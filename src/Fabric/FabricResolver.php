<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * Cross-device aggregation for the EVPN fabric view (plan §7.3 / §7.6): builds the graph of
 * EVPN speakers from the per-leaf tables and core BGP data, resolves every VTEP address to
 * a LibreNMS device, derives roles, adds the underlay links, and stores the components as
 * fabrics with members. Runs after the writers of every leaf poll, under a cache lock.
 */
class FabricResolver
{
    public const LOCK = 'netconf:fabric-resolver';

    public const LOCK_SECONDS = 120;

    public function __construct(private readonly UnderlayResolver $underlay = new UnderlayResolver)
    {
    }

    public static function make(): self
    {
        return new self;
    }

    /**
     * Resolve under the lock; null when another poller holds it (its run covers ours).
     *
     * @return array<string, int>|null
     */
    public function run(): ?array
    {
        try {
            $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        } catch (\Throwable) {
            return $this->resolve();   // cache store without lock support
        }

        $result = $lock->get(fn () => $this->resolve());

        return $result === false ? null : $result;
    }

    /**
     * @return array<string, int> nodes, devices, unknown, fabrics, links
     */
    public function resolve(): array
    {
        $now = now()->toDateTimeString();
        $graph = new FabricGraph;
        /** @var array<int, list<string>> $deviceNodes device_id => its addresses, own VTEP first */
        $deviceNodes = [];
        /** @var array<int, string|null> $routerIds */
        $routerIds = [];
        /** @var array<string, string> $nameHints peer address => BGP description */
        $nameHints = [];

        // 1. participants: devices with rows in the per-leaf tables
        $participants = [];
        foreach (['vni', 'tunnel', 'neighbor', 'esi'] as $table) {
            foreach (DB::table(TableSchema::tableName($table))->distinct()->pluck('device_id') as $id) {
                $participants[(int) $id] = true;
            }
        }
        foreach (array_keys($participants) as $deviceId) {
            $own = DB::table(TableSchema::tableName('vni'))->where('device_id', $deviceId)->whereNotNull('source_vtep')->distinct()->pluck('source_vtep')->map(fn ($v) => (string) $v)->all();
            $rids = DB::table(TableSchema::tableName('neighbor'))->where('device_id', $deviceId)->whereNotNull('router_id')->distinct()->pluck('router_id')->map(fn ($v) => (string) $v)->all();
            $routerIds[$deviceId] = $rids[0] ?? null;
            $ips = array_values(array_unique(array_merge($own, $rids)));
            if ($ips === []) {
                $ips = $this->loopbacks($deviceId);
            }
            if ($ips === []) {
                Log::debug("netconf fabric: device $deviceId has EVPN rows but no VTEP address / router-id, skipped");
                continue;
            }
            $this->attach($graph, $deviceNodes, $deviceId, $ips);
            foreach ($own as $ip) {
                $graph->markVtep($ip);
            }
            if ($own === [] && DB::table(TableSchema::tableName('tunnel'))->where('device_id', $deviceId)->exists()) {
                $graph->markVtep($ips[0]);
            }
            if (DB::table(TableSchema::tableName('vni'))->where('device_id', $deviceId)->whereNotNull('irb_ifname')->exists()) {
                $graph->markGateway($ips[0]);   // spread over the device's other addresses in step 7
            }
        }

        // 2. per-leaf edges: EVPN neighbours, ESI remote PEs, tunnels and flood lists
        foreach (DB::table(TableSchema::tableName('neighbor'))->get(['device_id', 'neighbor_ip']) as $row) {
            $this->edge($graph, $deviceNodes, (int) $row->device_id, (string) $row->neighbor_ip);
        }
        foreach (DB::table(TableSchema::tableName('esi'))->whereNotNull('remote_vtep_ips')->get(['device_id', 'remote_vtep_ips']) as $row) {
            foreach ((array) json_decode((string) $row->remote_vtep_ips, true) as $ip) {
                $this->edge($graph, $deviceNodes, (int) $row->device_id, (string) $ip);
            }
        }
        foreach (['tunnel', 'vni_vtep'] as $table) {
            foreach (DB::table(TableSchema::tableName($table))->distinct()->get(['device_id', 'remote_vtep_ip']) as $row) {
                $this->edge($graph, $deviceNodes, (int) $row->device_id, (string) $row->remote_vtep_ip);
            }
        }

        // 3. EVPN BGP sessions: core bgpPeers_cbgp (safi evpn) and the plugin's per-RIB metrics
        foreach ($this->evpnSessions() as $session) {
            $deviceId = $session['device_id'];
            if (! isset($deviceNodes[$deviceId])) {
                if ($session['local'] === null) {
                    continue;
                }
                $this->attach($graph, $deviceNodes, $deviceId, [$session['local']]);
            }
            $local = $deviceNodes[$deviceId][0];
            $graph->union($local, $session['peer']);
            $graph->markSession($local);
            $graph->markSession($session['peer']);
            if ($session['description'] !== null && $session['description'] !== '') {
                $nameHints[$session['peer']] ??= $session['description'];
            }
        }

        // 4. border flag from the junos-evpn L3 context sensor
        $border = DB::table('sensors')->where('sensor_type', 'like', 'netconf-%-l3-contexts')->where('sensor_current', '>', 0)->pluck('device_id');
        foreach ($border as $deviceId) {
            if (isset($deviceNodes[(int) $deviceId])) {
                $graph->markBorder($deviceNodes[(int) $deviceId][0]);
            }
        }

        // 5. every node address -> device (ipv4_addresses, then devices.hostname / ip)
        $ipDevice = [];
        foreach ($deviceNodes as $deviceId => $ips) {
            foreach ($ips as $ip) {
                $ipDevice[$ip] = $deviceId;
            }
        }
        foreach ($this->resolveDevices(array_diff($graph->nodes(), array_keys($ipDevice))) as $ip => $deviceId) {
            $ipDevice[$ip] = $deviceId;
            $this->attach($graph, $deviceNodes, $deviceId, [$ip]);
        }

        // 6. underlay: confirmed links join the graph when both ends are nodes
        $edges = $this->underlay->resolve(array_keys($deviceNodes));
        foreach ($edges as $edge) {
            $a = $deviceNodes[$edge->aDeviceId][0] ?? null;
            if ($a === null || $edge->protocol === 'lldp-only') {
                continue;
            }
            if ($edge->bDeviceId !== null && isset($deviceNodes[$edge->bDeviceId])) {
                $graph->union($a, $deviceNodes[$edge->bDeviceId][0]);
                $edge->wan = false;
            } elseif ($edge->bVtepIp !== null && $graph->has($edge->bVtepIp)) {
                $graph->union($a, $edge->bVtepIp);
                $edge->wan = false;
            }
        }

        // 7. one node per device: its addresses share the evidence, the first one is the member
        foreach ($deviceNodes as $ips) {
            $graph->mergeEvidence($ips);
        }

        // 8. persist
        $components = $graph->components();
        $fabricIds = $this->storeFabrics($components, $now);
        $unknown = $this->storeVteps($graph, $components, $fabricIds, $ipDevice, $deviceNodes, $routerIds, $nameHints, $now);
        $this->storeUnderlay($edges, $graph, $deviceNodes, $fabricIds, $now);
        $this->resolvePorts(array_keys($participants), $ipDevice);
        $this->cleanup($graph);

        return [
            'nodes' => count($graph->nodes()),
            'devices' => count($deviceNodes),
            'unknown' => $unknown,
            'fabrics' => count($components),
            'links' => count($edges),
        ];
    }

