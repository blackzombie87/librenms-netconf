<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * The "EVPN fabric" line of a device (plan §7.4 badge): its fabric, role, VTEP address and a
 * few counts — VNIs, ESI-LAGs with the DF count, EVPN neighbours, tunnels — or null when the
 * device is not a fabric member.
 */
final class DeviceBadge
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forDevice(int $deviceId): ?array
    {
        $vtep = DB::table(TableSchema::tableName('vtep') . ' as v')
            ->join(TableSchema::tableName('fabric_member') . ' as m', 'm.vtep_ip', '=', 'v.vtep_ip')
            ->join(TableSchema::tableName('fabric') . ' as f', 'f.id', '=', 'm.fabric_id')
            ->where('v.device_id', $deviceId)
            ->first(['v.vtep_ip', 'v.router_id', 'v.border', 'm.role', 'm.pinned', 'f.id as fabric_id', 'f.name as fabric_name']);
        if ($vtep === null) {
            return null;
        }

        $esi = DB::table(TableSchema::tableName('esi'))->where('device_id', $deviceId)->whereNotNull('local_ifname')
            ->selectRaw('count(*) as n, coalesce(sum(is_df = 1), 0) as df')->first();
        $vnis = DB::table(TableSchema::tableName('vni'))->where('device_id', $deviceId)->count();
        $irbs = DB::table(TableSchema::tableName('vni'))->where('device_id', $deviceId)->whereNotNull('irb_ifname')->count();
        $neighbors = DB::table(TableSchema::tableName('neighbor'))->where('device_id', $deviceId)->distinct()->count('neighbor_ip');
        $tunnels = DB::table(TableSchema::tableName('tunnel'))->where('device_id', $deviceId)->count();
        $members = DB::table(TableSchema::tableName('fabric_member'))->where('fabric_id', $vtep->fabric_id)->count();

        return [
            'fabric_id' => (int) $vtep->fabric_id,
            'fabric_name' => (string) $vtep->fabric_name,
            'members' => $members,
            'vtep_ip' => (string) $vtep->vtep_ip,
            'router_id' => $vtep->router_id,
            'role' => (string) $vtep->role,
            'border' => (bool) $vtep->border,
            'pinned' => (bool) $vtep->pinned,
            'esis' => (int) ($esi->n ?? 0),
            'esis_df' => (int) ($esi->df ?? 0),
            'vnis' => $vnis,
            'irbs' => $irbs,
            'neighbors' => $neighbors,
            'tunnels' => $tunnels,
        ];
    }
}
