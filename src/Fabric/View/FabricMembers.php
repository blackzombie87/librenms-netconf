<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Members tab (plan §7.4): one row per fabric member with the device (when monitored), role,
 * router-id, software version, instances, VNIs, MACs, ESIs, tunnels (DeviceStats), last poll
 * and the collector status. Unknown VTEPs keep their BGP description as the name.
 */
final class FabricMembers
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forFabric(int $fabricId): array
    {
        $members = DB::table(TableSchema::tableName('fabric_member') . ' as m')
            ->join(TableSchema::tableName('vtep') . ' as v', 'v.vtep_ip', '=', 'm.vtep_ip')
            ->where('m.fabric_id', $fabricId)
            ->get(['m.vtep_ip', 'm.role', 'm.pinned', 'm.since', 'v.device_id', 'v.router_id', 'v.border', 'v.name_hint', 'v.first_seen', 'v.last_seen']);
        if ($members->isEmpty()) {
            return [];
        }

        $deviceIds = $members->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        /** @var \Illuminate\Support\Collection<int, Device> $devices */
        $devices = Device::query()->whereIn('device_id', $deviceIds ?: [0])->get()->keyBy('device_id');
        $locations = DB::table('locations')->pluck('location', 'id')->map(fn ($v) => (string) $v)->all();
        $aliases = DB::table(TableSchema::tableName('vtep'))->whereIn('device_id', $deviceIds ?: [0])->get(['vtep_ip', 'device_id'])->groupBy('device_id');
        $status = DB::table('netconf_device_status')->whereIn('device_id', $deviceIds ?: [0])->get()->keyBy('device_id');

        $stats = DeviceStats::forDevices($deviceIds);

        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        foreach ($members as $m) {
            $deviceId = $m->device_id === null ? null : (int) $m->device_id;
            $device = $deviceId === null ? null : $devices->get($deviceId);
            $st = $deviceId === null ? null : $status->get($deviceId);
            $rows[] = [
                'vtep_ip' => (string) $m->vtep_ip,
                'role' => (string) $m->role,
                'border' => (bool) $m->border,
                'pinned' => (bool) $m->pinned,
                'device_id' => $deviceId,
                'device' => $device,
                'name' => $device?->displayName() ?? ($m->name_hint ?: (string) $m->vtep_ip),
                'name_hint' => $m->name_hint,
                'router_id' => $m->router_id,
                'aliases' => $deviceId === null ? [] : array_values(array_diff($aliases->get($deviceId, collect())->pluck('vtep_ip')->map(fn ($v) => (string) $v)->all(), [(string) $m->vtep_ip])),
                'version' => $device?->version,
                'hardware' => $device?->hardware,
                'location' => $device === null || $device->location_id === null ? null : ($locations[(int) $device->location_id] ?? null),
                'stats' => $deviceId === null || ! isset($stats[$deviceId]) ? null : [
                    'vnis' => count($stats[$deviceId]['vnis']),
                    'irbs' => $stats[$deviceId]['irbs'],
                    'esis' => $stats[$deviceId]['esis_local'],
                    'esis_df' => $stats[$deviceId]['esis_df'],
                    'tunnels' => $stats[$deviceId]['tunnels'],
                    'neighbors' => $stats[$deviceId]['neighbors'],
                    'local_macs' => $stats[$deviceId]['local_macs'],
                    'remote_macs' => $stats[$deviceId]['remote_macs'],
                ],
                'instances' => $deviceId === null ? [] : ($stats[$deviceId]['instances'] ?? []),
                'last_ok' => $st?->last_ok,
                'failures' => $st === null ? 0 : (int) $st->consecutive_failures,
                'last_error' => $st?->last_error,
                'first_seen' => $m->first_seen,
                'last_seen' => $m->last_seen,
                'since' => $m->since,
            ];
        }
        usort($rows, fn ($a, $b) => self::roleRank($a['role']) <=> self::roleRank($b['role']) ?: FabricGraph::compare($a['vtep_ip'], $b['vtep_ip']));

        return $rows;
    }

    public static function roleRank(string $role): int
    {
        return match ($role) {
            FabricGraph::ROLE_GATEWAY => 0,
            FabricGraph::ROLE_SPINE => 1,
            FabricGraph::ROLE_LEAF => 2,
            default => 3,
        };
    }
}
