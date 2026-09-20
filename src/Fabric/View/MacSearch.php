<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * MAC search (plan §7.4 "MACs"): a MAC, an IP or a VNI looked up in the plugin's EVPN MAC
 * database (every opted-in leaf's view: local port, ESI with its peers, or remote VTEP
 * resolved to the device) and cross-checked with core ports_fdb (bridge tables) and ipv4_mac
 * (ARP). Scoped to a fabric's devices or global.
 */
final class MacSearch
{
    public const LIMIT = 200;

    /**
     * @param  list<int>|null  $deviceIds  restrict to these devices (a fabric), null = all
     * @return array<string, mixed>
     */
    public static function run(string $q, ?array $deviceIds = null, int $limit = self::LIMIT): array
    {
        $query = self::classify($q);
        $result = $query + ['rows' => [], 'fdb' => [], 'arp' => [], 'truncated' => false, 'opted_in' => self::optedIn($deviceIds)];
        if ($query['kind'] === null) {
            return $result;
        }

        $macTable = TableSchema::tableName('mac');
        $plugin = DB::table($macTable)->when($deviceIds !== null, fn ($b) => $b->whereIn('device_id', $deviceIds ?: [0]));
        $fdb = DB::table('ports_fdb')->when($deviceIds !== null, fn ($b) => $b->whereIn('ports_fdb.device_id', $deviceIds ?: [0]));
        $arp = DB::table('ipv4_mac')->when($deviceIds !== null, fn ($b) => $b->whereIn('ipv4_mac.device_id', $deviceIds ?: [0]));

        switch ($query['kind']) {
            case 'mac':
                $plugin->where('mac_address', $query['value']);
                $fdb->where('ports_fdb.mac_address', $query['value']);
                $arp->where('ipv4_mac.mac_address', $query['value']);
                break;
            case 'mac-prefix':
                $plugin->where('mac_address', 'like', $query['value'] . '%');
                $fdb->where('ports_fdb.mac_address', 'like', $query['value'] . '%');
                $arp->where('ipv4_mac.mac_address', 'like', $query['value'] . '%');
                break;
            case 'ip':
                $plugin->where('ip_addresses', 'like', '%"' . $query['value'] . '"%');
                $arp->where('ipv4_mac.ipv4_address', $query['value']);
                $fdb = null;
                break;
            case 'vni':
                $plugin->where('vni', (int) $query['value']);
                $fdb = null;
                $arp = null;
                break;
            default:
                $plugin->where('ip_addresses', 'like', '%' . $query['value'] . '%');
                $fdb = null;
                $arp = null;
        }

        $rows = $plugin->orderBy('mac_address')->orderBy('vni')->orderBy('device_id')->limit($limit + 1)->get()->map(fn ($r) => (array) $r)->all();
        if (count($rows) > $limit) {
            $result['truncated'] = true;
            $rows = array_slice($rows, 0, $limit);
        }
        $result['rows'] = self::resolve($rows);

        if ($fdb !== null) {
            $result['fdb'] = $fdb->join('ports', 'ports.port_id', '=', 'ports_fdb.port_id')
                ->leftJoin('vlans', 'vlans.vlan_id', '=', 'ports_fdb.vlan_id')
                ->orderBy('ports_fdb.mac_address')->limit($limit)
                ->get(['ports_fdb.device_id', 'ports_fdb.mac_address', 'ports_fdb.port_id', 'ports.ifName', 'vlans.vlan_vlan', 'vlans.vlan_name', 'ports_fdb.updated_at'])
                ->map(fn ($r) => (array) $r)->all();
        }
        if ($arp !== null) {
            $result['arp'] = $arp->leftJoin('ports', 'ports.port_id', '=', 'ipv4_mac.port_id')
                ->orderBy('ipv4_mac.mac_address')->limit($limit)
                ->get(['ipv4_mac.device_id', 'ipv4_mac.mac_address', 'ipv4_mac.ipv4_address', 'ipv4_mac.port_id', 'ports.ifName', 'ipv4_mac.context_name'])
                ->map(fn ($r) => (array) $r)->all();
        }

        // devices for the core rows
        $ids = array_unique(array_merge(array_column($result['fdb'], 'device_id'), array_column($result['arp'], 'device_id')));
        /** @var \Illuminate\Support\Collection<int, Device> $devices */
        $devices = Device::query()->whereIn('device_id', array_map('intval', $ids) ?: [0])->get()->keyBy('device_id');
        foreach (['fdb', 'arp'] as $key) {
            foreach ($result[$key] as &$r) {
                $r['device'] = $devices->get((int) $r['device_id']);
                $r['mac'] = self::readable((string) $r['mac_address']);
            }
            unset($r);
        }

        return $result;
    }

