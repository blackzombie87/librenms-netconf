<?php

use SafferIt\LibrenmsNetconf\Fabric\View\EagleLayout;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricShape;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function eagleNode(string $ip, string $role = 'leaf', array $extra = []): array
{
    return $extra + [
        'ip' => $ip, 'name' => 'node-' . $ip, 'role' => $role, 'device_id' => 1, 'border' => false,
        'site' => null, 'status' => true, 'collected' => true, 'version' => '23.4R2', 'version_skew' => false,
        'irbs' => 0, 'esi_degraded' => 0,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function eagleLink(string $a, ?string $b, array $extra = []): array
{
    return $extra + [
        'link_key' => $a . '|' . ($b ?? '-'), 'a' => $a, 'b' => $b, 'b_label' => null,
        'protocol' => 'ospf', 'state' => 'Full', 'up' => true, 'lldp' => false, 'wan' => false,
        'a_port' => 'et-0/0/1', 'b_port' => 'et-0/0/2', 'a_port_id' => 1, 'b_port_id' => 2, 'network' => '10.1.1.0/31',
    ];
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @return array<string, mixed>
 */
function eagleShape(array $nodes, string $underlay = FabricShape::UNDERLAY_LEAF_MESH, string $routing = FabricShape::ROUTING_NONE): array
{
    $tier = [];
    foreach ($nodes as $n) {
        $tier[$n['ip']] = match ($n['role']) {
            'spine' => FabricShape::TIER_SPINE,
            'gateway' => $routing === FabricShape::ROUTING_CRB ? FabricShape::TIER_GATEWAY : FabricShape::TIER_LEAF,
            default => FabricShape::TIER_LEAF,
        };
    }

    return ['underlay' => $underlay, 'routing' => $routing, 'overlay' => FabricShape::OVERLAY_FULL, 'evidence' => 'evidence.', 'badge' => 'b', 'tier' => $tier, 'shared_far_ends' => [], 'faults' => [], 'overlay_edges' => [], 'counts' => []];
}

/**
 * The real production shape (plan §11 E-F8/E-F9): 14 members in 7 compounds of
 * 4 / 2 / 2 / 2 / 2 / 1 / 1, the two gateways alone in their verbose facility locations.
 *
 * @return list<array<string, mixed>>
 */
function productionNodes(): array
{
    $sites = ['BER1', 'BER1', 'BER1', 'BER1', 'BER2', 'BER2', 'BER4', 'BER4', 'HRO1', 'HRO1', 'RLG1', 'RLG1'];
    $nodes = [];
    foreach ($sites as $i => $site) {
        $nodes[] = eagleNode('10.0.0.' . (11 + $i), 'leaf', ['site' => $site, 'device_id' => 11 + $i]);
    }
    $nodes[] = eagleNode('10.0.0.1', 'gateway', ['site' => 'NTT/e-shelter Berlin - RZ BER1', 'device_id' => 1, 'irbs' => 57, 'border' => true]);
    $nodes[] = eagleNode('10.0.0.2', 'gateway', ['site' => 'IPB/CarrierColo Rechenzentrum Berlin - RZ BER2', 'device_id' => 2, 'irbs' => 57, 'border' => true]);

    return $nodes;
}

it('draws the production shape: 7 compounds of 4/2/2/2/2/1/1 and the gateways on their own tier', function () {
    $nodes = productionNodes();
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_LEAF_MESH, FabricShape::ROUTING_CRB);
    $out = EagleLayout::place($shape, $nodes, [], [], [], [], []);

    $sizes = array_map(fn ($g) => count($g['members']), $out['groups']);
    rsort($sizes);
    $gatewayY = $out['nodes']['10.0.0.1']['y'];
    $leafY = $out['nodes']['10.0.0.11']['y'];

    // every card sits inside the picture's own viewBox, not inside the target width
    $rightmost = max(array_map(fn ($n) => $n['x'] + $n['w'], $out['nodes']));

    expect($out['width'])->toBeLessThanOrEqual(EagleLayout::TARGET_WIDTH)
        ->and($rightmost)->toBeLessThanOrEqual($out['width'])
        ->and($sizes)->toBe([4, 2, 2, 2, 2, 1, 1])
        ->and($out['nodes']['10.0.0.1']['tier'])->toBe(FabricShape::TIER_GATEWAY)
        ->and($gatewayY)->toBeLessThan($leafY)
        ->and($out['counts']['cards'])->toBe(14)
        // no overlay edge was passed, so none is drawn: a healthy mesh is a sentence
        ->and(array_filter($out['edges'], fn ($e) => $e['layer'] === 'overlay'))->toBe([])
        // and every compound has a header box an edge can land on, expanded or not
        ->and(array_filter($out['groups'], fn ($g) => ! isset($g['header']['w'])))->toBe([]);
});

it('wraps 26 members in 6 sites into more than one site row and stays inside the target width', function () {
    $nodes = [];
    for ($i = 0; $i < 26; $i++) {
        $nodes[] = eagleNode('10.0.' . intdiv($i, 5) . '.' . (10 + $i), 'leaf', ['site' => 'site-' . intdiv($i, 5), 'device_id' => 100 + $i]);
    }
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], []);

    $rows = array_unique(array_map(fn ($g) => $g['y'], $out['groups']));

    expect($out['width'])->toBeLessThanOrEqual(EagleLayout::TARGET_WIDTH)
        ->and(max(array_map(fn ($n) => $n['x'] + $n['w'], $out['nodes'])))->toBeLessThanOrEqual($out['width'])
        ->and(count($out['groups']))->toBe(6)
        ->and(count($rows))->toBeGreaterThan(1)
        ->and($out['counts']['cards'])->toBe(26);
});

