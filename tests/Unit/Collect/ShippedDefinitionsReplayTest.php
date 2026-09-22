<?php

use SafferIt\LibrenmsNetconf\Collect\CollectionResult;
use SafferIt\LibrenmsNetconf\Collect\Collector;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Support\FixtureReplay;

/**
 * Regression test: every shipped definition replayed against the recorded Junos replies.
 */
function replayShipped(): CollectionResult
{
    static $result = null;
    if ($result === null) {
        $definitions = array_values((new DefinitionLoader([DefinitionLoader::shippedDirectory()]))->all());
        $commands = [];
        foreach ($definitions as $d) {
            foreach ($d->commands as $c) {
                $commands[] = $c->cli;
            }
        }
        [$transport, $missing] = FixtureReplay::transport(__DIR__ . '/../../fixtures/junos', $commands);
        expect($missing)->toBe([]);
        $result = (new Collector($transport))->collect($definitions);
    }

    return $result;
}

function tablesOf(string $definition, string $mapping): array
{
    $out = [];
    foreach (replayShipped()->definitions[$definition]->tables as $t) {
        if ($t->mapping->id === $mapping) {
            $out[$t->key] = $t;
        }
    }

    return $out;
}

function sensorsOf(string $definition, string $mapping): array
{
    $out = [];
    foreach (replayShipped()->definitions[$definition]->sensors as $s) {
        if ($s->mapping->id === $mapping) {
            $out[$s->index] = $s;
        }
    }

    return $out;
}

function metricsOf(string $definition, string $mapping): array
{
    $out = [];
    foreach (replayShipped()->definitions[$definition]->metrics as $m) {
        if ($m->mapping->id === $mapping) {
            $out[$m->index] = $m;
        }
    }

    return $out;
}

it('replays every shipped definition without errors or warnings', function () {
    $result = replayShipped();

    expect($result->ok())->toBeTrue()
        ->and($result->warnings())->toBe([])
        ->and($result->summary()['commands_ok'])->toBe(28) // distinct commands across the shipped definitions
        ->and($result->summary()['commands_skipped'])->toBe(0)
        ->and($result->summary()['commands_failed'])->toBe(0)
        ->and(count($result->sensors()))->toBeGreaterThanOrEqual(33)
        ->and(count($result->metrics()))->toBeGreaterThanOrEqual(36);
});

it('extracts the EVPN state of the leaf', function () {
    // the 2026-09-22 sample: one suppressed MAC (a VRRP virtual MAC in an L3 VNI) in mgmt-vrf
    expect(sensorsOf('junos-evpn', 'dup-mac-total')['total']->value)->toBe(1.0)
        ->and(array_keys(sensorsOf('junos-evpn', 'dup-mac-instance')))->toBe(['mgmt-vrf'])
        ->and(sensorsOf('junos-evpn', 'dup-mac-instance')['mgmt-vrf']->value)->toBe(1.0)
        ->and(sensorsOf('junos-evpn', 'dup-mac-instance')['mgmt-vrf']->descr)->toBe('EVPN duplicate MACs mgmt-vrf')
        ->and(sensorsOf('junos-evpn', 'esi-lag-status')['ae2.0']->state?->label)->toBe('Up')
        ->and(sensorsOf('junos-evpn', 'esi-lag-status')['ae4.0']->state?->generic)->toBe(2)
        ->and(sensorsOf('junos-evpn', 'esi-resolution')['ae2.0']->rawText)->toBe('Resolved by IFL ae2.0')
        ->and(array_keys(sensorsOf('junos-evpn', 'interface-status')))->toBe(['ae2.0', 'ae4.0', 'ge-0/0/28.0'])
        ->and(sensorsOf('junos-evpn', 'esi-without-remote-pe')['total']->value)->toBe(1.0)
        ->and(sensorsOf('junos-evpn', 'bridge-domains-degraded')['total']->value)->toBe(1.0)
        ->and(sensorsOf('junos-evpn', 'l3-contexts')['total']->value)->toBe(0.0);

    $instance = metricsOf('junos-evpn', 'instance');
    expect(array_keys($instance))->toBe(['default-switch'])
        ->and($instance['default-switch']->values['local_macs'])->toBe(577.0)
        ->and($instance['default-switch']->values['remote_macs'])->toBe(4449.0)
        ->and($instance['default-switch']->strings['encap'])->toBe('VXLAN');

    $esi = metricsOf('junos-evpn', 'esi');
    expect($esi['ae2.0']->values['is_df'])->toBe(1.0)
        ->and($esi['ae2.0']->values['remote_pes'])->toBe(1.0)
        ->and($esi['ae4.0']->values['remote_pes'])->toBe(0.0)
        ->and($esi['ae2.0']->strings['esi'])->toBe('00:11:22:33:44:55:00:00:02:00')
        ->and($esi['ae2.0']->strings['df'])->toBe('192.0.2.11');

    expect(metricsOf('junos-evpn', 'neighbor'))->toHaveKeys(['default-switch/192.0.2.12', 'default-switch/192.0.2.61', '__default_evpn__/192.0.2.12']);
});

