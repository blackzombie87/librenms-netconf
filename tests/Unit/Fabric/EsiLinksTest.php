<?php

use SafferIt\LibrenmsNetconf\Fabric\EsiLinks;

/**
 * Core `links` rows from a leaf's ESI table (plan §3.8): one row per local ESI-LAG and
 * remote PE, the peer's AE taken from the same ESI on its side.
 */
function esiPeers(): array
{
    return [
        '192.0.2.12' => ['device_id' => 12, 'hostname' => 'leaf-b', 'hardware' => 'Juniper EX4650', 'version' => '23.4R2-S7.4', 'name_hint' => 'LEAF-B'],
        '192.0.2.61' => ['device_id' => null, 'hostname' => null, 'hardware' => null, 'version' => null, 'name_hint' => 'gw-1'],
        // 192.0.2.62 is unknown altogether
    ];
}

it('links a local ESI-LAG to the peer leaf and its AE', function () {
    $rows = EsiLinks::rows(
        [['esi' => '00:11:22:33:44:55:00:00:02:00', 'local_port_id' => 45, 'local_ifname' => 'ae2.0', 'remote_vtep_ips' => ['192.0.2.12']]],
        esiPeers(),
        [12 => ['00:11:22:33:44:55:00:00:02:00' => ['port_id' => 77, 'ifname' => 'ae2.0']]],
    );

    expect($rows)->toBe([[
        'local_port_id' => 45,
        'remote_device_id' => 12,
        'remote_hostname' => 'leaf-b',
        'remote_port_id' => 77,
        'remote_port' => 'ae2.0',
        'remote_platform' => 'Juniper EX4650',
        'remote_version' => '23.4R2-S7.4',
        'protocol' => 'evpn-esi',
        'active' => 1,
    ]]);
});

it('keeps the remote port empty when the peer does not carry the ESI or is not monitored', function () {
    $rows = EsiLinks::rows(
        [['esi' => '00:11:22:33:44:66:00:00:01:00', 'local_port_id' => 50, 'local_ifname' => 'ae1.0', 'remote_vtep_ips' => ['192.0.2.12', '192.0.2.61', '192.0.2.62', '192.0.2.62']]],
        esiPeers(),
        [12 => ['00:11:22:33:44:55:00:00:02:00' => ['port_id' => 77, 'ifname' => 'ae2.0']]],
    );

    expect(array_column($rows, 'remote_hostname'))->toBe(['leaf-b', 'gw-1', '192.0.2.62'])
        ->and(array_column($rows, 'remote_device_id'))->toBe([12, 0, 0])
        ->and(array_column($rows, 'remote_port'))->toBe(['', '', ''])
        ->and(array_column($rows, 'remote_port_id'))->toBe([null, null, null])
        ->and(array_column($rows, 'remote_version'))->toBe(['23.4R2-S7.4', '', ''])
        ->and(array_column($rows, 'remote_platform'))->toBe(['Juniper EX4650', null, null]);
});

it('skips ESIs without a local ESI-LAG port and rows without remote PEs', function () {
    $rows = EsiLinks::rows(
        [
            // seen from another leaf only: no local interface
            ['esi' => '01:00:10:00:11:00:00:00:02:00', 'local_port_id' => null, 'local_ifname' => null, 'remote_vtep_ips' => ['192.0.2.12']],
            // local AE that core has not discovered as a port yet
            ['esi' => '01:00:60:00:11:00:00:00:30:00', 'local_port_id' => null, 'local_ifname' => 'ae48.0', 'remote_vtep_ips' => ['192.0.2.12']],
            // peer lost the segment
            ['esi' => '01:00:60:00:11:00:00:00:31:00', 'local_port_id' => 60, 'local_ifname' => 'ae49.0', 'remote_vtep_ips' => []],
        ],
        esiPeers(),
        [],
    );

    expect($rows)->toBe([]);
});

it('identifies a row like core does: local port, remote hostname, remote port', function () {
    expect(EsiLinks::key(['local_port_id' => 45, 'remote_hostname' => 'leaf-b', 'remote_port' => 'ae2.0']))->toBe('45|leaf-b|ae2.0')
        ->and(EsiLinks::key(['local_port_id' => '45', 'remote_hostname' => 'leaf-b', 'remote_port' => 'ae2.0']))->toBe('45|leaf-b|ae2.0')
        ->and(EsiLinks::PROTOCOL)->toHaveLength(8);
});