it('is deterministic', function () {
    $nodes = productionNodes();
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_LEAF_MESH, FabricShape::ROUTING_CRB);
    $links = [eagleLink('10.0.0.11', '10.0.0.12')];

    expect(EagleLayout::place($shape, $nodes, $links, [], [], [], []))
        ->toEqual(EagleLayout::place($shape, $nodes, $links, [], [], [], []));
});

it('draws no bracket for gateway segments and one for a real AE pair', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A', 'esi_degraded' => 2]), eagleNode('10.0.0.12', 'leaf', ['site' => 'A'])];
    // the loader has already dropped the 53 shared 05: segments; only the LAG pair arrives
    $pairs = [['a' => '10.0.0.11', 'b' => '10.0.0.12', 'esis' => 4, 'degraded' => 2, 'id' => '10.0.0.11|10.0.0.12']];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], $pairs, []);

    $esi = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'esi'));
    expect($esi)->toHaveCount(1)
        ->and($esi[0]['shape'])->toBe('bracket')
        ->and($esi[0]['label'])->toBe('4 ESIs, 2 degraded')
        ->and($out['nodes']['10.0.0.11']['chips'][0]['text'])->toBe('2 ESI')
        // and with no pair at all there is no bracket
        ->and(array_filter(EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], [])['edges'], fn ($e) => $e['layer'] === 'esi'))->toBe([]);
});

it('draws every overlay edge it is given and never reclassifies one', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'B'])];
    $overlay = [
        ['kind' => 'symmetric', 'a' => '10.0.0.11', 'b' => '10.0.0.12', 'id' => 's'],
        ['kind' => 'asymmetric', 'a' => '10.0.0.11', 'b' => '10.0.0.12', 'id' => 'a'],
        ['kind' => 'missing', 'a' => '10.0.0.11', 'b' => '10.0.0.12', 'id' => 'm'],
    ];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], $overlay, [], [], []);

    $drawn = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'overlay'));
    expect($drawn)->toHaveCount(3)
        ->and(array_column($drawn, 'kind'))->toBe(['overlay', 'asymmetric', 'missing'])
        // a fault reads as a fault without colour, too
        ->and($drawn[1]['dash'])->not->toBe($drawn[0]['dash'])
        ->and($drawn[1]['stroke'])->toBe('#d9534f');
});

