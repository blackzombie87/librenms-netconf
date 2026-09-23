<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Support\IpSort;

/**
 * The "EVPN multihoming" table of one device (plan §3.8): its ESI-LAGs with mode, DF/BDF
 * election, LAG state and, per remote PE, the peer device and the peer's AE for the same ESI.
 */
final class EsiPeers
{
    /**
     * @return array{rows: list<array<string, mixed>>, own_vtep: string|null}
     */
    public static function forDevice(int $deviceId): array
    {
        $esiTable = TableSchema::tableName('esi');
        $esis = DB::table($esiTable)->where('device_id', $deviceId)->whereNotNull('local_ifname')->get();
        if ($esis->isEmpty()) {
            return ['rows' => [], 'own_vtep' => null];
        }

        $ownVtep = self::ownAddress($deviceId);

        // every remote VTEP address -> vtep row -> device
        $remoteIps = [];
        foreach ($esis as $esi) {
            foreach ((array) json_decode((string) ($esi->remote_vtep_ips ?? '[]'), true) as $ip) {
                $remoteIps[(string) $ip] = true;
            }
        }
        $vteps = DB::table(TableSchema::tableName('vtep'))->whereIn('vtep_ip', array_keys($remoteIps) ?: [''])->get(['vtep_ip', 'device_id', 'name_hint'])->keyBy('vtep_ip');
        $peerDeviceIds = $vteps->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $devices = Device::query()->whereIn('device_id', $peerDeviceIds ?: [0])->get()->keyBy('device_id');

        // the peers' side of the same ESIs
        $remoteEsis = [];
        foreach (DB::table($esiTable)->whereIn('device_id', $peerDeviceIds ?: [0])->whereIn('esi', $esis->pluck('esi')->all())->get(['device_id', 'esi', 'local_port_id', 'local_ifname', 'lag_status', 'is_df']) as $row) {
            $remoteEsis[(int) $row->device_id][(string) $row->esi] = $row;
        }

        $portIds = $esis->pluck('local_port_id')->filter()->all();
        foreach ($remoteEsis as $byEsi) {
            foreach ($byEsi as $row) {
                if ($row->local_port_id !== null) {
                    $portIds[] = $row->local_port_id;
                }
            }
        }
        $ports = Port::query()->whereIn('port_id', array_values(array_unique(array_map('intval', $portIds))) ?: [0])->get()->keyBy('port_id');

        $rows = [];
        foreach ($esis as $esi) {
            $peers = [];
            foreach (array_unique(array_map('strval', (array) json_decode((string) ($esi->remote_vtep_ips ?? '[]'), true))) as $ip) {
                $vtep = $vteps->get($ip);
                $peerDeviceId = $vtep?->device_id === null ? null : (int) $vtep->device_id;
                $remote = $peerDeviceId === null ? null : ($remoteEsis[$peerDeviceId][(string) $esi->esi] ?? null);
                $peers[] = [
                    'vtep_ip' => $ip,
                    'device' => $peerDeviceId === null ? null : $devices->get($peerDeviceId),
                    'name' => $vtep?->name_hint ?: $ip,
                    'port' => $remote?->local_port_id === null ? null : $ports->get((int) $remote->local_port_id),
                    'ifname' => $remote?->local_ifname,
                    'lag_status' => $remote?->lag_status,
                    'df' => $esi->df_ip !== null && $esi->df_ip === $ip,
                    'bdf' => $esi->bdf_ip !== null && $esi->bdf_ip === $ip,
                ];
            }

            $rows[] = [
                'esi' => (string) $esi->esi,
                'instance' => $esi->instance,
                'local_ifname' => (string) $esi->local_ifname,
                'local_port' => $esi->local_port_id === null ? null : $ports->get((int) $esi->local_port_id),
                'mode' => $esi->mode,
                'status' => $esi->status,
                'lag_status' => $esi->lag_status,
                'is_df' => (bool) $esi->is_df,
                'is_bdf' => $ownVtep !== null && $esi->bdf_ip === $ownVtep,
                'df_ip' => $esi->df_ip,
                'bdf_ip' => $esi->bdf_ip,
                'aliasing' => $esi->aliasing === null ? null : (bool) $esi->aliasing,
                'remote_mac_count' => $esi->remote_mac_count === null ? null : (int) $esi->remote_mac_count,
                'peers' => $peers,
                'last_seen' => $esi->last_seen,
            ];
        }
        usort($rows, fn ($a, $b) => strnatcmp($a['local_ifname'], $b['local_ifname']));

        return ['rows' => $rows, 'own_vtep' => $ownVtep];
    }

    /**
     * The address this device is known by: the one the resolver made its fabric member, else
     * the lowest of its VTEP source addresses or router-ids. A leaf can have several, and the
     * BDF flag compares against this one, so it has to be the address the member row names
     * and not whatever row the database returned first (F6 5).
     */
    private static function ownAddress(int $deviceId): ?string
    {
        $member = DB::table(TableSchema::tableName('vtep') . ' as v')
            ->join(TableSchema::tableName('fabric_member') . ' as m', 'm.vtep_ip', '=', 'v.vtep_ip')
            ->where('v.device_id', $deviceId)->pluck('v.vtep_ip')->map('strval')->all();
        if ($member !== []) {
            return IpSort::lowest(array_values($member));
        }

        $own = DB::table(TableSchema::tableName('vni'))->where('device_id', $deviceId)->whereNotNull('source_vtep')
            ->distinct()->pluck('source_vtep')->map('strval')->all();
        if ($own === []) {
            $own = DB::table(TableSchema::tableName('neighbor'))->where('device_id', $deviceId)->whereNotNull('router_id')
                ->distinct()->pluck('router_id')->map('strval')->all();
        }

        return IpSort::lowest(array_values($own));
    }
}
