<?php

use SafferIt\LibrenmsNetconf\Fabric\View\DeviceStats;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricSummary;

/**
 * Fabric-level totals over several monitored members (F3 review Issue 1): the same VNI on two
 * leaves is one VNI, and two leaves with disjoint lists of the same length must not collapse.
 */
function deviceStats(array $vnis, array $esis, array $instances, array $extra = []): array
{
    return $extra + ['vnis' => $vnis, 'irbs' => 0, 'instances' => $instances, 'esis' => $esis, 'esis_local' => count($esis), 'esis_df' => 0, 'esis_degraded' => 0, 'tunnels' => 0, 'neighbors' => 0, 'local_macs' => null, 'remote_macs' => null, 'dup_macs' => 0, 'orphan_vnis' => 0, 'collector_failing' => false];
}

it('counts VNIs, ESIs and instances distinct over two members with disjoint and overlapping lists', function () {
    $totals = DeviceStats::totals([
        11 => deviceStats([10010, 10011, 10012], ['00:11:00:00:00:00:00:00:00:01', '00:11:00:00:00:00:00:00:00:02'], ['MACVRF-A'], ['tunnels' => 3, 'neighbors' => 2, 'local_macs' => 10, 'remote_macs' => 20, 'esis_degraded' => 1]),
        12 => deviceStats([20000, 20001, 10010], ['00:11:00:00:00:00:00:00:00:02', '00:11:00:00:00:00:00:00:00:03'], ['MACVRF-A', 'MACVRF-B'], ['tunnels' => 4, 'neighbors' => 2, 'local_macs' => 5, 'remote_macs' => 25, 'collector_failing' => true]),
    ]);

    expect($totals['vnis'])->toBe(5)            // not 3: array union on 0-based lists kept only the first list
        ->and($totals['esis'])->toBe(3)
        ->and($totals['instances'])->toBe(2)
        ->and($totals['tunnels'])->toBe(7)
        ->and($totals['neighbors'])->toBe(4)
        ->and($totals['local_macs'])->toBe(15)
        ->and($totals['remote_macs'])->toBe(45)
        ->and($totals['esis_degraded'])->toBe(1)
        ->and($totals['collector_failing'])->toBe(1);
});

it('is empty for no devices', function () {
    expect(DeviceStats::totals([]))->toBe(['vnis' => 0, 'esis' => 0, 'instances' => 0, 'tunnels' => 0, 'neighbors' => 0, 'local_macs' => 0, 'remote_macs' => 0, 'esis_degraded' => 0, 'dup_macs' => 0, 'orphan_vnis' => 0, 'collector_failing' => 0]);
});

it('classifies degraded ESI-LAGs', function () {
    $esi = fn (array $v) => (object) ($v + ['lag_status' => 'Up/Forwarding', 'status' => 'Resolved by IFL ae1.0', 'remote_vtep_ips' => '["192.0.2.12"]']);

    expect(DeviceStats::esiDegraded($esi([])))->toBeFalse()
        ->and(DeviceStats::esiDegraded($esi(['lag_status' => 'Down'])))->toBeTrue()
        ->and(DeviceStats::esiDegraded($esi(['status' => 'Unresolved'])))->toBeTrue()
        ->and(DeviceStats::esiDegraded($esi(['remote_vtep_ips' => '[]'])))->toBeTrue()
        ->and(DeviceStats::esiDegraded($esi(['remote_vtep_ips' => null])))->toBeTrue();
});

it('counts EVPN sessions down against every address of a member device', function () {
    // F1 leaf fixture shape: device 11 has VTEP 192.0.2.61 (member) and router-id 192.0.2.11 (alias)
    $session = fn (int $device, string $peer, bool $up = false) => ['device_id' => $device, 'peer_ip' => $peer, 'state' => $up ? 'Established' : 'Active', 'up' => $up];
    $sessions = [
        $session(12, '192.0.2.11'),          // peer is device 11's router-id alias -> counts
        $session(12, '192.0.2.61', true),    // established -> not down
        $session(11, '192.0.2.1'),           // peer is an unknown VTEP member (spine) -> counts
        $session(11, '198.51.100.9'),        // peer outside the fabric -> ignored
        $session(99, '192.0.2.11'),          // device not in this fabric -> ignored
        ['device_id' => 12, 'peer_ip' => '192.0.2.1', 'state' => null, 'up' => false],   // no state known -> ignored
    ];
    $devices = [11, 12];
    $nodes = ['192.0.2.61', '192.0.2.62', '192.0.2.1'];
    $ipDevice = ['192.0.2.61' => 11, '192.0.2.11' => 11, '192.0.2.62' => 12];

    expect(FabricSummary::sessionsDown($sessions, $devices, $nodes, $ipDevice))->toBe(2)
        ->and(FabricSummary::sessionsDown($sessions, $devices, $nodes, ['192.0.2.61' => 11, '192.0.2.62' => 12]))->toBe(1);   // without the alias the router-id peer is lost
});