it('maps ESI-LAGs to remote VTEPs', function () {
    $esi = metricsOf('junos-evpn-esi', 'esi');
    $peers = metricsOf('junos-evpn-esi', 'remote-vtep');

    expect(array_keys($esi))->toBe(['ae2.0', 'ae5.0'])
        ->and($esi['ae2.0']->values['remote_macs'])->toBe(81.0)
        ->and($esi['ae2.0']->strings['esi'])->toBe('00:11:22:33:44:55:00:00:02:00')
        ->and(array_keys($peers))->toBe(['ae2.0/192.0.2.12'])
        ->and($peers['ae2.0/192.0.2.12']->strings['vtep'])->toBe('192.0.2.12');
});

it('extracts routing, L2 and alarm sensors', function () {
    expect(sensorsOf('junos-routing', 'active-routes')['bgp.evpn.0']->value)->toBe(11587.0)
        ->and(array_keys(sensorsOf('junos-routing', 'hidden-routes')))->toBe(['inet.0', ':vxlan.inet.0', 'bgp.evpn.0', 'default-switch.evpn.0'])
        ->and(sensorsOf('junos-routing', 'bgp-peers-down')['total']->value)->toBe(0.0)
        ->and(metricsOf('junos-routing', 'protocol')['bgp.evpn.0/EVPN']->values['routes'])->toBe(1443.0)
        ->and(metricsOf('junos-routing', 'bgp-peer')['192.0.2.51']->values['flaps'])->toBe(43.0)
        ->and(metricsOf('junos-routing', 'bgp-peer')['192.0.2.51']->types['flaps'])->toBe('COUNTER')
        ->and(metricsOf('junos-routing', 'bgp-peer')['192.0.2.51']->strings['state'])->toBe('Established')
        ->and(metricsOf('junos-routing', 'bgp-peer-rib'))->toHaveCount(6)
        ->and(sensorsOf('junos-l2', 'mac-total')['total']->value)->toBe(2841.0)
        ->and(sensorsOf('junos-alarms', 'system-minor')['system-minor']->value)->toBe(4.0)
        ->and(sensorsOf('junos-alarms', 'chassis-major')['chassis-major']->value)->toBe(0.0);
});

it('extracts per-port counters keyed by ifIndex', function () {
    $ports = replayShipped()->definitions['junos-interfaces']->ports;

    expect($ports)->toHaveCount(1)
        ->and($ports[0]->matchField)->toBe('ifIndex')
        ->and($ports[0]->matchValue)->toBe('626')
        ->and($ports[0]->values['oversized'])->toBe(26602.0)
        ->and($ports[0]->values['bpdu_error'])->toBe(0.0)
        ->and($ports[0]->types['oversized'])->toBe('COUNTER')
        ->and($ports[0]->values)->toHaveCount(19);

    expect(array_keys(metricsOf('junos-interface-queues', 'queue')))->toBe(['et-0/0/49/0', 'et-0/0/49/7', 'et-0/0/49/8']);
});

it('expands the flattened SRX cluster table per node', function () {
    $status = sensorsOf('junos-srx-cluster', 'rg-status');

    expect(array_keys($status))->toBe(['RG0/node0', 'RG0/node1', 'RG1/node0', 'RG1/node1'])
        ->and($status['RG0/node0']->state?->label)->toBe('primary')
        ->and($status['RG0/node1']->state?->label)->toBe('secondary')
        ->and($status['RG0/node0']->descr)->toBe('Cluster RG0/node0')
        ->and(sensorsOf('junos-srx-cluster', 'rg-monitor')['RG1/node1']->state?->label)->toBe('None')
        ->and(sensorsOf('junos-srx-cluster', 'rg-failovers')['RG1']->value)->toBe(1.0)
        ->and(metricsOf('junos-srx-cluster', 'rg-node')['RG0/node1']->values['priority'])->toBe(1.0)
        ->and(metricsOf('junos-srx-cluster', 'rg-node')['RG0/node1']->strings['status'])->toBe('secondary');
});

