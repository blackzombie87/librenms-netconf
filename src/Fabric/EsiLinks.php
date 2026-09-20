<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

/**
 * EVPN multihoming peers as core `links` rows (plan §3.8): for every ESI-LAG of a leaf, one
 * row per remote PE with protocol `evpn-esi`, so the peer leaf shows up in the Neighbours
 * tab and on the map next to the LLDP neighbours.
 *
 * Pure: the row shapes are computed from arrays so the logic is unit-testable; EsiLinkWriter
 * loads the inputs and writes the rows.
 */
final class EsiLinks
{
    /** Fits the 11-character `links.protocol` column. */
    public const PROTOCOL = 'evpn-esi';

    /**
     * Rows for one device.
     *
     * @param  list<array{esi: string, local_port_id: int|null, local_ifname: string|null, remote_vtep_ips: list<string>}>  $esis  the device's ESI rows
     * @param  array<string, array{device_id: int|null, hostname: string|null, hardware: string|null, version: string|null, name_hint: string|null}>  $peers  remote VTEP address => what is known about it
     * @param  array<int, array<string, array{port_id: int|null, ifname: string|null}>>  $remoteEsis  device_id => esi => the peer's local side of that ESI
     * @return list<array{local_port_id: int, remote_device_id: int, remote_hostname: string, remote_port_id: int|null, remote_port: string, remote_platform: string|null, remote_version: string, protocol: string, active: int}>
     */
    public static function rows(array $esis, array $peers, array $remoteEsis): array
    {
        $rows = [];
        foreach ($esis as $esi) {
            // only this leaf's own segments (a local ESI-LAG that core knows as a port) link anywhere
            if ($esi['local_port_id'] === null || $esi['local_ifname'] === null || $esi['local_ifname'] === '') {
                continue;
            }
            foreach (array_unique($esi['remote_vtep_ips']) as $ip) {
                $peer = $peers[$ip] ?? ['device_id' => null, 'hostname' => null, 'hardware' => null, 'version' => null, 'name_hint' => null];
                $deviceId = $peer['device_id'];
                $remote = $deviceId === null ? null : ($remoteEsis[$deviceId][$esi['esi']] ?? null);

                $row = [
                    'local_port_id' => $esi['local_port_id'],
                    'remote_device_id' => $deviceId ?? 0,
                    'remote_hostname' => mb_substr((string) ($peer['hostname'] ?? $peer['name_hint'] ?? $ip), 0, 128),
                    // the same ESI on the peer names its AE; empty when the peer is not monitored by the plugin
                    'remote_port_id' => $remote['port_id'] ?? null,
                    'remote_port' => mb_substr((string) ($remote['ifname'] ?? ''), 0, 128),
                    'remote_platform' => $peer['hardware'] !== null && $peer['hardware'] !== '' ? mb_substr($peer['hardware'], 0, 256) : null,
                    'remote_version' => mb_substr((string) ($peer['version'] ?? ''), 0, 256),
                    'protocol' => self::PROTOCOL,
                    'active' => 1,
                ];
                $rows[self::key($row)] = $row;
            }
        }

        return array_values($rows);
    }

    /**
     * Identity of a row across runs: core dedupes its own links the same way (local port,
     * remote hostname, remote port), see includes/discovery/discovery-protocols.inc.php.
     *
     * @param  array{local_port_id: int|string|null, remote_hostname: string, remote_port: string}  $row
     */
    public static function key(array $row): string
    {
        return $row['local_port_id'] . '|' . $row['remote_hostname'] . '|' . $row['remote_port'];
    }
}