    /**
     * What the user typed: a full MAC (12 hex), a MAC prefix (4+ hex), an IP, a VNI or text.
     *
     * @return array{kind: string|null, value: string, q: string}
     */
    public static function classify(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['kind' => null, 'value' => '', 'q' => $q];
        }
        if (filter_var($q, FILTER_VALIDATE_IP) !== false) {
            return ['kind' => 'ip', 'value' => $q, 'q' => $q];
        }
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $q) ?? '');
        $looksLikeMac = preg_match('/^[0-9a-fA-F]{1,4}([:.-][0-9a-fA-F]{1,4}){1,5}$/', $q) === 1 || preg_match('/^[0-9a-fA-F]{12}$/', $q) === 1;
        if ($looksLikeMac && strlen($hex) === 12) {
            return ['kind' => 'mac', 'value' => $hex, 'q' => $q];
        }
        if ($looksLikeMac && strlen($hex) >= 4 && strlen($hex) < 12 && strlen($hex) % 2 === 0) {
            return ['kind' => 'mac-prefix', 'value' => $hex, 'q' => $q];
        }
        if (ctype_digit($q)) {
            return ['kind' => 'vni', 'value' => $q, 'q' => $q];
        }

        return ['kind' => 'text', 'value' => $q, 'q' => $q];
    }

    public static function readable(string $hex): string
    {
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : $hex;
    }

    /**
     * Turn the raw MAC rows into display rows: the leaf, the source resolved to a port
     * (local IFL), an ESI with its PEs, or the remote VTEP's device.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function resolve(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $deviceIds = array_values(array_unique(array_map(fn ($r) => (int) $r['device_id'], $rows)));
        $sourceDeviceIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['source_device_id'] === null ? null : (int) $r['source_device_id'], $rows))));
        /** @var \Illuminate\Support\Collection<int, Device> $devices */
        $devices = Device::query()->whereIn('device_id', array_merge($deviceIds, $sourceDeviceIds) ?: [0])->get()->keyBy('device_id');

        $esis = [];
        $esiValues = array_values(array_unique(array_map(fn ($r) => (string) $r['source'], array_filter($rows, fn ($r) => $r['source_type'] === 'esi'))));
        if ($esiValues !== []) {
            foreach (DB::table(TableSchema::tableName('esi'))->whereIn('esi', $esiValues)->get(['device_id', 'esi', 'local_ifname', 'local_port_id', 'remote_vtep_ips']) as $e) {
                $esis[(string) $e->esi][] = $e;
            }
        }
        $vteps = DB::table(TableSchema::tableName('vtep'))->pluck('device_id', 'vtep_ip')->all();
        $names = DB::table(TableSchema::tableName('vtep'))->pluck('name_hint', 'vtep_ip')->all();

        // local IFLs -> ports by name (ge-0/0/1.0 or ge-0/0/1)
        $portNames = [];
        foreach ($rows as $r) {
            if ($r['source_type'] === 'local' && ! empty($r['source'])) {
                $portNames[(int) $r['device_id']][] = (string) $r['source'];
                $portNames[(int) $r['device_id']][] = preg_replace('/\.\d+$/', '', (string) $r['source']) ?? (string) $r['source'];
            }
        }
        $portIds = [];
        foreach ($esis as $list) {
            foreach ($list as $e) {
                if ($e->local_port_id !== null) {
                    $portIds[] = (int) $e->local_port_id;
                }
            }
        }
        /** @var \Illuminate\Support\Collection<int, Port> $ports */
        $ports = Port::query()->where(function ($b) use ($portNames, $portIds) {
            $b->whereIn('port_id', $portIds ?: [0]);
            foreach ($portNames as $deviceId => $names) {
                $b->orWhere(fn ($w) => $w->where('device_id', $deviceId)->whereIn('ifName', array_unique($names)));
            }
        })->where('deleted', 0)->get();
        $portsById = $ports->keyBy('port_id');
        $portsByName = [];
        foreach ($ports as $port) {
            $portsByName[(int) $port->device_id][(string) $port->ifName] = $port;
        }

        $out = [];
        foreach ($rows as $r) {
            $deviceId = (int) $r['device_id'];
            $source = (string) ($r['source'] ?? '');
            $resolved = ['type' => (string) ($r['source_type'] ?? ''), 'text' => $source, 'port' => null, 'device' => null, 'peers' => []];
            switch ($r['source_type']) {
                case 'local':
                    $resolved['port'] = $portsByName[$deviceId][$source] ?? $portsByName[$deviceId][preg_replace('/\.\d+$/', '', $source) ?? $source] ?? null;
                    $resolved['device'] = $devices->get($deviceId);
                    break;
                case 'remote':
                    $target = $r['source_device_id'] !== null ? (int) $r['source_device_id'] : ($vteps[$source] ?? null);
                    $resolved['device'] = $target === null ? null : $devices->get((int) $target);
                    $resolved['text'] = $resolved['device']?->displayName() ?? ($names[$source] ?? $source);
                    break;
                case 'esi':
                    foreach ($esis[$source] ?? [] as $e) {
                        if ($e->local_ifname === null) {
                            continue;
                        }
                        $resolved['peers'][(int) $e->device_id] = [
                            'device' => $devices->get((int) $e->device_id) ?? Device::query()->find((int) $e->device_id),
                            'ifname' => (string) $e->local_ifname,
                            'port' => $e->local_port_id === null ? null : $portsById->get((int) $e->local_port_id),
                        ];
                    }
                    // remote PEs of the segment that are not monitored sides
                    foreach ($esis[$source] ?? [] as $e) {
                        foreach ((array) json_decode((string) ($e->remote_vtep_ips ?? '[]'), true) as $ip) {
                            $target = $vteps[(string) $ip] ?? null;
                            if ($target !== null && isset($resolved['peers'][(int) $target])) {
                                continue;
                            }
                            $resolved['peers']['ip:' . $ip] ??= ['device' => $target === null ? null : $devices->get((int) $target), 'ifname' => null, 'port' => null, 'name' => $names[(string) $ip] ?? (string) $ip];
                        }
                    }
                    break;
            }
            $out[] = [
                'device' => $devices->get($deviceId),
                'device_id' => $deviceId,
                'vni' => (int) $r['vni'],
                'instance' => $r['instance'],
                'mac' => self::readable((string) $r['mac_address']),
                'mac_hex' => (string) $r['mac_address'],
                'ips' => array_values(array_filter((array) json_decode((string) ($r['ip_addresses'] ?? '[]'), true))),
                'source' => $resolved,
                'active_since' => $r['active_since'],
                'moves' => (int) ($r['moves'] ?? 0),
                'is_duplicate' => (bool) ($r['is_duplicate'] ?? false),
                'first_seen' => $r['first_seen'],
                'last_seen' => $r['last_seen'],
            ];
        }

        return $out;
    }

    /**
     * Devices whose MAC database is collected (attribute netconf_evpn_mac), in scope.
     *
     * @param  list<int>|null  $deviceIds
     * @return array{devices: int, rows: int}
     */
    public static function optedIn(?array $deviceIds): array
    {
        $attribs = DB::table('devices_attribs')->where('attrib_type', 'netconf_evpn_mac')->where('attrib_value', '1')
            ->when($deviceIds !== null, fn ($b) => $b->whereIn('device_id', $deviceIds ?: [0]))->count();
        $rows = DB::table(TableSchema::tableName('mac'))->when($deviceIds !== null, fn ($b) => $b->whereIn('device_id', $deviceIds ?: [0]))->count();

        return ['devices' => $attribs, 'rows' => $rows];
    }
}
