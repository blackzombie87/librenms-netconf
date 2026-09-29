<?php

use SafferIt\LibrenmsNetconf\Fabric\Trace\RoutedPath;

/**
 * Routed (inter-VNI) traces, plan §12.9 T6a. The shape is the owner's line with two
 * gateways hanging off it:
 *
 *   GW1 — BER2 — BER1        and        GW2 — RLG1 — BER2
 *
 * so a flow from BER1 to RLG1 can be routed on either gateway and the ranking has something
 * to choose between.
 *
 * @param  array<string, mixed>  $extra  applied to every edge, to swap the underlay protocol
 * @return list<array<string, mixed>>
 */
function gatewayFabric(array $extra = []): array
{
    return [
        ...lineFabric($extra),
        pathEdge('192.0.2.62', '192.0.2.1', $extra + ['a_port' => 'et-0/0/10', 'b_port' => 'et-0/0/11', 'link_key' => 'g1']),
        pathEdge('192.0.2.63', '192.0.2.2', $extra + ['a_port' => 'et-0/0/20', 'b_port' => 'et-0/0/21', 'link_key' => 'g2']),
    ];
}

it('routes through the gateway that has both IRBs in one L3 context, as two legs with their own VNIs', function () {
    $irbs = ['192.0.2.1' => [10 => irb('irb.10', portId: 501), 20 => irb('irb.20', portId: 502)]];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);

    expect($routed['gateway'])->toBe('192.0.2.1')
        ->and($routed['context'])->toBe('master')
        ->and($routed['reason'])->toBeNull()
        ->and($routed['irb_a']['port_id'])->toBe(501)
        ->and($routed['legs'])->toHaveCount(2)
        ->and($routed['legs'][0])->toMatchArray(['vni' => 10, 'from' => '192.0.2.61', 'to' => '192.0.2.1', 'pivot' => 'irb.10 → irb.20'])
        ->and($routed['legs'][1])->toMatchArray(['vni' => 20, 'from' => '192.0.2.1', 'to' => '192.0.2.63', 'pivot' => null])
        // BER1 → BER2 → GW1, then back out GW1 → BER2 → RLG1
        ->and(array_map(fn ($h) => $h['b'], $routed['legs'][0]['path']))->toBe(['192.0.2.62', '192.0.2.1'])
        ->and(array_map(fn ($h) => $h['b'], $routed['legs'][1]['path']))->toBe(['192.0.2.62', '192.0.2.63']);
});

it('refuses a gateway whose two IRBs sit in different L3 contexts, and says which', function () {
    // the failure this class exists for: two IRBs on one box is not the same as two IRBs
    // that can reach each other
    $irbs = ['192.0.2.1' => [10 => irb('irb.10', context: 'VRF-A'), 20 => irb('irb.20', context: 'VRF-B')]];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);

    expect($routed['gateway'])->toBeNull()
        ->and($routed['legs'])->toBe([])
        ->and($routed['reason'])->toBe('192.0.2.1 has an IRB in both, but in different L3 contexts (VRF-A / VRF-B)');
});

it('says nothing routes between two VNIs when no member has an IRB in both', function () {
    $irbs = ['192.0.2.1' => [10 => irb('irb.10')], '192.0.2.2' => [20 => irb('irb.20')]];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);

    expect($routed['gateway'])->toBeNull()
        ->and($routed['reason'])->toBe('no member has an IRB in both VNI 10 and VNI 20');
});

it('prefers the gateway the two legs together are closest to', function () {
    $irbs = [
        '192.0.2.2' => [10 => irb('irb.10'), 20 => irb('irb.20')],   // BER1 → 3 hops, 2 back to BER2
        '192.0.2.1' => [10 => irb('irb.10'), 20 => irb('irb.20')],   // BER1 → 2 hops, 1 back to BER2
    ];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.62', 20);

    expect($routed['gateway'])->toBe('192.0.2.1')
        ->and(array_column($routed['candidates'], 'hops'))->toBe([3, 5]);
});

