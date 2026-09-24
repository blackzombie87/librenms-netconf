<?php

use SafferIt\LibrenmsNetconf\Fabric\Checks\CheckInput;
use SafferIt\LibrenmsNetconf\Fabric\Checks\FabricChecks;
use SafferIt\LibrenmsNetconf\Fabric\Checks\Issue;
use SafferIt\LibrenmsNetconf\Fabric\View\EsiMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\OverlaySessions;
use SafferIt\LibrenmsNetconf\Fabric\View\TunnelMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\VniMatrix;

/**
 * The fabric consistency checks (plan §7.5) over the same rows the tabs aggregate. Two
 * monitored leaves (11: VTEP 192.0.2.61 with router-id alias 192.0.2.11, 12: 192.0.2.62),
 * an unknown spine 192.0.2.1, and a leaf 13 that is fine.
 */
function checkNodes(): FabricNodes
{
    return FabricNodes::fromArray([
        ['vtep_ip' => '192.0.2.1', 'device_id' => null, 'name' => 'spine-a', 'role' => 'spine'],
        ['vtep_ip' => '192.0.2.61', 'device_id' => 11, 'name' => 'leaf-a', 'role' => 'leaf'],
        ['vtep_ip' => '192.0.2.11', 'device_id' => 11, 'name' => 'leaf-a', 'role' => 'leaf', 'member' => false],
        ['vtep_ip' => '192.0.2.62', 'device_id' => 12, 'name' => 'leaf-b', 'role' => 'leaf'],
        ['vtep_ip' => '192.0.2.63', 'device_id' => 13, 'name' => 'leaf-c', 'role' => 'leaf'],
    ]);
}

/** @return array<string, list<Issue>> check => issues */
function byCheck(array $issues): array
{
    $out = [];
    foreach ($issues as $issue) {
        $out[$issue->check][] = $issue;
    }

    return $out;
}

it('finds nothing in a consistent fabric apart from the unknown spine', function () {
    $nodes = checkNodes();
    $issues = FabricChecks::evaluate($nodes, new CheckInput(
        neighbors: [
            ['device_id' => 11, 'instance' => 'MACVRF-A', 'neighbor_ip' => '192.0.2.62', 'mac_routes' => 10, 'mac_ip_routes' => 3],
            ['device_id' => 12, 'instance' => 'MACVRF-A', 'neighbor_ip' => '192.0.2.11', 'mac_routes' => 7, 'mac_ip_routes' => 0],   // by the router-id alias
        ],
        instances: [11 => ['MACVRF-A' => ['local_macs' => 7, 'dup_threshold' => '5', 'dup_window' => '180', 'dup_recovery' => '5']], 12 => ['MACVRF-A' => ['local_macs' => 13, 'dup_threshold' => '5', 'dup_window' => '180', 'dup_recovery' => '5']]],
        versions: [11 => '23.4R2-S7.4', 12 => '23.4R2-S7.4', 13 => null],
    ));

    expect(array_map(fn (Issue $i) => [$i->check, $i->severity, $i->subject], $issues))->toBe([
        ['unknown-vtep', Issue::WARNING, '192.0.2.1'],
    ])->and($issues[0]->message)->toBe('VTEP 192.0.2.1 (spine-a) is not a monitored device')
        ->and($issues[0]->deviceIds)->toBe([]);
});

