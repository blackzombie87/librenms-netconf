<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Fabric list and overview figures (plan §7.4): member counts by role, totals over the
 * per-leaf tables of the monitored members, and the health badges that need no checks
 * engine (EVPN sessions down, ESIs degraded, duplicate MACs, orphan VNIs, unknown VTEPs,
 * members whose collector is failing). Everything is computed for all fabrics at once:
 * the tables are small at the fabric level (tens of nodes, hundreds of VNIs).
 */
final class FabricSummary
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(self::build());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function one(int $fabricId): ?array
    {
        return self::build($fabricId)[$fabricId] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>> fabric id => summary
     */
    private static function build(?int $only = null): array
    {
        $fabrics = DB::table(TableSchema::tableName('fabric'))->when($only !== null, fn ($q) => $q->where('id', $only))->get();
        if ($fabrics->isEmpty()) {
            return [];
        }

        $members = DB::table(TableSchema::tableName('fabric_member') . ' as m')
            ->join(TableSchema::tableName('vtep') . ' as v', 'v.vtep_ip', '=', 'm.vtep_ip')
            ->whereIn('m.fabric_id', $fabrics->pluck('id')->all())
            ->get(['m.fabric_id', 'm.vtep_ip', 'm.role', 'm.pinned', 'v.device_id', 'v.border', 'v.name_hint', 'v.last_seen'])
            ->groupBy('fabric_id');

        $deviceIds = $members->flatten(1)->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $perDevice = self::perDevice($deviceIds);
        $sessions = OverlaySessions::forDevices($deviceIds);

        $summaries = [];
        foreach ($fabrics as $fabric) {
            $own = $members->get($fabric->id, collect());
            $nodes = $own->pluck('vtep_ip')->map(fn ($v) => (string) $v)->all();
            $devices = $own->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

            $totals = ['vnis' => [], 'esis' => [], 'tunnels' => 0, 'neighbors' => 0, 'local_macs' => 0, 'remote_macs' => 0, 'instances' => []];
            $health = ['sessions_down' => 0, 'esis_degraded' => 0, 'dup_macs' => 0, 'orphan_vnis' => 0, 'unknown_vteps' => $own->whereNull('device_id')->count(), 'collector_failing' => 0];
            foreach ($devices as $deviceId) {
                $d = $perDevice[$deviceId] ?? null;
                if ($d === null) {
                    continue;
                }
                $totals['vnis'] += $d['vnis'];
                $totals['esis'] += $d['esis'];
                $totals['instances'] += $d['instances'];
                $totals['tunnels'] += $d['tunnels'];
                $totals['neighbors'] += $d['neighbors'];
                $totals['local_macs'] += $d['local_macs'];
                $totals['remote_macs'] += $d['remote_macs'];
                $health['esis_degraded'] += $d['esis_degraded'];
                $health['dup_macs'] += $d['dup_macs'];
                $health['orphan_vnis'] += $d['orphan_vnis'];
                $health['collector_failing'] += $d['collector_failing'] ? 1 : 0;
            }
            foreach ($sessions as $session) {
                if (in_array($session['device_id'], $devices, true) && in_array($session['peer_ip'], $nodes, true) && $session['state'] !== null && ! $session['up']) {
                    $health['sessions_down']++;
                }
            }

            $summaries[(int) $fabric->id] = [
                'id' => (int) $fabric->id,
                'name' => (string) $fabric->name,
                'key' => (string) $fabric->key,
                'auto' => (bool) $fabric->auto,
                'notes' => $fabric->notes,
                'updated_at' => $fabric->updated_at,
                'members' => $own->count(),
                'monitored' => count($devices),
                'roles' => [
                    FabricGraph::ROLE_LEAF => $own->where('role', FabricGraph::ROLE_LEAF)->count(),
                    FabricGraph::ROLE_SPINE => $own->where('role', FabricGraph::ROLE_SPINE)->count(),
                    FabricGraph::ROLE_GATEWAY => $own->where('role', FabricGraph::ROLE_GATEWAY)->count(),
                    FabricGraph::ROLE_UNKNOWN => $own->where('role', FabricGraph::ROLE_UNKNOWN)->count(),
                ],
                'border' => $own->where('border', 1)->count(),
                'pinned' => $own->where('pinned', 1)->count(),
                'totals' => [
                    'vnis' => count(array_unique($totals['vnis'])),
                    'esis' => count(array_unique($totals['esis'])),
                    'instances' => count(array_unique($totals['instances'])),
                    'tunnels' => $totals['tunnels'],
                    'neighbors' => $totals['neighbors'],
                    'local_macs' => $totals['local_macs'],
                    'remote_macs' => $totals['remote_macs'],
                ],
                'health' => $health,
                'issues' => array_sum($health),
                'last_seen' => $own->max('last_seen'),
                'device_ids' => $devices,
                'nodes' => $nodes,
            ];
        }

        return $summaries;
    }

    /**
     * Per-device figures from the per-leaf tables, the junos-evpn instance metric and the
     * collector status.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, array<string, mixed>>
     */
    private static function perDevice(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $out = [];
        foreach ($deviceIds as $id) {
            $out[$id] = ['vnis' => [], 'esis' => [], 'instances' => [], 'tunnels' => 0, 'neighbors' => 0, 'local_macs' => 0, 'remote_macs' => 0, 'esis_degraded' => 0, 'dup_macs' => 0, 'orphan_vnis' => 0, 'collector_failing' => false];
        }

        foreach (DB::table(TableSchema::tableName('vni'))->whereIn('device_id', $deviceIds)->get(['device_id', 'vni', 'instance']) as $row) {
            $out[(int) $row->device_id]['vnis'][] = (int) $row->vni;
            if ($row->instance !== null) {
                $out[(int) $row->device_id]['instances'][] = (string) $row->instance;
            }
        }
        foreach (DB::table(TableSchema::tableName('esi'))->whereIn('device_id', $deviceIds)->get(['device_id', 'esi', 'local_ifname', 'status', 'lag_status', 'remote_vtep_ips']) as $row) {
            $out[(int) $row->device_id]['esis'][] = (string) $row->esi;
            if ($row->local_ifname !== null && self::esiDegraded($row)) {
                $out[(int) $row->device_id]['esis_degraded']++;
            }
        }
        foreach (DB::table(TableSchema::tableName('tunnel'))->whereIn('device_id', $deviceIds)->selectRaw('device_id, count(*) as n')->groupBy('device_id')->get() as $row) {
            $out[(int) $row->device_id]['tunnels'] = (int) $row->n;
        }
        foreach (DB::table(TableSchema::tableName('neighbor'))->whereIn('device_id', $deviceIds)->selectRaw('device_id, count(distinct neighbor_ip) as n')->groupBy('device_id')->get() as $row) {
            $out[(int) $row->device_id]['neighbors'] = (int) $row->n;
        }

        // VNIs without a single flood-list entry on the same leaf
        $flooded = DB::table(TableSchema::tableName('vni_vtep'))->whereIn('device_id', $deviceIds)->distinct()->get(['device_id', 'vni']);
        $floodedKeys = [];
        foreach ($flooded as $row) {
            $floodedKeys[$row->device_id . '/' . $row->vni] = true;
        }
        if ($floodedKeys !== []) {
            // only meaningful for leaves whose remote table was collected at all
            $collected = array_unique(array_map(fn ($row) => (int) $row->device_id, $flooded->all()));
            foreach ($out as $deviceId => $d) {
                if (! in_array($deviceId, $collected, true)) {
                    continue;
                }
                foreach (array_unique($d['vnis']) as $vni) {
                    if (! isset($floodedKeys[$deviceId . '/' . $vni])) {
                        $out[$deviceId]['orphan_vnis']++;
                    }
                }
            }
        }

        foreach (DB::table('netconf_metrics')->whereIn('device_id', $deviceIds)->where('mapping', 'instance')->where('definition', 'like', '%-evpn')->get(['device_id', 'values']) as $row) {
            $values = json_decode((string) $row->values, true);
            if (is_array($values)) {
                $out[(int) $row->device_id]['local_macs'] += (int) ($values['local_macs'] ?? 0);
                $out[(int) $row->device_id]['remote_macs'] += (int) ($values['remote_macs'] ?? 0);
            }
        }
        foreach (DB::table('sensors')->whereIn('device_id', $deviceIds)->where('sensor_type', 'like', 'netconf-%-dup-mac-total')->get(['device_id', 'sensor_current']) as $row) {
            $out[(int) $row->device_id]['dup_macs'] += (int) $row->sensor_current;
        }
        foreach (DB::table('netconf_device_status')->whereIn('device_id', $deviceIds)->where('consecutive_failures', '>', 0)->pluck('device_id') as $id) {
            $out[(int) $id]['collector_failing'] = true;
        }

        return $out;
    }

    /** A local ESI-LAG whose LAG is not up, whose ESI is unresolved or that has no remote PE. */
    public static function esiDegraded(object $esi): bool
    {
        $lag = (string) ($esi->lag_status ?? '');
        if ($lag !== '' && ! str_starts_with($lag, 'Up')) {
            return true;
        }
        $status = (string) ($esi->status ?? '');
        if ($status !== '' && ! str_starts_with($status, 'Resolved')) {
            return true;
        }
        $remote = json_decode((string) ($esi->remote_vtep_ips ?? '[]'), true);

        return ! is_array($remote) || $remote === [];
    }
}
