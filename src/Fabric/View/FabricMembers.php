<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Members tab (plan §7.4): one row per fabric member with the device (when monitored), role,
 * router-id, software version, instances, VNIs, MACs, ESIs, tunnels, last poll and the
 * collector status. Unknown VTEPs keep their BGP description as the name.
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

        $stats = [];
        foreach ($deviceIds as $id) {
            $stats[$id] = ['instances' => [], 'vnis' => 0, 'esis' => 0, 'esis_df' => 0, 'tunnels' => 0, 'neighbors' => 0, 'local_macs' => null, 'remote_macs' => null, 'irbs' => 0];
        }
        foreach (DB::table(TableSchema::tableName('vni'))->whereIn('device_id', $deviceIds ?: [0])->selectRaw('device_id, count(*) as n, sum(irb_ifname is not null) as irbs')->groupBy('device_id')->get() as $r) {
            $stats[(int) $r->device_id]['vnis'] = (int) $r->n;
            $stats[(int) $r->device_id]['irbs'] = (int) $r->irbs;
        }
        foreach (DB::table(TableSchema::tableName('vni'))->whereIn('device_id', $deviceIds ?: [0])->whereNotNull('instance')->distinct()->get(['device_id', 'instance']) as $r) {
            $stats[(int) $r->device_id]['instances'][] = (string) $r->instance;
        }
        foreach (DB::table(TableSchema::tableName('neighbor'))->whereIn('device_id', $deviceIds ?: [0])->distinct()->get(['device_id', 'instance']) as $r) {
            $stats[(int) $r->device_id]['instances'][] = (string) $r->instance;
        }
        foreach (DB::table(TableSchema::tableName('neighbor'))->whereIn('device_id', $deviceIds ?: [0])->selectRaw('device_id, count(distinct neighbor_ip) as n')->groupBy('device_id')->get() as $r) {
            $stats[(int) $r->device_id]['neighbors'] = (int) $r->n;
        }
        foreach (DB::table(TableSchema::tableName('esi'))->whereIn('device_id', $deviceIds ?: [0])->whereNotNull('local_ifname')->selectRaw('device_id, count(*) as n, sum(is_df = 1) as df')->groupBy('device_id')->get() as $r) {
            $stats[(int) $r->device_id]['esis'] = (int) $r->n;
            $stats[(int) $r->device_id]['esis_df'] = (int) $r->df;
        }
        foreach (DB::table(TableSchema::tableName('tunnel'))->whereIn('device_id', $deviceIds ?: [0])->selectRaw('device_id, count(*) as n')->groupBy('device_id')->get() as $r) {
            $stats[(int) $r->device_id]['tunnels'] = (int) $r->n;
        }
        foreach (DB::table('netconf_metrics')->whereIn('device_id', $deviceIds ?: [0])->where('mapping', 'instance')->where('definition', 'like', '%-evpn')->get(['device_id', 'values']) as $r) {
            $values = json_decode((string) $r->values, true);
            if (is_array($values)) {
                $stats[(int) $r->device_id]['local_macs'] = ($stats[(int) $r->device_id]['local_macs'] ?? 0) + (int) ($values['local_macs'] ?? 0);
                $stats[(int) $r->device_id]['remote_macs'] = ($stats[(int) $r->device_id]['remote_macs'] ?? 0) + (int) ($values['remote_macs'] ?? 0);
            }
        }

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
                'stats' => $deviceId === null ? null : ($stats[$deviceId] ?? null),
                'instances' => $deviceId === null ? [] : array_values(array_unique($stats[$deviceId]['instances'] ?? [])),
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