it('gives every edge state its own dash pattern, not only its own colour', function () {
    $up = EagleLayout::edgeStyle('underlay', true);
    $down = EagleLayout::edgeStyle('underlay', false);
    $unknown = EagleLayout::edgeStyle('underlay', null);
    $wan = EagleLayout::edgeStyle('wan', true);
    $cross = EagleLayout::edgeStyle('cross-site', true);

    expect([$up['dash'], $down['dash'], $unknown['dash'], $wan['dash']])->toBe(['', '5 3', '1 4', '7 3'])
        ->and(count(array_unique([$up['dash'], $down['dash'], $unknown['dash'], $wan['dash']])))->toBe(4)
        ->and($cross['stroke'])->toBe('#3d5a80')     // cross-site is solid and not the WAN dash
        ->and($cross['dash'])->toBe('')
        ->and($up['state'])->toBe('up')
        ->and($unknown['state'])->toBe('unknown');   // an `ip` edge is unknown, never down
});

it('trunks a site only when every leaf has an up session of the same protocol set', function () {
    $spine = eagleNode('10.0.0.1', 'spine', ['device_id' => 1]);
    $leaves = [];
    for ($i = 0; $i < 5; $i++) {
        $leaves[] = eagleNode('10.0.0.1' . $i, 'leaf', ['site' => 'A', 'device_id' => 10 + $i]);
    }
    $nodes = [$spine, ...$leaves];
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_SPINE_LEAF);

    $uniform = array_map(fn ($n) => eagleLink('10.0.0.1', $n['ip']), $leaves);
    $trunk = EagleLayout::place($shape, $nodes, $uniform, [], [], [], []);
    expect(array_filter($trunk['edges'], fn ($e) => $e['kind'] === 'trunk'))->toHaveCount(1)
        ->and(array_filter($trunk['edges'], fn ($e) => $e['kind'] === 'underlay'))->toBe([]);

    // one down session: five lines and no trunk, so the broken one stays visible
    $oneDown = $uniform;
    $oneDown[4] = eagleLink('10.0.0.1', $leaves[4]['ip'], ['state' => 'Init', 'up' => false]);
    $noTrunk = EagleLayout::place($shape, $nodes, $oneDown, [], [], [], []);
    expect(array_filter($noTrunk['edges'], fn ($e) => $e['kind'] === 'trunk'))->toBe([])
        ->and(array_filter($noTrunk['edges'], fn ($e) => $e['layer'] === 'underlay'))->toHaveCount(5);
});

it('does not trunk a BGP-only leaf in with four that also run OSPF', function () {
    // plan §12.4a: the underlay protocol is read off the row. `bgp` is not `bgp,ospf`
    $spine = eagleNode('10.0.0.1', 'spine', ['device_id' => 1]);
    $leaves = [];
    for ($i = 0; $i < 5; $i++) {
        $leaves[] = eagleNode('10.0.0.1' . $i, 'leaf', ['site' => 'A', 'device_id' => 10 + $i]);
    }
    $nodes = [$spine, ...$leaves];
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_SPINE_LEAF);

    // a pure eBGP site (RFC 7938) trunks exactly like the OSPF one
    $pureBgp = array_map(fn ($n) => eagleLink('10.0.0.1', $n['ip'], ['protocol' => 'bgp', 'state' => 'Established']), $leaves);
    expect(array_filter(EagleLayout::place($shape, $nodes, $pureBgp, [], [], [], [])['edges'], fn ($e) => $e['kind'] === 'trunk'))->toHaveCount(1);

    $mixed = $pureBgp;
    $mixed[4] = eagleLink('10.0.0.1', $leaves[4]['ip'], ['protocol' => 'bgp,ospf', 'state' => 'Established/Full']);
    $out = EagleLayout::place($shape, $nodes, $mixed, [], [], [], []);
    expect(array_filter($out['edges'], fn ($e) => $e['kind'] === 'trunk'))->toBe([])
        ->and(array_filter($out['edges'], fn ($e) => $e['layer'] === 'underlay'))->toHaveCount(5)
        ->and(EagleLayout::protocolSet('ospf,bgp'))->toBe('bgp,ospf')
        ->and(EagleLayout::protocolSet('bgp'))->not->toBe(EagleLayout::protocolSet('bgp,ospf'))
        ->and(EagleLayout::protocolSet('lldp-only'))->toBe('');
});