it('flags asymmetric neighbours, sessions down or missing, and missing MAC routes', function () {
    $nodes = checkNodes();
    $sessions = [
        ['device_id' => 11, 'peer_ip' => '192.0.2.1', 'state' => 'Active', 'up' => false, 'flaps' => 3],
        ['device_id' => 12, 'peer_ip' => '192.0.2.1', 'state' => 'Established', 'up' => true, 'flaps' => 0],
        ['device_id' => 12, 'peer_ip' => '192.0.2.11', 'state' => 'Connect', 'up' => false, 'flaps' => null],   // towards leaf-a's router-id
        ['device_id' => 11, 'peer_ip' => '198.51.100.9', 'state' => 'Idle', 'up' => false, 'flaps' => null],    // outside the fabric
        ['device_id' => 13, 'peer_ip' => '192.0.2.1', 'state' => 'Established', 'up' => true, 'flaps' => 0],
    ];
    $issues = byCheck(FabricChecks::evaluate($nodes, new CheckInput(
        neighbors: [
            ['device_id' => 11, 'instance' => 'MACVRF-A', 'neighbor_ip' => '192.0.2.62', 'mac_routes' => 0, 'mac_ip_routes' => 0],
            // leaf-b lists nobody back
        ],
        sessions: $sessions,
        missingSessions: OverlaySessions::missing($sessions, $nodes->deviceNodes()),
        instances: [12 => ['MACVRF-A' => ['local_macs' => 13, 'dup_threshold' => null, 'dup_window' => null, 'dup_recovery' => null]]],
    )));

    expect($issues['neighbor-asymmetric'][0]->subject)->toBe('11>12')
        ->and($issues['neighbor-asymmetric'][0]->message)->toBe('leaf-a lists leaf-b as EVPN neighbour, leaf-b does not list leaf-a')
        ->and($issues['neighbor-asymmetric'][0]->deviceIds)->toBe([11, 12])
        ->and(array_map(fn (Issue $i) => $i->subject, $issues['session-down']))->toBe(['11/192.0.2.1', '12/192.0.2.11'])
        ->and($issues['session-down'][0]->message)->toBe('EVPN session leaf-a → spine-a is Active (3 flaps)')
        ->and($issues['session-down'][0]->severity)->toBe(Issue::CRITICAL)
        ->and($issues['session-down'][1]->deviceIds)->toBe([12, 11])
        ->and($issues['session-down'][1]->message)->toBe('EVPN session leaf-b → leaf-a is Connect')
        ->and(array_map(fn (Issue $i) => $i->subject, $issues['session-missing'] ?? []))->toBe([])   // 192.0.2.11 is leaf-a itself, only one other has it
        ->and($issues['route-count'][0]->message)->toBe('leaf-a receives no MAC routes from leaf-b in MACVRF-A, which has 13 local MACs')
        ->and(isset($issues['unknown-vtep']))->toBeTrue();
});

it('reports a member missing a session the others have', function () {
    $nodes = checkNodes();
    $sessions = [
        ['device_id' => 11, 'peer_ip' => '192.0.2.1', 'state' => 'Established', 'up' => true, 'flaps' => 0],
        ['device_id' => 12, 'peer_ip' => '192.0.2.1', 'state' => 'Established', 'up' => true, 'flaps' => 0],
    ];
    $issues = byCheck(FabricChecks::evaluate($nodes, new CheckInput(sessions: $sessions, missingSessions: OverlaySessions::missing($sessions, $nodes->deviceNodes()))));

    expect($issues['session-missing'][0]->subject)->toBe('13/192.0.2.1')
        ->and($issues['session-missing'][0]->message)->toBe('leaf-c has no EVPN session to spine-a, which leaf-a, leaf-b have')
        ->and($issues['session-missing'][0]->deviceIds)->toBe([13]);
});

it('turns the VNI matrix flags into issues', function () {
    $nodes = checkNodes();
    $vni = fn (int $device, int $vni, array $extra = []) => $extra + ['device_id' => $device, 'vni' => $vni, 'instance' => 'MACVRF-A', 'vlan_id' => 100, 'vlan_name' => null, 'source_vtep' => null, 'multicast_group' => null, 'irb_ifname' => null, 'irb_status' => null, 'remote_macs' => 0];
    $rows = VniMatrix::build(
        [
            // vlan mismatch, and leaf-b's flood list lacks leaf-a although leaf-c hears it
            $vni(11, 10010), $vni(12, 10010, ['vlan_id' => 200]), $vni(13, 10010, ['vlan_id' => null]),
            $vni(11, 10011, ['irb_ifname' => 'irb.11', 'irb_status' => 'Down']), $vni(12, 10011),   // irb down on a, partial
            $vni(11, 10012), $vni(13, 10012),                                        // leaf-a hears nothing while leaf-c is advertised
        ],
        [
            ['device_id' => 11, 'vni' => 10010, 'remote_vtep_ip' => '192.0.2.62'],
            ['device_id' => 12, 'vni' => 10010, 'remote_vtep_ip' => '192.0.2.1'],
            ['device_id' => 13, 'vni' => 10010, 'remote_vtep_ip' => '192.0.2.61'], ['device_id' => 13, 'vni' => 10010, 'remote_vtep_ip' => '192.0.2.62'],
            ['device_id' => 11, 'vni' => 10011, 'remote_vtep_ip' => '192.0.2.62'],
            ['device_id' => 12, 'vni' => 10011, 'remote_vtep_ip' => '192.0.2.11'],
            ['device_id' => 12, 'vni' => 10012, 'remote_vtep_ip' => '192.0.2.63'],
            ['device_id' => 13, 'vni' => 10012, 'remote_vtep_ip' => '192.0.2.61'],
        ],
        $nodes->deviceNodes(),
    );
    $issues = byCheck(FabricChecks::evaluate($nodes, new CheckInput(vnis: $rows)));

    expect($issues['vni-flood-gap'][0]->subject)->toBe('10010/12>11')
        ->and($issues['vni-flood-gap'][0]->message)->toBe('VNI 10010: flood list of leaf-b lacks leaf-a, which advertises the VNI to the other carriers')
        ->and($issues['vni-flood-gap'][0]->severity)->toBe(Issue::CRITICAL)
        ->and($issues['vni-vlan-mismatch'][0]->message)->toBe('VNI 10010 maps to different VLAN tags: leaf-a: VLAN 100, leaf-b: VLAN 200')
        ->and($issues['vni-vlan-mismatch'][0]->severity)->toBe(Issue::INFO)
        ->and($issues['vni-irb-down'][0]->message)->toBe('IRB irb.11 for VNI 10011 is Down on leaf-a')
        ->and($issues['vni-irb-partial'][0]->message)->toBe('VNI 10011 has an IRB on leaf-a but not on leaf-b')
        ->and($issues['vni-orphan'][0]->message)->toBe('VNI 10012 has an empty flood list on leaf-a')
        ->and(isset($issues['vni-stale-flood']))->toBeFalse();   // leaf-b does not carry 10012, so it is not judged as a carrier
});

