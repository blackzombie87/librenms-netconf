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
        ->and($result->summary()['commands_ok'])->toBe(23) // distinct commands across the shipped definitions
        ->and($result->summary()['commands_skipped'])->toBe(0)
        ->and($result->summary()['commands_failed'])->toBe(0)
        ->and(count($result->sensors()))->toBeGreaterThanOrEqual(33)
        ->and(count($result->metrics()))->toBeGreaterThanOrEqual(36);
});

it('extracts the EVPN state of the leaf', function () {
    expect(sensorsOf('junos-evpn', 'dup-mac-total')['total']->value)->toBe(0.0)
        ->and(sensorsOf('junos-evpn', 'dup-mac-instance'))->toBe([])
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
        ->and(metricsOf('junos-ntp', 'system')['system']->values['offset_ms'])->toBe(0.325185);

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

it('extracts the synthetic LDP, RPKI and VRRP samples', function () {
    expect(sensorsOf('junos-ldp', 'neighbors')['total']->value)->toBe(2.0)
        ->and(sensorsOf('junos-ldp', 'sessions-down')['total']->value)->toBe(1.0)
        ->and(sensorsOf('junos-ldp', 'session-state')['192.0.2.101']->state?->label)->toBe('Operational')
        ->and(sensorsOf('junos-ldp', 'session-state')['192.0.2.102']->state?->generic)->toBe(2);

    expect(sensorsOf('junos-rpki', 'session-state')['192.0.2.202']->state?->label)->toBe('Transition')
        ->and(sensorsOf('junos-rpki', 'sessions-down')['total']->value)->toBe(1.0)
        ->and(sensorsOf('junos-rpki', 'invalid-origins')['invalid']->value)->toBe(1200.0)
        ->and(metricsOf('junos-rpki', 'session')['192.0.2.201']->types['flaps'])->toBe('COUNTER');

    expect(array_keys(sensorsOf('junos-vrrp', 'state')))->toBe(['irb.100/100', 'irb.200/200', 'irb.300/300'])
        ->and(sensorsOf('junos-vrrp', 'state')['irb.300/300']->state?->label)->toBe('Init')
        ->and(sensorsOf('junos-vrrp', 'groups-degraded')['total']->value)->toBe(1.0)
        ->and(metricsOf('junos-vrrp', 'group')['irb.100/100']->values['master'])->toBe(1.0);
});
