<?php

use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

it('groups nodes into components keyed by the lowest address', function () {
    $g = new FabricGraph;
    $g->union('192.0.2.61', '192.0.2.12');
    $g->union('192.0.2.12', '192.0.2.11');
    $g->union('198.51.100.6', '192.0.2.61');
    $g->union('10.99.0.2', '10.99.0.1');
    $g->add('203.0.113.9');

    expect($g->components())->toBe([
        '10.99.0.1' => ['10.99.0.1', '10.99.0.2'],
        '192.0.2.11' => ['192.0.2.11', '192.0.2.12', '192.0.2.61', '198.51.100.6'],
        '203.0.113.9' => ['203.0.113.9'],
    ])
        ->and($g->key('198.51.100.6'))->toBe('192.0.2.11')
        ->and($g->key('unknown'))->toBeNull()
        ->and($g->connected('192.0.2.11', '198.51.100.6'))->toBeTrue()
        ->and($g->connected('192.0.2.11', '10.99.0.1'))->toBeFalse()
        ->and($g->nodes()[0])->toBe('10.99.0.1');
});

it('derives roles from the evidence', function () {
    $g = new FabricGraph;
    $g->markVtep('192.0.2.11');
    $g->markSession('192.0.2.11');
    $g->markSession('192.0.2.1');
    $g->markVtep('192.0.2.62');
    $g->markGateway('192.0.2.62');
    $g->markBorder('192.0.2.62');
    $g->add('192.0.2.99');

    expect($g->role('192.0.2.11'))->toBe(FabricGraph::ROLE_LEAF)
        ->and($g->role('192.0.2.1'))->toBe(FabricGraph::ROLE_SPINE)
        ->and($g->role('192.0.2.62'))->toBe(FabricGraph::ROLE_GATEWAY)
        ->and($g->isBorder('192.0.2.62'))->toBeTrue()
        ->and($g->isBorder('192.0.2.11'))->toBeFalse()
        ->and($g->role('192.0.2.99'))->toBe(FabricGraph::ROLE_UNKNOWN)
        ->and($g->role('192.0.2.100'))->toBe(FabricGraph::ROLE_UNKNOWN)
        ->and($g->isVtep('192.0.2.11'))->toBeTrue();
});

it('orders addresses numerically with IPv4 first', function () {
    expect(FabricGraph::compare('192.0.2.9', '192.0.2.10'))->toBeLessThan(0)
        ->and(FabricGraph::compare('10.0.0.1', '9.0.0.1'))->toBeGreaterThan(0)
        ->and(FabricGraph::compare('192.0.2.1', '2001:db8::1'))->toBeLessThan(0)
        ->and(FabricGraph::compare('192.0.2.1', 'leaf1'))->toBeLessThan(0)
        ->and(FabricGraph::lowest(['192.0.2.61', '192.0.2.11', '192.0.2.100']))->toBe('192.0.2.11')
        ->and(FabricGraph::lowest([]))->toBeNull();
});

it('tests CIDR membership for v4 and v6', function () {
    expect(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('10.26.61.2', '10.26.61.0/30'))->toBeTrue()
        ->and(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('10.26.61.4', '10.26.61.0/30'))->toBeFalse()
        ->and(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('10.26.61.5', '10.26.61.4/31'))->toBeTrue()
        ->and(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('192.0.2.1', '192.0.2.0/24'))->toBeTrue()
        ->and(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('2001:db8::5', '2001:db8::/64'))->toBeTrue()
        ->and(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('2001:db9::5', '2001:db8::/32'))->toBeFalse()
        ->and(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('192.0.2.1', '2001:db8::/32'))->toBeFalse()
        ->and(\SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver::inCidr('bogus', '192.0.2.0/24'))->toBeFalse();

    $edge = new \SafferIt\LibrenmsNetconf\Fabric\UnderlayEdge(2, 26, '10.26.61.1', null, null, '10.26.61.2', '192.0.2.62', '10.26.61.0/30', 'ospf', 'full');
    expect($edge->key())->toBe('2:26|-:-|10.26.61.2')
        ->and($edge->toRow()['b_vtep_ip'])->toBe('192.0.2.62')
        ->and($edge->toRow()['wan'])->toBe(0);
});

it('pools the evidence of a device\'s alias addresses', function () {
    $g = new FabricGraph;
    // own VTEP from the source table, router-id from the neighbour table; a peer marks the router-id as VTEP
    $g->markVtep('192.0.2.61');
    $g->union('192.0.2.61', '192.0.2.11');
    $g->markSession('192.0.2.11');
    $g->markGateway('192.0.2.11');
    $g->markBorder('192.0.2.61');
    $g->add('192.0.2.99');

    expect($g->role('192.0.2.61'))->toBe(FabricGraph::ROLE_LEAF)
        ->and($g->role('192.0.2.11'))->toBe(FabricGraph::ROLE_GATEWAY);

    $g->mergeEvidence(['192.0.2.61', '192.0.2.11', '198.51.100.1']);   // unknown address ignored

    expect($g->role('192.0.2.61'))->toBe(FabricGraph::ROLE_GATEWAY)
        ->and($g->role('192.0.2.11'))->toBe(FabricGraph::ROLE_GATEWAY)
        ->and($g->isBorder('192.0.2.11'))->toBeTrue()
        ->and($g->isVtep('192.0.2.11'))->toBeTrue()
        ->and($g->has('198.51.100.1'))->toBeFalse()
        ->and($g->role('192.0.2.99'))->toBe(FabricGraph::ROLE_UNKNOWN);
});