    /**
     * A device leaves LibreNMS (module cleanup): its addresses become unknown VTEPs, its
     * underlay edges go, and the graph is recomputed so its membership follows the evidence
     * the remaining leaves still have. Returns the number of rows changed.
     */
    public function forget(int $deviceId): int
    {
        $changed = DB::table(TableSchema::tableName('vtep'))->where('device_id', $deviceId)->update(['device_id' => null, 'router_id' => null]);
        $changed += DB::table(TableSchema::tableName('underlay_link'))->where('a_device_id', $deviceId)->orWhere('b_device_id', $deviceId)->delete();
        $changed += DB::table(TableSchema::tableName('mac'))->where('source_device_id', $deviceId)->update(['source_device_id' => null]);

        return $changed;
    }

    /** Drop what the graph no longer contains: unpinned VTEP rows and automatic fabrics without members. */
    private function cleanup(FabricGraph $graph): void
    {
        $members = TableSchema::tableName('fabric_member');
        $pinned = DB::table($members)->where('pinned', 1)->pluck('vtep_ip')->map(fn ($v) => (string) $v)->all();
        $keep = array_values(array_unique(array_merge($graph->nodes(), $pinned)));
        DB::table(TableSchema::tableName('vtep'))->whereNotIn('vtep_ip', $keep ?: [''])->delete();

        $fabrics = TableSchema::tableName('fabric');
        DB::table($fabrics)->where('auto', 1)->whereNotExists(function ($q) use ($members, $fabrics) {
            $q->select(DB::raw(1))->from($members)->whereColumn("$members.fabric_id", "$fabrics.id");
        })->delete();
    }

