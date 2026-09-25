<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\EsiLinks;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * The one assembler behind both fabric pictures (plan §11 E4). Every query the overview needs lives here, so the canonical neighbour addresses,
 * the `wan` rule and the "≥2 members peer with this far end" set cannot drift apart between
 * the vis map and the eagle view.
 *
 * `Topology::layout()` and `Topology::graph()` keep receiving exactly the arrays they receive
 * today, type-5 ESI pairs and missing port ids included: moving the queries is allowed,
 * changing the vis payload is not. The eagle path asks for `eagle: true`, which adds the port
 * ids and the `link_key` to every underlay row, filters the ESI pairs down to real LAGs, and
 * loads the per-member figures the cards and the classifier need.
 */
final class FabricTopologyInput
{
    /**
     * @return array{
     *   layout_nodes: list<array{ip: string, name: string, role: string, device_id: int|null, border: bool, site: string|null, status: bool|null}>,
     *   layout_underlay: list<array<string, mixed>>,
     *   layout_esi_pairs: list<array{a: string, b: string, esis: int}>,
     *   overlay: list<array{0: string, 1: string}>,
     *   nodes: list<array<string, mixed>>,
     *   underlay: list<array<string, mixed>>,
     *   esi_pairs: list<array{a: string, b: string, esis: int, degraded: int, id: string}>,
     *   esi_rows: list<array<string, mixed>>,
     *   shared_far_ends: list<string>,
     *   missing: list<array{device_id: int, peer_ip: string}>,
     *   members: list<array{ip: string, device_id: int|null, role: string, collected: bool, irbs: int, lag_esis: int}>
     * }
     */
    public static function load(int $fabricId, FabricNodes $nodes, bool $eagle = false): array
    {
        $deviceIds = $nodes->deviceIds();
        $locations = DB::table('locations')->pluck('location', 'id')->map(fn ($v) => (string) $v)->all();
        $devices = DB::table('devices')->whereIn('device_id', $deviceIds ?: [0])
            ->get(['device_id', 'status', 'location_id', 'disabled', 'version'])->keyBy('device_id');

        $layoutNodes = [];
        foreach ($nodes->all() as $ip => $n) {
            if (! $n['member']) {
                continue;
            }
            $device = $n['device_id'] === null ? null : $devices->get($n['device_id']);
            $layoutNodes[] = [
                'ip' => (string) $ip,
                'name' => $n['name'],
                'role' => $n['role'],
                'device_id' => $n['device_id'],
                'border' => $n['border'],
                'site' => $device === null || $device->location_id === null ? null : ($locations[(int) $device->location_id] ?? null),
                'status' => $device === null ? null : ($device->disabled ? null : (bool) $device->status),
            ];
        }

        [$layoutUnderlay, $eagleUnderlay] = self::underlay($fabricId, $nodes);
        $overlay = Topology::overlayPairs($nodes, DB::table(TableSchema::tableName('neighbor'))
            ->whereIn('device_id', $deviceIds ?: [0])->distinct()->get(['device_id', 'neighbor_ip'])
            ->map(fn ($r) => [(int) $r->device_id, (string) $r->neighbor_ip])->all());

        $out = [
            'layout_nodes' => $layoutNodes,
            'layout_underlay' => $layoutUnderlay,
            'layout_esi_pairs' => self::layoutEsiPairs($nodes),
            'overlay' => $overlay,
            'nodes' => [],
            'underlay' => $eagleUnderlay,
            'esi_pairs' => [],
            'esi_rows' => [],
            'shared_far_ends' => self::sharedFarEnds($layoutUnderlay),
            'missing' => [],
            'members' => [],
        ];
        if (! $eagle) {
            return $out;
        }

        $esiRows = EsiMatrix::forFabric($nodes);
        [$pairs, $degradedPerDevice] = self::lagPairs($esiRows, $nodes);
        $stats = DeviceStats::forDevices($deviceIds);
        $sessions = OverlaySessions::forDevices($deviceIds);

        $out['esi_rows'] = $esiRows;
        $out['esi_pairs'] = $pairs;
        $out['missing'] = array_map(
            fn ($m) => ['device_id' => (int) $m['device_id'], 'peer_ip' => (string) $m['peer_ip']],
            OverlaySessions::missing($sessions, $nodes->deviceNodes(), $nodes->collectedIds()),
        );

        $majority = self::majorityVersion($layoutNodes, $devices, $nodes);
        foreach ($layoutNodes as $n) {
            $deviceId = $n['device_id'];
            $device = $deviceId === null ? null : $devices->get($deviceId);
            $version = $device === null || $device->version === null || $device->version === '' ? null : (string) $device->version;
            $collected = $deviceId !== null && $nodes->isCollected($deviceId);
            $out['nodes'][] = $n + [
                'collected' => $collected,
                'polled' => $deviceId !== null && $nodes->isPolled($deviceId),
                'version' => $version,
                'version_skew' => $version !== null && $majority !== null && $version !== $majority,
                'irbs' => $deviceId === null ? 0 : (int) ($stats[$deviceId]['irbs'] ?? 0),
                'esi_degraded' => $deviceId === null ? 0 : (int) ($degradedPerDevice[$deviceId] ?? 0),
            ];
            $out['members'][] = [
                'ip' => $n['ip'],
                'device_id' => $deviceId,
                'role' => $n['role'],
                'collected' => $collected,
                'irbs' => $deviceId === null ? 0 : (int) ($stats[$deviceId]['irbs'] ?? 0),
                'lag_esis' => $deviceId === null ? 0 : (int) ($stats[$deviceId]['esis_lag'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The devices hanging off this fabric's ESI-LAGs, from core discovery (plan §11 E6). Two
     * queries on the ESI sides that were loaded already — `ports_stack` for the physical
     * members of each AE, and `links` on either — and nothing per port. It does not read
     * `netconf_evpn_esi` a second time and it does not run at all unless `attached=1`.
     *
     * @param  list<array<string, mixed>>  $esiRows  EsiMatrix rows
     * @return list<array<string, mixed>>
     */
    public static function attached(array $esiRows, FabricNodes $nodes): array
    {
        $lagSides = [];
        foreach ($esiRows as $row) {
            if (! EsiKind::isLag($row)) {
                continue;
            }
            /** @var array<int, array<string, mixed>> $sides */
            $sides = (array) $row['sides'];
            foreach ($sides as $side) {
                if ($side['port_id'] !== null && ! EsiKind::isGateway((string) $row['esi'], $side['ifname'] === null ? null : (string) $side['ifname'])) {
                    $lagSides[] = ['esi' => (string) $row['esi'], 'device_id' => (int) $side['device_id'], 'port_id' => (int) $side['port_id']];
                }
            }
        }
        if ($lagSides === []) {
            return [];
        }

        $aeIds = array_values(array_unique(array_column($lagSides, 'port_id')));
        $stack = DB::table('ports_stack')->whereIn('high_port_id', $aeIds)->whereNotNull('low_port_id')
            ->get(['high_port_id', 'low_port_id'])
            ->map(fn ($r) => ['high_port_id' => (int) $r->high_port_id, 'low_port_id' => (int) $r->low_port_id])->all();

        $portIds = array_values(array_unique(array_merge($aeIds, array_column($stack, 'low_port_id'))));
        $links = DB::table('links')->whereIn('local_port_id', $portIds)
            ->where(fn ($q) => $q->whereNull('protocol')->orWhere('protocol', '!=', EsiLinks::PROTOCOL))
            ->get(['local_port_id', 'protocol', 'remote_device_id', 'remote_hostname', 'remote_port'])
            ->map(fn ($r) => [
                'local_port_id' => (int) $r->local_port_id,
                'protocol' => $r->protocol === null ? null : (string) $r->protocol,
                'remote_device_id' => (int) $r->remote_device_id,
                'remote_hostname' => (string) $r->remote_hostname,
                'remote_port' => (string) $r->remote_port,
            ])->all();

        $remoteIds = array_values(array_filter(array_unique(array_column($links, 'remote_device_id'))));
        $names = [];
        foreach (Device::query()->whereIn('device_id', $remoteIds ?: [0])->get() as $device) {
            $names[(int) $device->device_id] = array_values(array_unique(array_filter([$device->displayName(), $device->hostname, $device->sysName])));
        }

        $records = EsiAttached::select($lagSides, $stack, $links, array_fill_keys($nodes->deviceIds(), true), $names);
        foreach ($records as &$record) {
            // the card it hangs under: the PE the first attachment was seen on
            $record['anchor'] = $nodes->addressOf((int) ($record['attachments'][0]['device_id'] ?? 0));
        }
        unset($record);

        return array_values(array_filter($records, fn ($r) => $r['anchor'] !== null));
    }

    /**
     * Underlay rows twice: the array `Topology::layout()` has always had, and the same rows
     * with `link_key` and the two port ids the eagle view's focus grammar and inspector need.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function underlay(int $fabricId, FabricNodes $nodes): array
    {
        $links = DB::table(TableSchema::tableName('underlay_link'))->where('fabric_id', $fabricId)->get();
        $portIds = array_filter(array_merge($links->pluck('a_port_id')->all(), $links->pluck('b_port_id')->all()));
        $portNames = $portIds === []
            ? []
            : DB::table('ports')->whereIn('port_id', $portIds)->pluck('ifName', 'port_id')->map(fn ($v) => (string) $v)->all();

        $layout = [];
        $eagle = [];
        foreach ($links as $l) {
            $a = $nodes->addressOf((int) $l->a_device_id);
            $b = $l->b_device_id !== null ? $nodes->addressOf((int) $l->b_device_id) : null;
            $b ??= $l->b_vtep_ip !== null && $nodes->has((string) $l->b_vtep_ip) ? (string) $l->b_vtep_ip : null;
            if ($a === null) {
                continue;
            }
            $row = [
                'a' => $a,
                'b' => $b,
                'b_label' => $b === null ? ($l->b_address ?? null) : null,
                'protocol' => (string) $l->protocol,
                'state' => $l->state,
                'up' => Topology::sessionUp((string) $l->protocol, $l->state),
                'lldp' => (bool) $l->lldp,
                'wan' => (bool) $l->wan,
                'a_port' => $l->a_port_id === null ? null : ($portNames[(int) $l->a_port_id] ?? null),
                'b_port' => $l->b_port_id === null ? null : ($portNames[(int) $l->b_port_id] ?? null),
                'network' => $l->network,
            ];
            $layout[] = $row;
            $eagle[] = $row + [
                'link_key' => (string) $l->link_key,
                'a_port_id' => $l->a_port_id === null ? null : (int) $l->a_port_id,
                'b_port_id' => $l->b_port_id === null ? null : (int) $l->b_port_id,
                'a_device_id' => (int) $l->a_device_id,
                'b_device_id' => $l->b_device_id === null ? null : (int) $l->b_device_id,
            ];
        }

        return [$layout, $eagle];
    }

    /**
     * Far ends several members peer with: a node that talks to the fabric like a spine and is
     * not monitored yet. One member alone is a session out of the fabric (plan §10.12), and
     * the two are told apart here once, for both pictures.
     *
     * @param  list<array<string, mixed>>  $underlay
     * @return list<string>
     */
    public static function sharedFarEnds(array $underlay): array
    {
        $byFarEnd = [];
        foreach ($underlay as $e) {
            if ($e['b'] === null && $e['b_label'] !== null) {
                $byFarEnd[(string) $e['b_label']][(string) $e['a']] = true;
            }
        }
        $shared = array_map(strval(...), array_keys(array_filter($byFarEnd, fn ($members) => count($members) >= 2)));
        usort($shared, FabricGraph::compare(...));

        return $shared;
    }

    /**
     * The ESI pairs the static picture has always drawn: every local ifname, type-5 included,
     * expanded to pairwise marks. Untouched on purpose — the vis payload must not change.
     *
     * @return list<array{a: string, b: string, esis: int}>
     */
    private static function layoutEsiPairs(FabricNodes $nodes): array
    {
        /** @var array<string, array<string, true>> $byEsi */
        $byEsi = [];
        foreach (DB::table(TableSchema::tableName('esi'))->whereIn('device_id', $nodes->deviceIds() ?: [0])->get(['device_id', 'esi', 'local_ifname', 'remote_vtep_ips']) as $r) {
            $esi = (string) $r->esi;
            $byEsi[$esi] ??= [];
            if ($r->local_ifname !== null) {
                $own = $nodes->addressOf((int) $r->device_id);
                if ($own !== null) {
                    $byEsi[$esi][$own] = true;
                }
            }
            foreach ((array) json_decode((string) ($r->remote_vtep_ips ?? '[]'), true) as $ip) {
                $byEsi[$esi][$nodes->canonical((string) $ip)] = true;
            }
        }

        return self::pairwise($byEsi, fn () => 0)[0];
    }

    /**
     * The ESI-LAG pairs of the eagle view, with a degraded count per pair and per device.
     * Gateway segments are gone before this runs, so 53 shared type-5 values cannot become
     * one bracket labelled "53 ESIs" between two routers that share no cable.
     *
     * @param  list<array<string, mixed>>  $esiRows  EsiMatrix rows
     * @return array{0: list<array{a: string, b: string, esis: int, degraded: int, id: string}>, 1: array<int, int>}
     */
    private static function lagPairs(array $esiRows, FabricNodes $nodes): array
    {
        /** @var array<string, array<string, true>> $byEsi */
        $byEsi = [];
        $degradedEsis = [];
        $perDevice = [];
        foreach ($esiRows as $row) {
            if (! EsiKind::isLag($row)) {
                continue;
            }
            $esi = (string) $row['esi'];
            $degraded = EsiKind::degraded((array) $row['flags']);
            $degradedEsis[$esi] = $degraded;
            $byEsi[$esi] ??= [];
            /** @var array<int, array<string, mixed>> $sides */
            $sides = (array) $row['sides'];
            foreach ($sides as $side) {
                $address = $side['vtep_ip'] ?? null;
                if ($address !== null) {
                    $byEsi[$esi][(string) $address] = true;
                }
                if ($degraded) {
                    $perDevice[(int) $side['device_id']] = ($perDevice[(int) $side['device_id']] ?? 0) + 1;
                }
            }
            foreach ((array) $row['remote_pes'] as $ip) {
                $byEsi[$esi][$nodes->canonical((string) $ip)] = true;
            }
        }

        return [self::pairwise($byEsi, fn (string $esi) => ($degradedEsis[$esi] ?? false) ? 1 : 0)[0], $perDevice];
    }

    /**
     * @param  array<string, array<string, true>>  $byEsi
     * @param  \Closure(string): int  $weight
     * @return array{0: list<array{a: string, b: string, esis: int, degraded: int, id: string}>}
     */
    private static function pairwise(array $byEsi, \Closure $weight): array
    {
        $pairs = [];
        foreach ($byEsi as $esi => $pes) {
            $ips = array_keys($pes);
            usort($ips, FabricGraph::compare(...));
            for ($i = 0; $i < count($ips); $i++) {
                for ($j = $i + 1; $j < count($ips); $j++) {
                    $key = $ips[$i] . '|' . $ips[$j];
                    $pairs[$key] ??= ['a' => $ips[$i], 'b' => $ips[$j], 'esis' => 0, 'degraded' => 0, 'id' => $key];
                    $pairs[$key]['esis']++;
                    $pairs[$key]['degraded'] += $weight((string) $esi);
                }
            }
        }

        return [array_values($pairs)];
    }

    /**
     * The version a chip is measured against: the one the collected members agree on. A tie
     * has no majority, so every version is skewed; one version everywhere means no chip.
     *
     * @param  list<array{ip: string, name: string, role: string, device_id: int|null, border: bool, site: string|null, status: bool|null}>  $layoutNodes
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $devices
     */
    private static function majorityVersion(array $layoutNodes, $devices, FabricNodes $nodes): ?string
    {
        $counts = [];
        foreach ($layoutNodes as $n) {
            $deviceId = $n['device_id'];
            if ($deviceId === null || ! $nodes->isCollected($deviceId)) {
                continue;
            }
            $device = $devices->get($deviceId);
            $version = $device === null ? null : $device->version;
            if ($version !== null && $version !== '') {
                $counts[(string) $version] = ($counts[(string) $version] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $top = array_slice($counts, 0, 2, true);
        $values = array_values($top);
        if (count($values) > 1 && $values[0] === $values[1]) {
            return null;
        }

        return (string) array_key_first($top);
    }
}
