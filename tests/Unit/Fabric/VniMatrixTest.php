<?php

use SafferIt\LibrenmsNetconf\Fabric\View\VniMatrix;

/**
 * VNI aggregation over the monitored leaves (plan §7.4 VNIs tab, §7.5 checks 3, 4, 10).
 */
function vniRow(int $device, int $vni, array $extra = []): array
{
    return $extra + ['device_id' => $device, 'vni' => $vni, 'instance' => 'default-switch', 'vlan_id' => $vni, 'vlan_name' => "VX$vni", 'source_vtep' => "192.0.2.1$device", 'multicast_group' => '0.0.0.0', 'irb_ifname' => null, 'irb_status' => null, 'remote_macs' => 5];
}

it('merges carriers, sums MACs and reports a clean VNI without flags', function () {
    $nodes = [1 => ['192.0.2.11'], 2 => ['192.0.2.12']];
    $rows = VniMatrix::build(
        [vniRow(1, 10), vniRow(2, 10, ['remote_macs' => 7])],
        [
            ['device_id' => 1, 'vni' => 10, 'remote_vtep_ip' => '192.0.2.12'], ['device_id' => 1, 'vni' => 10, 'remote_vtep_ip' => '192.0.2.99'],
            ['device_id' => 2, 'vni' => 10, 'remote_vtep_ip' => '192.0.2.11'],
        ],
        $nodes,
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['vni'])->toBe(10)
        ->and(array_keys($rows[0]['carriers']))->toBe([1, 2])
        ->and($rows[0]['remote_macs'])->toBe(12)
        ->and($rows[0]['flood_peers'])->toBe(['192.0.2.12', '192.0.2.99', '192.0.2.11'])
        ->and($rows[0]['vlan_mismatch'])->toBeFalse()
        ->and($rows[0]['flags'])->toBe([]);
});

it('flags VLAN mismatch, flood-list gaps, stale entries and orphans', function () {
    $nodes = [1 => ['192.0.2.11'], 2 => ['192.0.2.12', '10.0.0.2'], 3 => ['192.0.2.13']];
    $rows = VniMatrix::build(
        [
            vniRow(1, 20, ['vlan_id' => 20]), vniRow(2, 20, ['vlan_id' => 220]), vniRow(3, 20),
            vniRow(1, 30), vniRow(3, 30),
            vniRow(3, 40),
        ],
        [
            // 20: leaf 3 heard leaf 1, so leaf 1 advertises it; leaf 2's list lacks leaf 1 -> gap on 2
            ['device_id' => 1, 'vni' => 20, 'remote_vtep_ip' => '10.0.0.2'], ['device_id' => 1, 'vni' => 20, 'remote_vtep_ip' => '192.0.2.13'],
            ['device_id' => 2, 'vni' => 20, 'remote_vtep_ip' => '192.0.2.13'], ['device_id' => 2, 'vni' => 20, 'remote_vtep_ip' => '192.0.2.99'],
            ['device_id' => 3, 'vni' => 20, 'remote_vtep_ip' => '192.0.2.11'], ['device_id' => 3, 'vni' => 20, 'remote_vtep_ip' => '10.0.0.2'],
            // 30: leaf 1 floods to leaf 2, which does not carry the VNI -> stale; leaf 3
            // advertises it (leaf 1 heard it) but its own flood list is empty -> orphan
            ['device_id' => 1, 'vni' => 30, 'remote_vtep_ip' => '192.0.2.12'], ['device_id' => 1, 'vni' => 30, 'remote_vtep_ip' => '192.0.2.13'],
            ['device_id' => 3, 'vni' => 99, 'remote_vtep_ip' => '192.0.2.11'],
        ],
        $nodes,
    );
    $byVni = array_column($rows, null, 'vni');

    expect($byVni[20]['vlan_mismatch'])->toBeTrue()
        ->and($byVni[20]['advertised'])->toBe([1, 2, 3])
        ->and($byVni[20]['gaps'])->toBe([['device_id' => 2, 'missing' => 1]])
        ->and($byVni[20]['flags'])->toBe(['vlan-mismatch', 'flood-gap'])
        // leaf 2 is in leaf 1's list for 30 although it does not carry it (stale), and that
        // still counts as evidence that it advertises the VNI — which is the contradiction
        ->and($byVni[30]['advertised'])->toBe([2, 3])
        ->and($byVni[30]['gaps'])->toBe([])
        ->and($byVni[30]['stale'])->toBe([['device_id' => 1, 'vtep_ip' => '192.0.2.12', 'target' => 2]])
        ->and($byVni[30]['orphan'])->toBe([3])
        ->and($byVni[30]['flags'])->toBe(['stale-flood', 'orphan'])
        // leaf 3 collected a remote table but has nothing for 40, and nobody advertises it
        ->and($byVni[40]['flags'])->toBe([]);
});