it('extracts the Tier 2 system, NTP, license and LACP data', function () {
    expect(sensorsOf('junos-ntp', 'sync')['ntp']->state?->label)->toBe('Synchronized')
        ->and(sensorsOf('junos-ntp', 'stratum')['stratum']->value)->toBe(4.0)
        ->and(sensorsOf('junos-ntp', 'peers-reachable')['reachable']->value)->toBe(1.0)
        ->and(array_keys(metricsOf('junos-ntp', 'peer')))->toBe(['ntp1.example.net'])
        ->and(metricsOf('junos-ntp', 'peer')['ntp1.example.net']->values['selected'])->toBe(1.0)
        ->and(metricsOf('junos-ntp', 'system')['system']->values['offset_ms'])->toBe(0.325185)
        ->and(metricsOf('junos-ntp', 'peer')['ntp1.example.net']->strings['reach'])->toBe('377')
        ->and(array_keys(metricsOf('junos-ntp', 'peer')['ntp1.example.net']->rrdValues()))->toBe(['stratum', 'delay_ms', 'offset_ms', 'jitter_ms', 'poll', 'selected'])
        ->and(array_keys(metricsOf('junos-ntp', 'system')['system']->types))->toBe(['offset_ms', 'rootdelay_ms', 'rootdisp_ms', 'sys_jitter', 'clk_jitter', 'frequency', 'stratum']);

    expect(sensorsOf('junos-system', 'krt-queue')['total']->value)->toBe(0.0)
        ->and(metricsOf('junos-system', 'krt'))->toHaveCount(32)
        ->and(metricsOf('junos-system', 'uptime')['localre']->values['config_age'])->toBe(93242.0)
        ->and(metricsOf('junos-system', 'uptime')['localre']->strings['config_user'])->toBe('netadmin')
        ->and(metricsOf('junos-system', 'commit')['last']->values['history'])->toBe(4.0)
        ->and(metricsOf('junos-system', 'commit')['last']->values['epoch'])->toBe(1789636652.0);

    expect(sensorsOf('junos-license', 'unlicensed')['unlicensed']->value)->toBe(4.0)
        ->and(metricsOf('junos-license', 'feature')['evpn-vxlan']->strings['validity'])->toBe('invalid');

    expect(array_keys(sensorsOf('junos-lacp', 'member-state')))->toBe(['xe-0/0/1', 'xe-0/0/2'])
        ->and(sensorsOf('junos-lacp', 'member-state')['xe-0/0/1']->descr)->toBe('LACP xe-0/0/1 in ae1')
        ->and(sensorsOf('junos-lacp', 'members-degraded')['ae1']->value)->toBe(0.0);
});

it('extracts the LDP, RPKI and VRRP samples from a Junos 22.2 router', function () {
    expect(sensorsOf('junos-ldp', 'neighbors')['total']->value)->toBe(4.0)
        ->and(sensorsOf('junos-ldp', 'sessions-down')['total']->value)->toBe(0.0)
        ->and(array_keys(sensorsOf('junos-ldp', 'session-state')))->toBe(['198.51.100.1', '198.51.100.2', '198.51.100.4'])
        ->and(sensorsOf('junos-ldp', 'session-state')['198.51.100.1']->state?->label)->toBe('Operational');

    expect(sensorsOf('junos-rpki', 'session-state')['198.51.100.7']->state?->label)->toBe('Up')
        ->and(sensorsOf('junos-rpki', 'sessions-down')['total']->value)->toBe(0.0)
        ->and(sensorsOf('junos-rpki', 'invalid-origins')['invalid']->value)->toBe(936368.0)
        ->and(metricsOf('junos-rpki', 'session')['198.51.100.7']->types['flaps'])->toBe('COUNTER')
        ->and(metricsOf('junos-rpki', 'session')['198.51.100.7']->values['v6'])->toBe(233566.0);

    // a dual-stack interface reports one row per address family with the same interface/group
    expect(array_keys(sensorsOf('junos-vrrp', 'state')))
        ->toBe(['ae1.600/1', 'et-0/0/1.12/112', 'et-0/0/1.1205/200', 'lt-0/0/0.13/99', 'lt-0/0/0.13/99/v6', 'xe-0/1/5.2103/3'])
        ->and(sensorsOf('junos-vrrp', 'state')['lt-0/0/0.13/99/v6']->state?->label)->toBe('Master')
        ->and(sensorsOf('junos-vrrp', 'groups-degraded')['total']->value)->toBe(0.0)
        ->and(metricsOf('junos-vrrp', 'group')['ae1.600/1']->values['master'])->toBe(1.0)
        ->and(metricsOf('junos-vrrp', 'group')['lt-0/0/0.13/99/v6']->strings['vip'])->toStartWith('2001:db8:');
});