it('breaks a tie on hops by address, so the same question gets the same answer twice', function () {
    // both gateways are four hops away in total on this line; nothing about the fabric
    // prefers one, so the tie-break has to be something stable rather than row order
    $irbs = [
        '192.0.2.2' => [10 => irb('irb.10'), 20 => irb('irb.20')],
        '192.0.2.1' => [10 => irb('irb.10'), 20 => irb('irb.20')],
    ];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);

    expect(array_column($routed['candidates'], 'hops'))->toBe([4, 4])
        ->and(array_column($routed['candidates'], 'address'))->toBe(['192.0.2.1', '192.0.2.2']);
});

it('ranks a gateway with a down IRB below one that is up', function () {
    $irbs = [
        '192.0.2.1' => [10 => irb('irb.10'), 20 => irb('irb.20', status: 'Down')],
        '192.0.2.2' => [10 => irb('irb.10'), 20 => irb('irb.20')],
    ];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);

    expect($routed['gateway'])->toBe('192.0.2.2')
        ->and($routed['candidates'][0]['up'])->toBeTrue()
        ->and($routed['candidates'][1]['up'])->toBeFalse();
});

it('treats an unpolled L3 context as unknown, ranks it last and labels it', function () {
    // a release that has not re-polled yet has the column empty: that is not a match and it
    // is not a refusal either
    $irbs = [
        '192.0.2.1' => [10 => irb('irb.10', context: null), 20 => irb('irb.20', context: null)],
        '192.0.2.2' => [10 => irb('irb.10'), 20 => irb('irb.20')],
    ];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);

    expect($routed['gateway'])->toBe('192.0.2.2')
        ->and($routed['candidates'][1])->toMatchArray([
            'address' => '192.0.2.1',
            'context' => null,
            'reason' => 'the L3 context of these IRBs has not been polled yet',
        ]);

    // and on its own it is still usable, with the caveat carried
    $alone = RoutedPath::through(['192.0.2.1' => $irbs['192.0.2.1']], gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);
    expect($alone['gateway'])->toBe('192.0.2.1')->and($alone['context'])->toBeNull();
});

it('keeps a gateway with no stored underlay to it, ranked last rather than dropped', function () {
    $irbs = [
        '192.0.2.9' => [10 => irb('irb.10'), 20 => irb('irb.20')],   // no edge reaches it
        '192.0.2.1' => [10 => irb('irb.10'), 20 => irb('irb.20')],
    ];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);

    expect($routed['gateway'])->toBe('192.0.2.1')
        ->and($routed['candidates'][1])->toMatchArray(['address' => '192.0.2.9', 'hops' => null]);
});

it('routes on the leaf itself when that leaf owns both IRBs, with no leg to walk', function () {
    // an ERB leaf: both endpoints hang off it and it has both IRBs, so nothing is encapsulated
    $irbs = ['192.0.2.61' => [10 => irb('irb.10'), 20 => irb('irb.20')]];

    $routed = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.61', 20);

    expect($routed['gateway'])->toBe('192.0.2.61')
        ->and($routed['legs'][0]['path'])->toBe([])
        ->and($routed['legs'][1]['path'])->toBe([])
        ->and($routed['candidates'][0]['hops'])->toBe(0);
});

it('picks the same gateway on a pure eBGP underlay as on the OSPF one', function () {
    // plan §12.4a and §12.8 (5): the routed engine must not read the protocol either
    $irbs = ['192.0.2.1' => [10 => irb('irb.10'), 20 => irb('irb.20')]];
    $shape = fn (array $routed) => [
        $routed['gateway'],
        array_map(fn ($leg) => array_map(fn ($h) => [$h['a'], $h['b']], $leg['path']), $routed['legs']),
    ];

    $ospf = RoutedPath::through($irbs, gatewayFabric(), '192.0.2.61', 10, '192.0.2.63', 20);
    $bgp = RoutedPath::through($irbs, gatewayFabric(['protocol' => 'bgp', 'state' => 'Established']), '192.0.2.61', 10, '192.0.2.63', 20);

    expect($shape($bgp))->toBe($shape($ospf))
        ->and($bgp['legs'][0]['path'][0]['protocol'])->toBe('bgp');
});
