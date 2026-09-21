<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * The "EVPN fabric" line of a device (plan §7.4 badge): its fabric, role, VTEP address and a
 * few counts from DeviceStats — VNIs, ESI-LAGs with the DF count, EVPN neighbours, tunnels —
 * or null when the device is not a fabric member.
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

        $stats = DeviceStats::forDevice($deviceId);
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
            'esis' => $stats['esis_local'],
            'esis_df' => $stats['esis_df'],
            'vnis' => count($stats['vnis']),
            'irbs' => $stats['irbs'],
            'neighbors' => $stats['neighbors'],
            'tunnels' => $stats['tunnels'],
        ];
    }
}