it('turns the ESI matrix flags into issues with the affected sides', function () {
    $nodes = checkNodes();
    $esi = fn (int $device, string $esi, array $extra = []) => $extra + ['device_id' => $device, 'esi' => $esi, 'instance' => 'default-switch', 'local_ifname' => null, 'local_port_id' => null, 'mode' => 'all-active', 'status' => 'Resolved by IFL ae1.0', 'lag_status' => 'Up/Forwarding', 'is_df' => 0, 'df_ip' => null, 'bdf_ip' => null, 'aliasing' => 1, 'remote_vtep_ips' => '[]', 'remote_mac_count' => null, 'last_seen' => null];
    $single = '00:11:00:00:00:00:00:00:00:01';
    $pair = '00:11:00:00:00:00:00:00:00:02';
    $remoteOnly = '00:11:00:00:00:00:00:00:00:03';
    $rows = EsiMatrix::build(
        [
            $esi(11, $single, ['local_ifname' => 'ae1.0', 'lag_status' => 'Down']),
            $esi(11, $pair, ['local_ifname' => 'ae2.0', 'is_df' => 1, 'df_ip' => '192.0.2.61', 'remote_vtep_ips' => '["192.0.2.62"]', 'mode' => 'all-active', 'aliasing' => 0]),
            $esi(12, $pair, ['local_ifname' => 'ae2.0', 'is_df' => 1, 'df_ip' => '192.0.2.62', 'remote_vtep_ips' => '["192.0.2.61"]', 'mode' => 'single-active', 'status' => 'Unresolved']),
            $esi(13, $remoteOnly, ['remote_vtep_ips' => '["192.0.2.9"]']),
        ],
        $nodes->deviceNodes(),
        [12 => ['ae2' => 1]],
    );
    $issues = byCheck(FabricChecks::evaluate($nodes, new CheckInput(esis: $rows)));

    expect($issues['esi-single-pe'][0]->message)->toBe("ESI $single (leaf-a ae1.0) has no peer PE")
        ->and($issues['esi-single-pe'][0]->severity)->toBe(Issue::CRITICAL)
        ->and($issues['esi-single-pe'][1]->message)->toBe("ESI $remoteOnly is known from one remote PE only (192.0.2.9), seen by leaf-c")
        ->and($issues['esi-single-pe'][1]->severity)->toBe(Issue::WARNING)
        ->and($issues['esi-lag-down'][0]->message)->toBe("ESI-LAG leaf-a ae1.0 is Down (ESI $single)")
        ->and($issues['esi-lag-down'][0]->deviceIds)->toBe([11])
        ->and(array_map(fn (Issue $i) => $i->details['flag'], $issues['esi-df-disagree']))->toBe(['df-disagree', 'df-both'])
        ->and($issues['esi-df-disagree'][0]->message)->toBe("ESI $pair: the PEs name different designated forwarders: leaf-a, leaf-b")
        ->and($issues['esi-mode-differs'][0]->message)->toBe("ESI $pair: mode differs between the PEs (all-active / single-active)")
        ->and($issues['esi-unresolved'][0]->message)->toBe("ESI $pair is Unresolved on leaf-b ae2.0")
        ->and($issues['esi-lacp-degraded'][0]->message)->toBe("leaf-b ae2.0: 1 LACP member(s) not distributing (ESI $pair)")
        ->and($issues['esi-no-aliasing'][0]->message)->toBe("aliasing is off on leaf-a ae2.0 (ESI $pair)");
});