it('fills the EVPN fabric tables of the leaf', function () {
    $neighbors = tablesOf('junos-evpn-fabric', 'neighbor');
    expect(array_keys($neighbors))->toBe(['__default_evpn__/192.0.2.12', 'default-switch/192.0.2.12', 'default-switch/192.0.2.61'])
        ->and($neighbors['default-switch/192.0.2.61']->values['router_id'])->toBe('192.0.2.11')
        ->and($neighbors['default-switch/192.0.2.61']->values['mac_routes'])->toBe(1482);

    $esi = tablesOf('junos-evpn-fabric', 'esi');
    expect(array_keys($esi))->toBe(['00:11:22:33:44:55:00:00:02:00', '00:11:22:33:44:55:00:00:04:00', '00:11:22:33:44:66:00:00:01:00'])
        ->and($esi['00:11:22:33:44:55:00:00:02:00']->values)->toMatchArray(['local_ifname' => 'ae2.0', 'lag_status' => 'Up/Forwarding', 'mode' => 'all-active', 'is_df' => true, 'df_ip' => '192.0.2.11', 'bdf_ip' => '192.0.2.12', 'remote_vtep_ips' => ['192.0.2.12']])
        ->and($esi['00:11:22:33:44:55:00:00:04:00']->values)->toMatchArray(['lag_status' => 'Down', 'is_df' => false, 'df_ip' => null, 'remote_vtep_ips' => []])
        ->and($esi['00:11:22:33:44:66:00:00:01:00']->values['local_ifname'])->toBeNull()
        ->and(tablesOf('junos-evpn-fabric', 'esi-forwarding')['00:11:22:33:44:55:00:00:02:00']->values)->toBe(['esi' => '00:11:22:33:44:55:00:00:02:00', 'aliasing' => true, 'remote_mac_count' => 81]);

    $vni = tablesOf('junos-evpn-fabric', 'vni');
    expect(array_keys($vni))->toBe([10, 100, 1001, 1002, 1003])
        ->and($vni['10']->values)->toBe(['vni' => 10, 'instance' => 'default-switch', 'vlan_name' => 'VX10', 'source_vtep' => '192.0.2.61', 'multicast_group' => '0.0.0.0'])
        ->and(tablesOf('junos-evpn-fabric', 'vni-vlan')['1001']->values)->toBe(['vni' => 1001, 'vlan_id' => 1001, 'vlan_name' => 'VX1001', 'remote_macs' => 3])
        ->and(tablesOf('junos-evpn-fabric', 'vni-irb'))->toBe([]);

    expect(tablesOf('junos-evpn-fabric', 'vni-vtep'))->toHaveCount(12)
        ->and(tablesOf('junos-evpn-fabric', 'vni-vtep')['452/192.0.2.11']->values['instance'])->toBe('default-switch')
        ->and(tablesOf('junos-evpn-fabric', 'tunnel')['192.0.2.21']->values)->toBe(['remote_vtep_ip' => '192.0.2.21', 'ifname' => 'vtep.32770', 'snmp_index' => 524])
        ->and(tablesOf('junos-evpn-fabric', 'tunnel-nexthop')['192.0.2.11']->values)->toBe(['remote_vtep_ip' => '192.0.2.11', 'mode' => 'RNVE', 'nh_id' => 2445])   // no ifname: only `tunnel` writes the kernel IFL
        ->and(tablesOf('junos-evpn-fabric', 'tunnel-instance')['192.0.2.11']->values['ri_ifname'])->toBe('vtep-4.32776');

    $mac = tablesOf('junos-evpn-fabric-mac', 'mac');
    expect($mac)->toHaveCount(9)
        ->and($mac['10/020000000009']->values)->toMatchArray(['source' => '192.0.2.11', 'source_type' => 'remote', 'ip_addresses' => ['203.0.113.14']])
        ->and($mac['1001/02000000000c']->values['source_type'])->toBe('local')
        ->and($mac['10/020000000001']->values['source_type'])->toBe('esi')
        ->and($mac['10/020000000001']->values['active_since'])->toEndWith('-09-18 14:15:11');
});
