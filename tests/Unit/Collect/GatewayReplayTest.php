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
            ->on('show evpn database state duplicate', (string) file_get_contents($fixtures . 'show-evpn-database-state-duplicate.xml'))
            ->on('show evpn l3-context', (string) file_get_contents($fixtures . 'show-evpn-l3-context.xml'));
        $all = (new DefinitionLoader([DefinitionLoader::shippedDirectory()]))->all();
        $result = (new Collector($transport))->collect([$all['junos-evpn'], $all['junos-routing']]);
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