it('puts an unmonitored far end on the spine tier and an outside session nowhere unless asked', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'A'])];
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_SPINE_LEAF);
    $shape['shared_far_ends'] = ['10.9.9.1'];
    $links = [
        eagleLink('10.0.0.11', null, ['b_label' => '10.9.9.1', 'link_key' => 'shared-a']),
        eagleLink('10.0.0.12', null, ['b_label' => '10.9.9.1', 'link_key' => 'shared-b']),
        eagleLink('10.0.0.11', null, ['b_label' => '193.178.185.1', 'wan' => true, 'link_key' => 'transit']),
    ];

    $off = EagleLayout::place($shape, $nodes, $links, [], ['10.9.9.1'], [], []);
    expect($off['nodes'])->toHaveKey('far:10.9.9.1')
        ->and(array_filter($off['nodes'], fn ($n) => $n['kind'] === 'outside'))->toBe([])
        ->and($off['counts']['outside'])->toBe(1)
        ->and(implode(' ', $off['sentences']))->toContain('1 session out of the fabric')
        ->and(implode(' ', $off['sentences']))->toContain('not drawn');

    $on = EagleLayout::place($shape, $nodes, $links, [], ['10.9.9.1'], [], ['outside' => true]);
    expect(array_filter($on['nodes'], fn ($n) => $n['kind'] === 'outside'))->toHaveCount(1);
});

it('collapses a site into one summary card that carries the worst state and the counts', function () {
    $nodes = [
        eagleNode('10.0.0.11', 'leaf', ['site' => 'A', 'device_id' => 11, 'esi_degraded' => 1]),
        eagleNode('10.0.0.12', 'leaf', ['site' => 'A', 'device_id' => 12]),
        eagleNode('10.0.0.21', 'leaf', ['site' => 'B', 'device_id' => 21]),
    ];
    $links = [
        eagleLink('10.0.0.11', '10.0.0.12', ['state' => 'Init', 'up' => false, 'link_key' => 'inside']),
        eagleLink('10.0.0.11', '10.0.0.21', ['link_key' => 'across']),
        eagleLink('10.0.0.11', null, ['b_label' => '198.51.100.1', 'wan' => true, 'link_key' => 'transit']),
    ];
    $pairs = [['a' => '10.0.0.11', 'b' => '10.0.0.21', 'esis' => 1, 'degraded' => 0, 'id' => 'p']];
    $attached = [['key' => 'k1', 'esi' => '01:aa', 'label' => 'server-a', 'device_id' => 5, 'anchor' => '10.0.0.11']];

    $out = EagleLayout::place(eagleShape($nodes), $nodes, $links, [], [], $pairs, ['collapse' => ['site:A'], 'outside' => true, 'attached' => $attached]);
    $groupA = array_values(array_filter($out['groups'], fn ($g) => $g['key'] === 'site:A'))[0];

    expect($groupA['collapsed'])->toBeTrue()
        ->and($groupA['state'])->toBe('down')                 // the hidden session is down
        ->and($out['nodes']['site:A']['stroke'])->toBe('#d9534f')
        ->and($groupA['summary'])->toBe(['2 members', '1 degraded ESI', '1 outside', '1 attached'])
        ->and($out['nodes'])->not->toHaveKey('10.0.0.11')     // no card at a coordinate that is gone
        ->and($out['nodes'])->not->toHaveKey('attached:k1')   // nothing hangs off a collapsed site
        ->and(array_filter($out['nodes'], fn ($n) => $n['kind'] === 'outside'))->toBe([])
        // the cross-site link now lands on the compound header, and the ESI pair is a segment
        ->and(array_values(array_filter($out['edges'], fn ($e) => $e['id'] === 'edge:underlay:across')))->toHaveCount(1)
        ->and(array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'esi'))[0]['shape'])->toBe('segment')
        // and the internal edge is not drawn twice from the header to itself
        ->and(array_filter($out['edges'], fn ($e) => $e['id'] === 'edge:underlay:inside'))->toBe([]);
});

