<?php

use SafferIt\LibrenmsNetconf\Collect\Collector;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Transport\FakeTransport;

/**
 * Regression test for an EVPN L3 gateway (MX204 sample): every anycast IRB carries a type-5 ESI whose
 * local interface is irb.N with an empty status and no remote PE. Those are not ESI-LAGs and must not
 * produce resolution/LAG sensors or a "without remote PE" alarm.
 */
function replayGateway(): \SafferIt\LibrenmsNetconf\Collect\CollectionResult
{
    static $result = null;
    if ($result === null) {
        $fixtures = __DIR__ . '/../../fixtures/junos/';
        $transport = (new FakeTransport)
            ->on('show evpn instance extensive', (string) file_get_contents($fixtures . 'show-evpn-instance-extensive-mx.xml'))
            ->on('show bgp summary', (string) file_get_contents($fixtures . 'show-bgp-summary-mx.xml'))
            ->on('show route summary', (string) file_get_contents($fixtures . 'show-route-summary.xml'))
            ->on('show evpn database state duplicate', (string) file_get_contents($fixtures . 'show-evpn-database-state-duplicate-empty.xml'))
            ->on('show evpn l3-context', (string) file_get_contents($fixtures . 'show-evpn-l3-context.xml'));
        $all = (new DefinitionLoader([DefinitionLoader::shippedDirectory()]))->all();
        $result = (new Collector($transport))->collect([$all['junos-evpn'], $all['junos-routing'], $all['junos-evpn-fabric']]);
    }

    return $result;
}

function gatewaySensors(string $definition, string $mapping): array
{
    $out = [];
    foreach (replayGateway()->definitions[$definition]->sensors as $s) {
        if ($s->mapping->id === $mapping) {
            $out[$s->index] = $s;
        }
    }

    return $out;
}

it('does not treat anycast gateway IRB ESIs as ESI-LAGs', function () {
    expect(replayGateway()->ok())->toBeTrue()
        ->and(gatewaySensors('junos-evpn', 'esi-resolution'))->toBe([])
        ->and(gatewaySensors('junos-evpn', 'esi-lag-status'))->toBe([])
        ->and(gatewaySensors('junos-evpn', 'esi-without-remote-pe')['total']->value)->toBe(0.0)
        ->and(gatewaySensors('junos-evpn', 'gateway-irbs')['total']->value)->toBe(3.0)
        ->and(array_keys(gatewaySensors('junos-evpn', 'irb-status')))->toBe(['irb.101', 'irb.102', 'irb.12'])
        ->and(gatewaySensors('junos-evpn', 'irb-status')['irb.12']->state?->label)->toBe('Up')
        ->and(gatewaySensors('junos-evpn', 'bridge-domains-degraded')['total']->value)->toBe(0.0);

    $esiMetrics = array_filter(replayGateway()->definitions['junos-evpn']->metrics, fn ($m) => $m->mapping->id === 'esi');
    expect($esiMetrics)->toBe([]);
});

it('keeps overlay and Internet BGP peers apart on the gateway', function () {
    $peers = [];
    foreach (replayGateway()->definitions['junos-routing']->metrics as $m) {
        if ($m->mapping->id === 'bgp-peer') {
            $peers[$m->index] = $m;
        }
    }
    // overlay peers are the ones with an evpn RIB, independent of AS or description
    $ribs = [];
    foreach (replayGateway()->definitions['junos-routing']->metrics as $m) {
        if ($m->mapping->id === 'bgp-peer-rib' && str_ends_with($m->index, '/bgp.evpn.0')) {
            $ribs[] = explode('/', $m->index)[0];
        }
    }
    expect($peers)->toHaveCount(7)
        ->and($ribs)->toBe(['192.0.2.11', '192.0.2.62', '192.0.2.253'])
        ->and(gatewaySensors('junos-routing', 'bgp-peers-down')['total']->value)->toBe(0.0);
});

function gatewayTables(string $mapping): array
{
    $out = [];
    foreach (replayGateway()->definitions['junos-evpn-fabric']->tables as $t) {
        if ($t->mapping->id === $mapping) {
            $out[$t->key] = $t;
        }
    }

    return $out;
}

it('fills the fabric tables of an L3 gateway: IRB per VNI, leaf ESIs only, overlay neighbours', function () {
    // the VXLAN forwarding commands have no gateway fixture and are optional: skipped, not failed
    $skipped = array_map(fn ($c) => $c->label, array_filter(replayGateway()->commands, fn ($c) => $c->status === \SafferIt\LibrenmsNetconf\Collect\CommandRun::SKIPPED));
    expect(replayGateway()->ok())->toBeTrue()
        ->and(array_values($skipped))->toContain('show mac-vrf forwarding vxlan-tunnel-end-point source', 'show interfaces vtep')
        ->and(gatewayTables('vni'))->toBe([])
        ->and(gatewayTables('tunnel'))->toBe([]);

    $irb = gatewayTables('vni-irb');
    expect(array_keys($irb))->toBe([101, 102, 12])
        ->and($irb['12']->values)->toBe(['vni' => 12, 'instance' => 'EVPN-FABRIC', 'irb_ifname' => 'irb.12', 'irb_status' => 'Up'])
        ->and($irb['101']->values['irb_ifname'])->toBe('irb.101');

    // type-5 gateway ESIs (05:…, local interface irb.N) are not ESI-LAGs and stay out of the esi table
    $esi = gatewayTables('esi');
    expect(array_keys($esi))->toBe(['00:11:22:33:44:55:00:00:02:00', '00:11:22:33:44:55:00:00:03:00', '00:11:22:33:44:55:00:00:04:00'])
        ->and($esi['00:11:22:33:44:55:00:00:02:00']->values)->toMatchArray(['instance' => 'EVPN-FABRIC', 'local_ifname' => null, 'status' => 'Resolved', 'mode' => 'all-active', 'is_df' => false, 'df_ip' => null, 'remote_vtep_ips' => ['192.0.2.11', '192.0.2.12']])
        ->and($esi['00:11:22:33:44:55:00:00:03:00']->values['remote_vtep_ips'])->toBe(['192.0.2.22']);

    $neighbors = gatewayTables('neighbor');
    expect(array_keys($neighbors))->toBe(['EVPN-FABRIC/192.0.2.11', 'EVPN-FABRIC/192.0.2.61', 'EVPN-FABRIC/192.0.2.253'])
        ->and($neighbors['EVPN-FABRIC/192.0.2.11']->values['router_id'])->toBe('192.0.2.252');
});