/**
 * Plan §10.3: `… end-point source` lists the VNIs a leaf *instantiates*, a flood list holds the
 * VTEPs whose type-3 (IMET) route it *received*. A VLAN template instantiates every VNI on every
 * leaf; a leaf announces one only where the bridge domain has an up interface. Demanding that
 * every carrier appear in every other carrier's flood list produced 21,395 criticals on a healthy
 * production fabric, so a gap now needs evidence that the missing leaf advertises the VNI at all.
 */
it('is silent about a VNI that every leaf instantiates and nobody advertises', function () {
    $leaves = [1, 2, 3, 4];
    $nodes = [];
    $vniRows = [];
    $floodRows = [];
    foreach ($leaves as $d) {
        $nodes[$d] = ['192.0.2.1' . $d];
    }
    foreach ($leaves as $d) {
        foreach ([10, 11] as $vni) {
            $vniRows[] = vniRow($d, $vni);
        }
        foreach ($leaves as $peer) {
            if ($peer !== $d) {
                $floodRows[] = ['device_id' => $d, 'vni' => 10, 'remote_vtep_ip' => '192.0.2.1' . $peer];   // 10 is live everywhere
            }
        }
    }

    $rows = array_column(VniMatrix::build($vniRows, $floodRows, $nodes), null, 'vni');

    expect($rows[10]['flags'])->toBe([])
        ->and($rows[11]['advertised'])->toBe([])
        ->and($rows[11]['gaps'])->toBe([])          // every leaf carries it, none announces it
        ->and($rows[11]['orphan'])->toBe([])
        ->and($rows[11]['flags'])->toBe([]);
});

it('still catches a leaf that drops out of one peer\'s flood list', function () {
    $leaves = [1, 2, 3, 4];
    $nodes = [];
    $vniRows = [];
    $floodRows = [];
    foreach ($leaves as $d) {
        $nodes[$d] = ['192.0.2.1' . $d];
        $vniRows[] = vniRow($d, 10);
        foreach ($leaves as $peer) {
            if ($peer !== $d && ! ($d === 2 && $peer === 3)) {   // leaf 2 lost leaf 3's route
                $floodRows[] = ['device_id' => $d, 'vni' => 10, 'remote_vtep_ip' => '192.0.2.1' . $peer];
            }
        }
    }

    $rows = VniMatrix::build($vniRows, $floodRows, $nodes);

    expect($rows[0]['gaps'])->toBe([['device_id' => 2, 'missing' => 3]])
        ->and($rows[0]['flags'])->toBe(['flood-gap']);
});

it('does not judge a leaf whose remote table was never collected', function () {
    $rows = VniMatrix::build([vniRow(1, 10), vniRow(2, 10)], [['device_id' => 2, 'vni' => 10, 'remote_vtep_ip' => '192.0.2.11']], [1 => ['192.0.2.11'], 2 => ['192.0.2.12']]);

    expect($rows[0]['flags'])->toBe([])->and($rows[0]['orphan'])->toBe([]);
});

it('reports anycast IRBs and flags one that is down', function () {
    $rows = VniMatrix::build(
        [vniRow(1, 50, ['irb_ifname' => 'irb.50', 'irb_status' => 'Up']), vniRow(2, 50, ['irb_ifname' => 'irb.50', 'irb_status' => 'Down']), vniRow(3, 50)],
        [],
        [1 => ['192.0.2.11'], 2 => ['192.0.2.12'], 3 => ['192.0.2.13']],
    );

    expect($rows[0]['irbs'])->toHaveKeys([1, 2])
        ->and($rows[0]['irb_down'])->toBe([2])
        ->and($rows[0]['irb_partial'])->toBeTrue()
        ->and($rows[0]['flags'])->toBe(['irb-down']);
});

it('filters by VNI, VLAN tag, name and issues', function () {
    $rows = VniMatrix::build([vniRow(1, 10, ['vlan_id' => 110]), vniRow(1, 20), vniRow(2, 20, ['vlan_id' => 220])], [], [1 => ['192.0.2.11'], 2 => ['192.0.2.12']]);

    expect(array_column(VniMatrix::filter($rows, '10', false), 'vni'))->toBe([10])
        ->and(array_column(VniMatrix::filter($rows, '110', false), 'vni'))->toBe([10])
        ->and(array_column(VniMatrix::filter($rows, 'vx2', false), 'vni'))->toBe([20])
        ->and(array_column(VniMatrix::filter($rows, '', true), 'vni'))->toBe([20])
        ->and(VniMatrix::filter($rows, 'nothing', false))->toBe([]);
});