it('says nothing about attached devices while the layer is off', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'A'])];
    $links = [eagleLink('10.0.0.11', null, ['b_label' => '198.51.100.1', 'wan' => true, 'link_key' => 't'])];

    $out = EagleLayout::place(eagleShape($nodes), $nodes, $links, [], [], [], ['collapse' => ['site:A'], 'outside' => true, 'attached' => null]);
    $group = $out['groups'][0];

    // 0 attached would claim the two neighbour queries ran and found none
    expect($group['attached'])->toBeNull()
        ->and($group['summary'])->toBe(['2 members', '1 outside'])
        ->and(implode(' ', $out['sentences']))->not->toContain('attached');
});

it('caps the attached devices drawn under one member and keeps the rest as a card', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A'])];
    $attached = [];
    for ($i = 0; $i < 9; $i++) {
        $attached[] = ['key' => 'k' . $i, 'esi' => '01:aa', 'label' => 'server-' . $i, 'device_id' => null, 'anchor' => '10.0.0.11'];
    }
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], ['attached' => $attached]);

    $drawn = array_filter($out['nodes'], fn ($n) => $n['kind'] === 'attached');
    expect($drawn)->toHaveCount(EagleLayout::ATTACHED_PER_ESI + 1)
        ->and($out['nodes']['attached:10.0.0.11:more']['name'])->toBe('+1 more')
        ->and(array_filter($out['edges'], fn ($e) => $e['layer'] === 'attached'))->toHaveCount(EagleLayout::ATTACHED_PER_ESI + 1);
});

it('strokes a card by the worst of not-monitored, down and not-collected', function () {
    $nodes = [
        eagleNode('10.0.0.11', 'leaf', ['device_id' => null]),
        eagleNode('10.0.0.12', 'leaf', ['status' => false]),
        eagleNode('10.0.0.13', 'leaf', ['collected' => false]),
        eagleNode('10.0.0.14', 'leaf', ['version' => '18.4R3-S8.7', 'version_skew' => true]),
    ];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], []);

    expect($out['nodes']['10.0.0.11']['stroke'])->toBe('#999999')
        ->and($out['nodes']['10.0.0.11']['dashed'])->toBeTrue()
        ->and($out['nodes']['10.0.0.12']['stroke'])->toBe('#d9534f')
        ->and($out['nodes']['10.0.0.13']['stroke'])->toBe('#f0ad4e')
        ->and($out['nodes']['10.0.0.13']['chips'][0]['text'])->toBe('no EVPN data')
        ->and($out['nodes']['10.0.0.14']['chips'][0]['text'])->toBe('18.4R3-S8.7')
        ->and($out['nodes']['10.0.0.14']['title'])->toContain('18.4R3-S8.7');
});

it('strokes the nodes and edges a trace names, and nothing else', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'A'])];
    $links = [eagleLink('10.0.0.11', '10.0.0.12', ['link_key' => 'k1'])];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, $links, [], [], [], [], ['nodes' => ['10.0.0.11'], 'edges' => ['edge:underlay:k1']]);

    expect($out['nodes']['10.0.0.11']['highlight'])->toBeTrue()
        ->and($out['nodes']['10.0.0.12']['highlight'])->toBeFalse()
        ->and($out['edges'][0]['highlight'])->toBeTrue()
        ->and(EagleLayout::place(eagleShape($nodes), $nodes, $links, [], [], [], [])['edges'][0]['highlight'])->toBeFalse();
});

it('keeps a single-member compound and a null-location compound deliberate rather than broken', function () {
    // plan §11 E-F8: the two MX204s carry verbose facility strings, so each sits alone
    $nodes = [
        eagleNode('10.0.0.11', 'leaf', ['site' => 'RZ BER1 - a very long facility name']),
        eagleNode('10.0.0.12', 'leaf', ['site' => null]),
    ];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], []);

    expect($out['groups'])->toHaveCount(2)
        ->and($out['groups'][0]['label'])->toBe('RZ BER1 - a very long facility name')
        ->and($out['groups'][1]['label'])->toBeNull()
        ->and($out['groups'][1]['key'])->toBe('pair:10.0.0.12')
        ->and($out['groups'][0]['header']['h'])->toBe(EagleLayout::SITE_HEADER);
});
