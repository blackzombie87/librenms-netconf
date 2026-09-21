<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * Keeps the `evpn-esi` rows of the core `links` table in step with the ESI table: one row
 * per local ESI-LAG and remote PE, the peer resolved through the vtep table and its AE
 * through the same ESI on the peer's side (plan §3.8).
 *
 * Existing rows are updated in place so their ids stay stable; rows that no ESI produces
 * any more are deleted. Core's discovery-protocols deletes every `links` row of a device
 * that its own LLDP/CDP run did not produce — the plugin's discovery module runs after it
 * and recreates the rows in the same run (documented caveat, only the id changes).
 */
class EsiLinkWriter
{
    /**
     * Sync the links of the given devices (the fabric participants); devices with no ESI
     * rows lose their evpn-esi links. Returns the number of rows present afterwards.
     *
     * @param  list<int>  $deviceIds
     */
    public function sync(array $deviceIds): int
    {
        $esiTable = TableSchema::tableName('esi');
        $esis = DB::table($esiTable)->whereIn('device_id', $deviceIds ?: [0])->whereNotNull('local_ifname')
            ->get(['device_id', 'esi', 'local_port_id', 'local_ifname', 'remote_vtep_ips']);

        /** @var array<int, list<array{esi: string, local_port_id: int|null, local_ifname: string|null, remote_vtep_ips: list<string>}>> $byDevice */
        $byDevice = [];
        $remoteIps = [];
        foreach ($esis as $row) {
            $ips = array_values(array_filter(array_map('strval', (array) json_decode((string) ($row->remote_vtep_ips ?? '[]'), true)), fn ($ip) => $ip !== ''));
            $byDevice[(int) $row->device_id][] = [
                'esi' => (string) $row->esi,
                'local_port_id' => $row->local_port_id === null ? null : (int) $row->local_port_id,
                'local_ifname' => $row->local_ifname === null ? null : (string) $row->local_ifname,
                'remote_vtep_ips' => $ips,
            ];
            foreach ($ips as $ip) {
                $remoteIps[$ip] = true;
            }
        }

        $peers = $this->peers(array_keys($remoteIps));
        $remoteDeviceIds = array_values(array_unique(array_filter(array_column($peers, 'device_id'))));
        $remoteEsis = [];
        foreach (DB::table($esiTable)->whereIn('device_id', $remoteDeviceIds ?: [0])->whereNotNull('local_ifname')->get(['device_id', 'esi', 'local_port_id', 'local_ifname']) as $row) {
            $remoteEsis[(int) $row->device_id][(string) $row->esi] = [
                'port_id' => $row->local_port_id === null ? null : (int) $row->local_port_id,
                'ifname' => (string) $row->local_ifname,
            ];
        }

        $total = 0;
        foreach ($deviceIds as $deviceId) {
            $total += $this->syncDevice($deviceId, EsiLinks::rows($byDevice[$deviceId] ?? [], $peers, $remoteEsis));
        }
        // devices that left the fabric tables altogether
        DB::table('links')->where('protocol', EsiLinks::PROTOCOL)->whereNotIn('local_device_id', $deviceIds ?: [0])->delete();

        return $total;
    }

    /** Delete every evpn-esi link (setting switched off, uninstall). */
    public function deleteAll(): int
    {
        return DB::table('links')->where('protocol', EsiLinks::PROTOCOL)->delete();
    }

    /** Delete the links a device owns (module cleanup); rows pointing at it are fixed by the next sync. */
    public function forget(int $deviceId): int
    {
        return DB::table('links')->where('protocol', EsiLinks::PROTOCOL)->where('local_device_id', $deviceId)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncDevice(int $deviceId, array $rows): int
    {
        $existing = [];
        foreach (DB::table('links')->where('protocol', EsiLinks::PROTOCOL)->where('local_device_id', $deviceId)->get() as $link) {
            $existing[EsiLinks::key(['local_port_id' => $link->local_port_id, 'remote_hostname' => (string) $link->remote_hostname, 'remote_port' => (string) $link->remote_port])][] = $link;
        }

        $inserts = [];
        foreach ($rows as $row) {
            $key = EsiLinks::key($row);
            $current = ($existing[$key] ?? []) === [] ? null : array_shift($existing[$key]);
            if ($current === null) {
                $inserts[] = $row + ['local_device_id' => $deviceId];
                continue;
            }
            $changes = [];
            foreach (['remote_device_id', 'remote_port_id', 'remote_platform', 'remote_version', 'active'] as $column) {
                if ($current->$column != $row[$column]) {
                    $changes[$column] = $row[$column];
                }
            }
            if ($changes !== []) {
                DB::table('links')->where('id', $current->id)->update($changes);
            }
        }

        // duplicates of a kept key and keys no ESI produces any more
        $stale = [];
        foreach ($existing as $links) {
            foreach ($links as $link) {
                $stale[] = (int) $link->id;
            }
        }
        if ($stale !== []) {
            DB::table('links')->whereIn('id', $stale)->delete();
        }
        foreach (array_chunk($inserts, 200) as $chunk) {
            DB::table('links')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * What is known about each remote VTEP address: the device it resolved to (vtep table,
     * filled by the resolver) and the BGP description as the fallback name.
     *
     * @param  list<string>  $ips
     * @return array<string, array{device_id: int|null, hostname: string|null, hardware: string|null, version: string|null, name_hint: string|null}>
     */
    private function peers(array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        $peers = [];
        foreach (DB::table(TableSchema::tableName('vtep'))->whereIn('vtep_ip', $ips)->get(['vtep_ip', 'device_id', 'name_hint']) as $vtep) {
            $peers[(string) $vtep->vtep_ip] = [
                'device_id' => $vtep->device_id === null ? null : (int) $vtep->device_id,
                'hostname' => null,
                'hardware' => null,
                'version' => null,
                'name_hint' => $vtep->name_hint === null || $vtep->name_hint === '' ? null : (string) $vtep->name_hint,
            ];
        }

        $deviceIds = array_values(array_unique(array_filter(array_column($peers, 'device_id'))));
        if ($deviceIds !== []) {
            $devices = [];
            foreach (DB::table('devices')->whereIn('device_id', $deviceIds)->get(['device_id', 'hostname', 'hardware', 'version']) as $device) {
                $devices[(int) $device->device_id] = $device;
            }
            foreach ($peers as $ip => $peer) {
                $device = $peer['device_id'] === null ? null : ($devices[$peer['device_id']] ?? null);
                if ($device !== null) {
                    $peers[$ip]['hostname'] = (string) $device->hostname;
                    $peers[$ip]['hardware'] = $device->hardware === null ? null : (string) $device->hardware;
                    $peers[$ip]['version'] = $device->version === null ? null : (string) $device->version;
                }
            }
        }

        return $peers;
    }
}