it('reports duplicate MACs, mobility, parameter skew, version skew, tunnels and failing members', function () {
    $nodes = checkNodes();
    $tunnels = TunnelMatrix::build([
        ['device_id' => 11, 'remote_vtep_ip' => '192.0.2.62', 'ifname' => 'vtep.32771', 'port_id' => 501, 'ri_ifname' => null, 'snmp_index' => null, 'mode' => null, 'nh_id' => null, 'mac_count' => null, 'last_seen' => null],
        ['device_id' => 11, 'remote_vtep_ip' => '192.0.2.1', 'ifname' => 'vtep.32772', 'port_id' => null, 'ri_ifname' => null, 'snmp_index' => null, 'mode' => null, 'nh_id' => null, 'mac_count' => null, 'last_seen' => null],
    ], $nodes->deviceNodes())['by_device'];
    $issues = byCheck(FabricChecks::evaluate($nodes, new CheckInput(
        tunnels: $tunnels,
        instances: [
            11 => ['MACVRF-A' => ['local_macs' => 1, 'dup_threshold' => '5', 'dup_window' => '180', 'dup_recovery' => '5']],
            12 => ['MACVRF-A' => ['local_macs' => 1, 'dup_threshold' => '3', 'dup_window' => '180', 'dup_recovery' => '5']],
            13 => ['MACVRF-A' => ['local_macs' => 1, 'dup_threshold' => '5', 'dup_window' => '180', 'dup_recovery' => '5']],
        ],
        dupMacs: [11 => ['MACVRF-A' => 2]],
        macMoves: [['device_id' => 12, 'vni' => 10010, 'mac_address' => '0011223344ff', 'instance' => 'MACVRF-A', 'source_type' => 'remote', 'source' => '192.0.2.61', 'moves' => 9, 'moves_recent' => 6, 'moves_since' => '2026-09-21 09:30:00']],
        portErrors: [501 => ['in_errors' => 4, 'out_errors' => 0, 'in_discards' => 0, 'out_discards' => 12]],
        versions: [11 => '23.4R2-S7.4', 12 => '22.4R3.25', 13 => '23.4R2-S7.4'],
        failing: [13 => 4],
    )));

    expect($issues['dup-mac'][0]->message)->toBe('2 duplicate MACs suppressed in MACVRF-A on leaf-a')
        ->and($issues['dup-mac'][0]->severity)->toBe(Issue::CRITICAL)
        ->and($issues['mac-mobility'][0]->message)->toBe('MAC 00:11:22:33:44:ff in VNI 10010 moved 6 times within an hour as seen by leaf-b (now remote 192.0.2.61)')
        ->and($issues['mac-mobility'][0]->subject)->toBe('10010/0011223344ff/12')
        ->and($issues['dup-mac-params'][0]->message)->toBe('duplicate-MAC detection (threshold/window/recovery) differs in MACVRF-A: 5/180/5 on leaf-a, leaf-c; 3/180/5 on leaf-b')
        ->and($issues['version-skew'][0]->message)->toBe('software versions differ: 22.4R3.25 (leaf-b), 23.4R2-S7.4 (leaf-a, leaf-c)')
        ->and($issues['version-skew'][0]->severity)->toBe(Issue::INFO)
        ->and($issues['tunnel-asymmetric'][0]->message)->toBe('leaf-a has a VXLAN tunnel to leaf-b, leaf-b has none back')
        ->and($issues['tunnel-errors'][0]->message)->toBe('tunnel vtep.32771 on leaf-a to leaf-b: 4 / 0 errors in / out, 0 / 12 discards in the last poll')
        ->and($issues['member-not-polling'][0]->message)->toBe('NETCONF collection on leaf-c is failing (4 consecutive failures), its EVPN data may be stale')
        ->and(array_keys($issues))->toBe(['dup-mac', 'dup-mac-params', 'mac-mobility', 'member-not-polling', 'tunnel-asymmetric', 'tunnel-errors', 'unknown-vtep', 'version-skew']);   // critical first, then by check
});

it('has a label, default severity and description for every check it can raise', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Fabric/Checks/FabricChecks.php');
    preg_match_all("/new Issue\('([a-z-]+)'/", (string) $source, $m);

    expect(array_diff(array_unique($m[1]), array_keys(FabricChecks::CHECKS)))->toBe([])
        ->and(array_diff(array_keys(FabricChecks::CHECKS), array_unique($m[1])))->toBe([]);
    foreach (FabricChecks::CHECKS as [$label, $severity, $description]) {
        expect($label)->not->toBe('')->and(Issue::SEVERITIES)->toContain($severity)->and($description)->not->toBe('');
    }
});