    /**
     * @param  array<int, list<string>>  $deviceNodes
     * @param  list<string>  $ips
     */
    private function attach(FabricGraph $graph, array &$deviceNodes, int $deviceId, array $ips): void
    {
        $known = $deviceNodes[$deviceId] ?? [];
        foreach ($ips as $ip) {
            if (! in_array($ip, $known, true)) {
                $known[] = $ip;
            }
        }
        $deviceNodes[$deviceId] = $known;
        foreach ($known as $ip) {
            $graph->union($known[0], $ip);
        }
    }

    /**
     * @param  array<int, list<string>>  $deviceNodes
     */
    private function edge(FabricGraph $graph, array $deviceNodes, int $deviceId, string $remote): void
    {
        $local = $deviceNodes[$deviceId][0] ?? null;
        if ($local === null || $remote === '') {
            return;
        }
        $graph->union($local, $remote);
        $graph->markVtep($remote);
    }

    /**
     * @return list<string>
     */
    private function loopbacks(int $deviceId): array
    {
        return DB::table('ipv4_addresses')->join('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
            ->where('ports.device_id', $deviceId)->where('ipv4_prefixlen', 32)->where('ports.ifName', 'like', 'lo%')
            ->where('ipv4_address', 'not like', '127.%')
            ->orderBy('ports.ifName')->pluck('ipv4_address')->map(fn ($v) => (string) $v)->all();
    }

    /**
     * EVPN sessions of monitored devices: bgpPeers_cbgp with safi evpn, plus the routing.yaml
     * per-RIB metric rows (index "peer/bgp.evpn.0") for devices whose SNMP lacks the table.
     *
     * @return list<array{device_id: int, peer: string, local: string|null, description: string|null}>
     */
    private function evpnSessions(): array
    {
        $sessions = [];
        $descriptions = [];
        foreach (DB::table('bgpPeers')->get(['device_id', 'bgpPeerIdentifier', 'bgpLocalAddr', 'bgpPeerDescr']) as $peer) {
            $descriptions[(int) $peer->device_id][(string) $peer->bgpPeerIdentifier] = [
                'local' => $peer->bgpLocalAddr !== null && $peer->bgpLocalAddr !== '' && $peer->bgpLocalAddr !== '0.0.0.0' ? (string) $peer->bgpLocalAddr : null,
                'description' => $peer->bgpPeerDescr !== null && $peer->bgpPeerDescr !== '' ? (string) $peer->bgpPeerDescr : null,
            ];
        }

        $rows = DB::table('bgpPeers_cbgp')->where('safi', 'evpn')->get(['device_id', 'bgpPeerIdentifier']);
        foreach ($rows as $row) {
            $info = $descriptions[(int) $row->device_id][(string) $row->bgpPeerIdentifier] ?? ['local' => null, 'description' => null];
            $sessions[$row->device_id . '/' . $row->bgpPeerIdentifier] = ['device_id' => (int) $row->device_id, 'peer' => (string) $row->bgpPeerIdentifier] + $info;
        }

        $metrics = DB::table('netconf_metrics')->where('mapping', 'bgp-peer-rib')->where('metric_index', 'like', '%/bgp.evpn.0')->get(['device_id', 'metric_index']);
        foreach ($metrics as $metric) {
            $peer = explode('/', (string) $metric->metric_index, 2)[0];
            if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $info = $descriptions[(int) $metric->device_id][$peer] ?? ['local' => null, 'description' => null];
            $sessions[$metric->device_id . '/' . $peer] ??= ['device_id' => (int) $metric->device_id, 'peer' => $peer] + $info;
        }
        // BGP descriptions from the routing.yaml peer metric where the core row has none
        $labels = DB::table('netconf_metrics')->where('mapping', 'bgp-peer')->get(['device_id', 'metric_index', 'labels']);
        foreach ($labels as $label) {
            $decoded = json_decode((string) $label->labels, true);
            $key = $label->device_id . '/' . $label->metric_index;
            if (isset($sessions[$key]) && $sessions[$key]['description'] === null && is_array($decoded) && ! empty($decoded['description'])) {
                $sessions[$key]['description'] = (string) $decoded['description'];
            }
        }

        return array_values($sessions);
    }

    /**
     * @param  list<string>  $ips
     * @return array<string, int> ip => device_id
     */
    private function resolveDevices(array $ips): array
    {
        $ips = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        if ($ips === []) {
            return [];
        }

        $resolved = [];
        $rows = DB::table('ipv4_addresses')->join('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
            ->whereIn('ipv4_address', $ips)->where('ports.deleted', 0)
            ->orderBy('ports.device_id')->get(['ipv4_address', 'ports.device_id']);
        foreach ($rows as $row) {
            $resolved[(string) $row->ipv4_address] ??= (int) $row->device_id;
        }

        $missing = array_values(array_diff($ips, array_keys($resolved)));
        if ($missing !== []) {
            $binary = array_values(array_filter(array_map(fn ($ip) => @inet_pton($ip) ?: null, $missing)));
            $devices = DB::table('devices')->where(function ($q) use ($missing, $binary) {
                $q->whereIn('hostname', $missing)->orWhereIn('overwrite_ip', $missing);
                if ($binary !== []) {
                    $q->orWhereIn('ip', $binary);
                }
            })->get(['device_id', 'hostname', 'overwrite_ip', 'ip']);
            foreach ($devices as $device) {
                $ip = is_string($device->ip) && $device->ip !== '' ? (@inet_ntop($device->ip) ?: null) : null;
                foreach ([$device->hostname, $device->overwrite_ip, $ip] as $candidate) {
                    if (is_string($candidate) && in_array($candidate, $missing, true)) {
                        $resolved[$candidate] ??= (int) $device->device_id;
                    }
                }
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, list<string>>  $components
     * @return array<string, int> component key => fabric id
     */
    private function storeFabrics(array $components, string $now): array
    {
        $table = TableSchema::tableName('fabric');
        $members = TableSchema::tableName('fabric_member');
        $fabricIds = [];

        foreach ($components as $key => $ips) {
            $fabric = DB::table($table)->where('key', $key)->first(['id']);
            if ($fabric === null) {
                // the component's lowest address changed: keep the fabric its members belong to
                $candidate = DB::table($members)->whereIn('vtep_ip', $ips)->where('pinned', 0)
                    ->join($table, "$table.id", '=', "$members.fabric_id")
                    ->orderBy("$table.id")->first(["$table.id as id"]);
                if ($candidate !== null && ! DB::table($members)->where('fabric_id', $candidate->id)->whereNotIn('vtep_ip', $ips)->where('pinned', 0)->exists()) {
                    DB::table($table)->where('id', $candidate->id)->update(['key' => $key, 'updated_at' => $now]);
                    $fabric = $candidate;
                }
            }
            if ($fabric === null) {
                $id = DB::table($table)->insertGetId(['name' => "Fabric $key", 'key' => $key, 'auto' => 1, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $id = (int) $fabric->id;
            }
            $fabricIds[$key] = $id;
        }

        return $fabricIds;
    }

    /**
     * Upsert vtep rows (one per address, aliases of a device carry its device_id too) and
     * fabric members (one per device, its first address; one per address for unknown
     * VTEPs); returns the number of nodes without a device.
     *
     * @param  array<string, list<string>>  $components
     * @param  array<string, int>  $fabricIds
     * @param  array<string, int>  $ipDevice
     * @param  array<int, list<string>>  $deviceNodes
     * @param  array<int, string|null>  $routerIds
     * @param  array<string, string>  $nameHints
     */
    private function storeVteps(FabricGraph $graph, array $components, array $fabricIds, array $ipDevice, array $deviceNodes, array $routerIds, array $nameHints, string $now): int
    {
        $vteps = [];
        $members = [];
        $unknown = 0;
        $memberTable = TableSchema::tableName('fabric_member');
        $pinned = DB::table($memberTable)->where('pinned', 1)->pluck('vtep_ip')->map(fn ($v) => (string) $v)->all();

        foreach ($components as $key => $ips) {
            foreach ($ips as $ip) {
                $deviceId = $ipDevice[$ip] ?? null;
                if ($deviceId === null) {
                    $unknown++;
                }
                $vteps[] = [
                    'vtep_ip' => $ip,
                    'device_id' => $deviceId,
                    'router_id' => $deviceId !== null ? ($routerIds[$deviceId] ?? null) : null,
                    'role' => $graph->role($ip),
                    'border' => $graph->isBorder($ip) ? 1 : 0,
                    'name_hint' => isset($nameHints[$ip]) ? mb_substr($nameHints[$ip], 0, 255) : null,
                    'first_seen' => $now,
                    'last_seen' => $now,
                ];
                $alias = $deviceId !== null && ($deviceNodes[$deviceId][0] ?? $ip) !== $ip;
                if (! $alias && ! in_array($ip, $pinned, true)) {
                    $members[] = ['fabric_id' => $fabricIds[$key], 'vtep_ip' => $ip, 'role' => $graph->role($ip), 'pinned' => 0, 'since' => $now];
                }
            }
        }

        foreach (array_chunk($vteps, 200) as $chunk) {
            DB::table(TableSchema::tableName('vtep'))->upsert($chunk, ['vtep_ip'], ['device_id', 'router_id', 'role', 'border', 'name_hint', 'last_seen']);
        }
        foreach (array_chunk($members, 200) as $chunk) {
            DB::table($memberTable)->upsert($chunk, ['vtep_ip'], ['fabric_id', 'role']);
        }
        DB::table($memberTable)->where('pinned', 0)->whereNotIn('vtep_ip', array_column($members, 'vtep_ip') ?: [''])->delete();

        return $unknown;
    }

    /**
     * @param  list<UnderlayEdge>  $edges
     * @param  array<int, list<string>>  $deviceNodes
     * @param  array<string, int>  $fabricIds
     */
    private function storeUnderlay(array $edges, FabricGraph $graph, array $deviceNodes, array $fabricIds, string $now): void
    {
        $table = TableSchema::tableName('underlay_link');
        $rows = [];
        foreach ($edges as $edge) {
            $node = $deviceNodes[$edge->aDeviceId][0] ?? null;
            $key = $node === null ? null : $graph->key($node);
            $rows[] = $edge->toRow() + ['fabric_id' => $key === null ? null : ($fabricIds[$key] ?? null), 'first_seen' => $now, 'last_seen' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->upsert($chunk, ['link_key'], ['fabric_id', 'a_address', 'b_device_id', 'b_port_id', 'b_address', 'b_vtep_ip', 'network', 'protocol', 'state', 'lldp', 'wan', 'last_seen']);
        }
        DB::table($table)->where('last_seen', '<', $now)->delete();
    }

    /**
     * Fill the port links of the per-leaf rows: tunnel.port_id from the vtep.N snmp-index,
     * esi.local_port_id from the ESI-LAG name, mac.source_device_id from the remote VTEP.
     *
     * @param  list<int>  $deviceIds
     * @param  array<string, int>  $ipDevice
     */
    private function resolvePorts(array $deviceIds, array $ipDevice): void
    {
        foreach ($deviceIds as $deviceId) {
            $ports = DB::table('ports')->where('device_id', $deviceId)->where('deleted', 0)->get(['port_id', 'ifIndex', 'ifName']);
            $byIndex = [];
            $byName = [];
            foreach ($ports as $port) {
                $byIndex[(int) $port->ifIndex] = (int) $port->port_id;
                $byName[(string) $port->ifName] = (int) $port->port_id;
            }

            $tunnels = DB::table(TableSchema::tableName('tunnel'))->where('device_id', $deviceId)->whereNotNull('snmp_index')->get(['id', 'snmp_index', 'port_id']);
            foreach ($tunnels as $tunnel) {
                $portId = $byIndex[(int) $tunnel->snmp_index] ?? null;
                if ($portId !== null && (int) $tunnel->port_id !== $portId) {
                    DB::table(TableSchema::tableName('tunnel'))->where('id', $tunnel->id)->update(['port_id' => $portId]);
                }
            }

            $esis = DB::table(TableSchema::tableName('esi'))->where('device_id', $deviceId)->whereNotNull('local_ifname')->get(['id', 'local_ifname', 'local_port_id']);
            foreach ($esis as $esi) {
                $name = (string) $esi->local_ifname;
                $portId = $byName[$name] ?? $byName[preg_replace('/\.0$/', '', $name) ?? $name] ?? null;
                if ($portId !== null && (int) $esi->local_port_id !== $portId) {
                    DB::table(TableSchema::tableName('esi'))->where('id', $esi->id)->update(['local_port_id' => $portId]);
                }
            }
        }

        $sources = DB::table(TableSchema::tableName('mac'))->where('source_type', 'remote')->distinct()->pluck('source');
        foreach ($sources as $source) {
            $deviceId = $ipDevice[(string) $source] ?? null;
            DB::table(TableSchema::tableName('mac'))->where('source_type', 'remote')->where('source', $source)
                ->where(fn ($q) => $deviceId === null ? $q->whereNotNull('source_device_id') : $q->where('source_device_id', '!=', $deviceId)->orWhereNull('source_device_id'))
                ->update(['source_device_id' => $deviceId]);
        }
    }
}
