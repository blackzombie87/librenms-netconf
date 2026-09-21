<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Fabric list and overview figures (plan §7.4): member counts by role, totals over the
 * per-leaf tables of the monitored members (DeviceStats, distinct VNIs / ESIs / instances),
 * and the health badges that need no checks engine (EVPN sessions down, ESIs degraded,
 * duplicate MACs, orphan VNIs, unknown VTEPs, members whose collector is failing).
 * Everything is computed for all fabrics at once: the tables are small at the fabric level
 * (tens of nodes, hundreds of VNIs).
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
        $perDevice = DeviceStats::forDevices($deviceIds);
        $sessions = OverlaySessions::forDevices($deviceIds);
        // every address of a monitored device (member address and router-id aliases) -> device
        $ipDevice = DB::table(TableSchema::tableName('vtep'))->whereNotNull('device_id')->pluck('device_id', 'vtep_ip')->map(fn ($id) => (int) $id)->all();

        $summaries = [];
        foreach ($fabrics as $fabric) {
            $own = $members->get($fabric->id, collect());
            $nodes = $own->pluck('vtep_ip')->map(fn ($v) => (string) $v)->all();
            $devices = $own->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

            $totals = DeviceStats::totals(array_intersect_key($perDevice, array_flip($devices)));
            $health = [
                'sessions_down' => self::sessionsDown($sessions, $devices, $nodes, $ipDevice),
                'esis_degraded' => $totals['esis_degraded'],
                'dup_macs' => $totals['dup_macs'],
                'orphan_vnis' => $totals['orphan_vnis'],
                'unknown_vteps' => $own->whereNull('device_id')->count(),
                'collector_failing' => $totals['collector_failing'],
            ];

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
                'totals' => array_intersect_key($totals, array_flip(['vnis', 'esis', 'instances', 'tunnels', 'neighbors', 'local_macs', 'remote_macs'])),
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
     * EVPN sessions of the fabric's monitored members that are not established. A session
     * belongs to the fabric when its peer is a member address or any address of a member
     * device (overlay peers are often router-ids, which since F1a are aliases, not members).
     *
     * @param  list<array<string, mixed>>  $sessions  rows of OverlaySessions::forDevices()
     * @param  list<int>  $devices  monitored member devices of the fabric
     * @param  list<string>  $nodes  member addresses of the fabric (monitored or not)
     * @param  array<string, int>  $ipDevice  every address of a monitored device => device_id
     */
    public static function sessionsDown(array $sessions, array $devices, array $nodes, array $ipDevice): int
    {
        $down = 0;
        foreach ($sessions as $session) {
            if (! in_array($session['device_id'], $devices, true) || $session['state'] === null || $session['up']) {
                continue;
            }
            $peer = (string) $session['peer_ip'];
            $peerDevice = $ipDevice[$peer] ?? null;
            if (in_array($peer, $nodes, true) || ($peerDevice !== null && in_array($peerDevice, $devices, true))) {
                $down++;
            }
        }

        return $down;
    }
}
