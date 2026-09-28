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
        ->and($drawn[1]['strokeClass'])->toBe('eg-stroke-fault');
});

it('gives every edge state its own dash pattern, not only its own colour class', function () {
    $up = EagleLayout::edgeStyle('underlay', true);
    $down = EagleLayout::edgeStyle('underlay', false);
    $unknown = EagleLayout::edgeStyle('underlay', null);
    $wan = EagleLayout::edgeStyle('wan', true);
    $cross = EagleLayout::edgeStyle('cross-site', true);

    expect([$up['dash'], $down['dash'], $unknown['dash'], $wan['dash']])->toBe(['', '5 3', '1 4', '7 3'])
        ->and(count(array_unique([$up['dash'], $down['dash'], $unknown['dash'], $wan['dash']])))->toBe(4)
        // the colour is a class, so the dark theme can reach it; the dash is not theme
        ->and($cross['strokeClass'])->toBe('eg-stroke-cross')
        ->and($up['strokeClass'])->toBe('eg-stroke-up')
        ->and($wan['strokeClass'])->toBe('eg-stroke-wan')
        ->and($cross['dash'])->toBe('')
        ->and($up['state'])->toBe('up')
        ->and($unknown['state'])->toBe('unknown');   // an `ip` edge is unknown, never down
});

it('trunks the healthy sessions of a site and draws the odd one beside them', function () {
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

    // one down session: a trunk of the four that are up plus one line for the broken one. Five
    // lines and no trunk was the old rule, and it exploded the pod into a fan that crossed the
    // trunks of the pods beside it
    $oneDown = $uniform;
    $oneDown[4] = eagleLink('10.0.0.1', $leaves[4]['ip'], ['state' => 'Init', 'up' => false]);
    $noTrunk = EagleLayout::place($shape, $nodes, $oneDown, [], [], [], []);
    $trunks = array_values(array_filter($noTrunk['edges'], fn ($e) => $e['kind'] === 'trunk'));
    $lines = array_values(array_filter($noTrunk['edges'], fn ($e) => $e['layer'] === 'underlay' && $e['kind'] !== 'trunk'));

    expect($trunks)->toHaveCount(1)
        ->and($trunks[0]['label'])->toBe('4 up')
        ->and($lines)->toHaveCount(1)
        // the exception is underlay, not cross-site: the spine has no compound to be across from
        ->and($lines[0]['kind'])->toBe('underlay')
        ->and($lines[0]['up'])->toBeFalse();
});

it('trunks the four bgp sessions and leaves the one that also runs OSPF beside them', function () {
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

    // four `bgp` links and one `bgp,ospf`: the largest up set is the four, and the odd session
    // is its own line. `bgp` and `bgp,ospf` are still different sets and are never merged
    $mixed = $pureBgp;
    $mixed[4] = eagleLink('10.0.0.1', $leaves[4]['ip'], ['protocol' => 'bgp,ospf', 'state' => 'Established/Full']);
    $out = EagleLayout::place($shape, $nodes, $mixed, [], [], [], []);
    $trunks = array_values(array_filter($out['edges'], fn ($e) => $e['kind'] === 'trunk'));
    $lines = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'underlay' && $e['kind'] !== 'trunk'));

    expect($trunks)->toHaveCount(1)
        ->and($trunks[0]['label'])->toBe('4 up')
        ->and($trunks[0]['title'])->toContain('bgp session')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['protocol'])->toBe('bgp,ospf')
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
        ->and($out['nodes']['site:A']['strokeClass'])->toBe('eg-card-down')
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

    expect($out['nodes']['10.0.0.11']['strokeClass'])->toBe('eg-card-unmonitored')
        ->and($out['nodes']['10.0.0.11']['dashed'])->toBeTrue()
        ->and($out['nodes']['10.0.0.12']['strokeClass'])->toBe('eg-card-down')
        ->and($out['nodes']['10.0.0.13']['strokeClass'])->toBe('eg-card-stale')
        // the fill still follows the stored role, not the tier
        ->and($out['nodes']['10.0.0.12']['fillClass'])->toBe('eg-leaf')
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

it('cuts a compound caption to its own box, and keeps the full location for the title', function () {
    // the two real gateway locations are 204 px and 304 px of 11 px type in a 176 px box
    $long = 'IPB/CarrierColo Rechenzentrum Berlin - RZ BER2';
    $nodes = [eagleNode('10.0.0.1', 'leaf', ['site' => $long]), eagleNode('10.0.0.2', 'leaf', ['site' => 'BER1'])];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], []);
    $captions = array_column($out['groups'], 'caption', 'label');

    $fits = fn (string $caption, int $boxWidth) => mb_strlen($caption) * EagleLayout::LABEL_CHAR_W <= $boxWidth;

    expect($captions[$long])->toEndWith('… (1)')
        ->and($captions[$long])->not->toBe($long . ' (1)')
        ->and($fits($captions[$long], $out['groups'][0]['w']))->toBeTrue()
        // a caption that already fits is left alone
        ->and($captions['BER1'])->toBe('BER1 (1)')
        // and the untouched location is still on the group, for the title
        ->and($out['groups'][0]['label'])->toBe($long)
        ->and(EagleLayout::fitLabel(null, 3, 400))->toBe('no location (3)');
});

it('reserves the strip an ESI bracket is drawn in, so the mark stays inside its compound', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'A'])];
    $pairs = [['a' => '10.0.0.11', 'b' => '10.0.0.12', 'esis' => 1, 'degraded' => 0, 'id' => 'p']];

    $without = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], [])['groups'][0];
    $with = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], $pairs, [])['groups'][0];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], $pairs, []);
    $bracket = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'esi'))[0];

    expect($with['h'] - $without['h'])->toBe(EagleLayout::ESI_BAND)
        ->and($with['bracket'])->toBeTrue()
        ->and($without['bracket'])->toBeFalse()
        // the bracket and its label sit inside the box the site reserved
        ->and($bracket['ly'] + 3)->toBeLessThanOrEqual($with['y'] + $with['h'])
        // a pair whose ends are in different sites does not widen either box
        ->and(EagleLayout::place(
            eagleShape([eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'B'])]),
            [eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'B'])],
            [], [], [], $pairs, [],
        )['groups'][0]['bracket'])->toBeFalse();
});

it('draws a segment, not a bracket, for an ESI pair that crosses from one site to the next', function () {
    // two sites of two members each — the production shape. Both put their members at local
    // row 0, columns 0 and 1, so a pair from A's right-hand card to B's left-hand card passes
    // a row/column adjacency test that does not also ask whether the two share a site.
    $nodes = [
        eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'A']),
        eagleNode('10.0.0.13', 'leaf', ['site' => 'B']), eagleNode('10.0.0.14', 'leaf', ['site' => 'B']),
    ];
    $pairs = [['a' => '10.0.0.12', 'b' => '10.0.0.13', 'esis' => 1, 'degraded' => 0, 'id' => 'split']];

    $bare = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], []);
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], $pairs, []);
    $edge = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'esi'))[0];
    $boxes = fn (array $o) => array_column($o['groups'], 'h', 'key');

    // every vertex of the routed path, as pairs
    preg_match_all('/(-?\d+) (-?\d+)/', $edge['path'], $m, PREG_SET_ORDER);
    $points = array_map(fn ($p) => [(int) $p[1], (int) $p[2]], $m);

    expect($edge['shape'])->toBe('segment')
        // not the four-point bracket, and not a diagonal either: it takes the channel between
        // the two compounds, so every segment is horizontal or vertical
        ->and(count($points))->toBeGreaterThan(1);
    for ($i = 1; $i < count($points); $i++) {
        expect($points[$i][0] === $points[$i - 1][0] || $points[$i][1] === $points[$i - 1][1])->toBeTrue();
    }
    // and no box grew, on either side, which is the other half of the 1.4.1 claim
    expect($boxes($out))->toBe($boxes($bare))
        ->and(array_column($out['groups'], 'bracket'))->toBe([false, false]);
});

it('never draws a bracket a compound has not reserved its band for', function () {
    // the invariant the two predicates have to keep: every drawn bracket has both ends in one
    // site, and that site reserved ESI_BAND for it. Same-site pairs, a split pair and a pair
    // the wrap separates onto two rows, all in one picture.
    $nodes = productionNodes();
    $pairs = [
        ['a' => '10.0.0.11', 'b' => '10.0.0.12', 'esis' => 2, 'degraded' => 0, 'id' => 'ber1-a'],
        ['a' => '10.0.0.13', 'b' => '10.0.0.14', 'esis' => 2, 'degraded' => 1, 'id' => 'ber1-b'],
        ['a' => '10.0.0.15', 'b' => '10.0.0.16', 'esis' => 1, 'degraded' => 0, 'id' => 'ber2'],
        ['a' => '10.0.0.16', 'b' => '10.0.0.17', 'esis' => 1, 'degraded' => 0, 'id' => 'ber2-ber4'],
        ['a' => '10.0.0.22', 'b' => '10.0.0.11', 'esis' => 1, 'degraded' => 0, 'id' => 'rlg1-ber1'],
    ];
    $out = EagleLayout::place(eagleShape($nodes, FabricShape::UNDERLAY_LEAF_MESH, FabricShape::ROUTING_CRB), $nodes, [], [], [], $pairs, []);

    $siteOf = [];
    foreach ($out['groups'] as $g) {
        foreach ($g['members'] as $ip) {
            $siteOf[$ip] = $g;
        }
    }
    $brackets = array_values(array_filter($out['edges'], fn ($e) => ($e['shape'] ?? '') === 'bracket'));
    $esis = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'esi'));

    expect($esis)->toHaveCount(5);
    foreach ($esis as $e) {
        $sameSite = ($siteOf[$e['a']]['key'] ?? 'a') === ($siteOf[$e['b']]['key'] ?? 'b');
        expect($sameSite || $e['shape'] === 'segment')->toBeTrue();
    }
    foreach ($brackets as $e) {
        $site = $siteOf[$e['a']] ?? null;
        expect($site)->not->toBeNull()
            ->and($site['key'])->toBe($siteOf[$e['b']]['key'])
            ->and($site['bracket'])->toBeTrue()
            // and the mark it reserved that band for stays inside the box
            ->and($e['ly'] + 3)->toBeLessThanOrEqual($site['y'] + $site['h']);
    }
    // the two cross-site pairs are drawn, and drawn as segments
    expect(count($brackets))->toBe(3);
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
        ->and($out['groups'][1]['key'])->toBe('noloc')
        ->and($out['groups'][0]['header']['h'])->toBe(EagleLayout::SITE_HEADER);
});

it('draws a spine-to-leaf link as underlay even when the two locations differ', function () {
    // the Clos bug: `kind` was decided from the site key alone, and a spine has no compound at
    // all, so every monitored spine-to-leaf link came out cross-site blue
    $nodes = [
        eagleNode('10.0.0.1', 'spine', ['site' => 'CLOS-CORE', 'device_id' => 1]),
        eagleNode('10.0.0.11', 'leaf', ['site' => 'POD-A', 'device_id' => 11]),
    ];
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_SPINE_LEAF);
    $out = EagleLayout::place($shape, $nodes, [eagleLink('10.0.0.1', '10.0.0.11')], [], [], [], []);
    $edge = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'underlay'))[0];

    // a site of one member is never trunked, so this is the single line, and it is green
    expect($edge['kind'])->toBe('underlay')
        ->and($edge['strokeClass'])->toBe('eg-stroke-up');
});

it('gives every null location in a tier one compound, not one each', function () {
    $nodes = [
        eagleNode('10.0.0.11', 'leaf', ['site' => null, 'device_id' => 11]),
        eagleNode('10.0.0.12', 'leaf', ['site' => null, 'device_id' => 12]),
        eagleNode('10.0.0.13', 'leaf', ['site' => null, 'device_id' => 13]),
        eagleNode('10.0.0.21', 'leaf', ['site' => 'BER1', 'device_id' => 21]),
    ];
    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [], []);
    $noloc = array_values(array_filter($out['groups'], fn ($g) => $g['label'] === null));

    expect($out['groups'])->toHaveCount(2)
        ->and($noloc)->toHaveCount(1)
        ->and($noloc[0]['key'])->toBe('noloc')
        ->and($noloc[0]['members'])->toHaveCount(3)
        ->and($noloc[0]['caption'])->toBe('no location (3)')
        // a member that does have a location is not pulled into it
        ->and(array_values(array_filter($out['groups'], fn ($g) => $g['label'] === 'BER1'))[0]['members'])->toBe(['10.0.0.21']);
});

it('grows a one-member gateway compound to its caption, up to the cap', function () {
    $long = 'IPB/CarrierColo Rechenzentrum Berlin - RZ BER2';
    $nodes = [
        eagleNode('10.0.0.1', 'gateway', ['site' => $long, 'device_id' => 1, 'irbs' => 57]),
        eagleNode('10.0.0.11', 'leaf', ['site' => 'BER1', 'device_id' => 11]),
    ];
    $out = EagleLayout::place(eagleShape($nodes, FabricShape::UNDERLAY_LEAF_MESH, FabricShape::ROUTING_CRB), $nodes, [], [], [], [], []);
    $gateway = array_values(array_filter($out['groups'], fn ($g) => $g['label'] === $long))[0];
    $leaf = array_values(array_filter($out['groups'], fn ($g) => $g['label'] === 'BER1'))[0];

    expect($gateway['w'])->toBeGreaterThan(EagleLayout::CARD_W + 2 * EagleLayout::SITE_PAD)
        ->and($gateway['w'])->toBeLessThanOrEqual(EagleLayout::GATEWAY_CAPTION_CAP)
        // the whole location, no ellipsis, and the card centred in the box it grew to
        ->and($gateway['caption'])->toBe($long . ' (1)')
        ->and($out['nodes']['10.0.0.1']['x'])->toBeGreaterThan($gateway['x'] + EagleLayout::SITE_PAD)
        ->and($out['nodes']['10.0.0.1']['x'] + EagleLayout::CARD_W)->toBeLessThanOrEqual($gateway['x'] + $gateway['w'])
        // a one-member leaf site stays on the card grid
        ->and($leaf['w'])->toBe(EagleLayout::CARD_W + 2 * EagleLayout::SITE_PAD);
});

it('reserves a band between two rows of a compound, not only under the last one', function () {
    // BER1 on the production fabric: four members, two columns by two rows. The upper pair's
    // bracket used to be drawn into ROW_GAP (16 px) and landed on the cards of the row below.
    $nodes = [];
    foreach (['11', '12', '13', '14'] as $i => $last) {
        $nodes[] = eagleNode('10.0.0.' . $last, 'leaf', ['site' => 'BER1', 'device_id' => 10 + $i]);
    }
    // three sites, so the cap is 2 columns and the site is two rows of two
    $nodes[] = eagleNode('10.0.1.1', 'leaf', ['site' => 'BER2', 'device_id' => 21]);
    $nodes[] = eagleNode('10.0.2.1', 'leaf', ['site' => 'BER4', 'device_id' => 31]);
    $upper = ['a' => '10.0.0.11', 'b' => '10.0.0.12', 'esis' => 2, 'degraded' => 0, 'id' => 'upper'];

    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], [$upper], []);
    $topRow = $out['nodes']['10.0.0.11'];
    $nextRow = $out['nodes']['10.0.0.13'];
    $edge = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'esi'))[0];

    expect($topRow['row'])->toBe(0)
        ->and($nextRow['row'])->toBe(1)
        // the gap between the two rows is the ESI band, not ROW_GAP
        ->and($nextRow['y'] - ($topRow['y'] + EagleLayout::CARD_H))->toBe(EagleLayout::ESI_BAND)
        ->and($edge['shape'])->toBe('bracket')
        // ... and the bracket's horizontal leg is inside it, above the next row of cards
        ->and($edge['ly'] + 3)->toBe($topRow['y'] + EagleLayout::CARD_H + 12)
        ->and($edge['ly'] + 3)->toBeLessThan($nextRow['y']);
});

it('walks a same-site pair on two rows into the top of the lower card, not through it', function () {
    $nodes = [];
    foreach (['11', '12', '13', '14'] as $i => $last) {
        $nodes[] = eagleNode('10.0.0.' . $last, 'leaf', ['site' => 'BER1', 'device_id' => 10 + $i]);
    }
    $nodes[] = eagleNode('10.0.1.1', 'leaf', ['site' => 'BER2', 'device_id' => 21]);
    $nodes[] = eagleNode('10.0.2.1', 'leaf', ['site' => 'BER4', 'device_id' => 31]);
    // a three-PE segment in a two-column site: ordering keeps the block together, so the third
    // member wraps onto the next row and that pair is the one with no single row to sit under
    $pairs = [
        ['a' => '10.0.0.11', 'b' => '10.0.0.12', 'esis' => 1, 'degraded' => 0, 'id' => 'a-b'],
        ['a' => '10.0.0.11', 'b' => '10.0.0.13', 'esis' => 1, 'degraded' => 0, 'id' => 'a-c'],
    ];

    $out = EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], $pairs, []);
    $upper = $out['nodes']['10.0.0.11'];
    $lower = $out['nodes']['10.0.0.13'];
    $edge = array_values(array_filter($out['edges'], fn ($e) => $e['id'] === 'edge:esi:a-c'))[0];
    preg_match_all('/-?\d+/', $edge['path'], $m);
    $points = array_map('intval', $m[0]);

    expect($upper['row'])->toBe(0)
        ->and($lower['row'])->toBe(1)
        ->and($edge['shape'])->toBe('segment')
        // the same 22 px band opens between the two rows
        ->and($lower['y'] - ($upper['y'] + EagleLayout::CARD_H))->toBe(EagleLayout::ESI_BAND)
        ->and($points)->toHaveCount(8)
        // down from the upper card's bottom, across the band, into the lower card's **top**
        ->and($points[1])->toBe($upper['y'] + EagleLayout::CARD_H)
        ->and($points[3])->toBe($upper['y'] + EagleLayout::CARD_H + 12)
        ->and($points[5])->toBe($upper['y'] + EagleLayout::CARD_H + 12)
        ->and($points[7])->toBe($lower['y'])
        ->and($points[3])->toBeLessThan($lower['y']);
});

it('shortens an ESI label that does not fit between its two anchors', function () {
    $nodes = [eagleNode('10.0.0.11', 'leaf', ['site' => 'A']), eagleNode('10.0.0.12', 'leaf', ['site' => 'A'])];
    $short = [['a' => '10.0.0.11', 'b' => '10.0.0.12', 'esis' => 2, 'degraded' => 0, 'id' => 'p']];
    $long = [['a' => '10.0.0.11', 'b' => '10.0.0.12', 'esis' => 22, 'degraded' => 5, 'id' => 'p']];

    $fits = array_values(array_filter(EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], $short, [])['edges'], fn ($e) => $e['layer'] === 'esi'))[0];
    $wide = array_values(array_filter(EagleLayout::place(eagleShape($nodes), $nodes, [], [], [], $long, [])['edges'], fn ($e) => $e['layer'] === 'esi'))[0];

    // the anchors are one column apart (168 px), which holds "22 ESIs, 5 degraded" at 4.8 px
    // a character; the assertion is the rule, not this one string
    expect($fits['label'])->toBe('2 ESIs')
        ->and($wide['label'])->toBe(mb_strlen('22 ESIs, 5 degraded') * EagleLayout::EDGE_CHAR_W <= 168 - 8 ? '22 ESIs, 5 degraded' : '22')
        // whatever is drawn, the whole string is in the title
        ->and($wide['title'])->toContain('22 shared ESI-LAG segments')
        ->and($wide['title'])->toContain('5 degraded');
});

it('trunks an unmonitored far end to each site instead of fanning a line per leaf', function () {
    // fabric 16: one shared far end above two multi-member sites. `trunks()` skipped every row
    // with `b === null`, so this drew one line per leaf straight through the gateway tier.
    $nodes = [];
    foreach ([['11', 'A'], ['12', 'A'], ['21', 'B'], ['22', 'B']] as $i => [$last, $site]) {
        $nodes[] = eagleNode('10.0.0.' . $last, 'leaf', ['site' => $site, 'device_id' => 10 + $i]);
    }
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_SPINE_LEAF);
    $links = [];
    foreach ($nodes as $n) {
        $links[] = eagleLink($n['ip'], null, ['b_label' => '10.9.9.1', 'link_key' => 'k-' . $n['ip']]);
    }

    $out = EagleLayout::place($shape, $nodes, $links, [], ['10.9.9.1'], [], []);
    $trunks = array_values(array_filter($out['edges'], fn ($e) => $e['kind'] === 'trunk'));
    $lines = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'underlay' && $e['kind'] !== 'trunk'));

    expect($out['nodes'])->toHaveKey('far:10.9.9.1')
        ->and($trunks)->toHaveCount(2)
        ->and($lines)->toBe([])
        // both name the placed card, not the bare address
        ->and(array_column($trunks, 'a'))->toBe(['far:10.9.9.1', 'far:10.9.9.1'])
        ->and(array_column($trunks, 'label'))->toBe(['2 up', '2 up'])
        // and the title reads as an address, not as an internal id
        ->and($trunks[0]['title'])->toContain('10.9.9.1')
        ->and($trunks[0]['title'])->not->toContain('far:');
});

it('names the placed far card on an untrunked line to the same far end', function () {
    // one site of two, but only one of them peers with the far end: no trunk, and the line that
    // is drawn still has to name the card it lands on
    $nodes = [
        eagleNode('10.0.0.11', 'leaf', ['site' => 'A', 'device_id' => 11]),
        eagleNode('10.0.0.12', 'leaf', ['site' => 'A', 'device_id' => 12]),
        eagleNode('10.0.0.21', 'leaf', ['site' => 'B', 'device_id' => 21]),
    ];
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_SPINE_LEAF);
    $links = [
        eagleLink('10.0.0.11', null, ['b_label' => '10.9.9.1', 'link_key' => 'a']),
        eagleLink('10.0.0.21', null, ['b_label' => '10.9.9.1', 'link_key' => 'b']),
    ];

    $out = EagleLayout::place($shape, $nodes, $links, [], ['10.9.9.1'], [], []);
    $lines = array_values(array_filter($out['edges'], fn ($e) => $e['layer'] === 'underlay'));

    expect($lines)->toHaveCount(2)
        ->and(array_column($lines, 'kind'))->toBe(['underlay', 'underlay'])
        ->and(array_column($lines, 'b'))->toBe(['far:10.9.9.1', 'far:10.9.9.1']);
});

/**
 * A routing context over hand-built compound rects, so a candidate can be asserted without the
 * packing rule choosing the geometry.
 *
 * @param  array<string, array{x: int, y: int, w: int, h: int}>  $rects
 * @return array<string, mixed>
 */
function eagleRouteContext(array $rects): array
{
    $groups = [];
    foreach ($rects as $key => $rect) {
        $groups[] = ['key' => $key] + $rect;
    }

    // no picture rect: the bounds are the rects themselves, so the ring is where the arithmetic
    // in the plan puts it rather than wherever a canvas size would have pushed it
    return EagleLayout::routeContext($groups, [], 0, 0);
}

/** @return list<array{0: int, 1: int}> */
function eaglePoints(string $path): array
{
    preg_match_all('/(-?\d+) (-?\d+)/', $path, $m, PREG_SET_ORDER);

    return array_map(fn ($p) => [(int) $p[1], (int) $p[2]], $m);
}

/** @param list<array{0: int, 1: int}> $points */
function eagleOrthogonal(array $points): bool
{
    for ($i = 1; $i < count($points); $i++) {
        if ($points[$i][0] !== $points[$i - 1][0] && $points[$i][1] !== $points[$i - 1][1]) {
            return false;
        }
    }

    return true;
}

it('routes around a compound it does not terminate on, and never diagonally', function () {
    // hand-built rects, so the packing cannot hide the case. A source compound, a wide foreign
    // compound overlapping it in y, and a target compound below: the channel between the bands
    // is blocked by the foreign box and so is every vertical leg, which leaves the left ring.
    $sourceGroup = ['x' => 100, 'y' => 40, 'w' => 180, 'h' => 100];
    $sourceCard = ['x' => 110, 'y' => 50, 'w' => 156, 'h' => 58];
    $foreign = ['x' => 80, 'y' => 130, 'w' => 400, 'h' => 40];
    $targetGroup = ['x' => 100, 'y' => 200, 'w' => 180, 'h' => 100];
    $targetCard = ['x' => 110, 'y' => 230, 'w' => 156, 'h' => 58];

    $ctx = eagleRouteContext(['A' => $sourceGroup, 'B' => $foreign, 'C' => $targetGroup]);
    $routed = EagleLayout::route($sourceCard, $targetCard, [$foreign], $ctx, 'edge:underlay:one');

    expect($routed['points'])->toBe([[188, 108], [64, 108], [64, 230], [188, 230]])
        ->and(eagleOrthogonal($routed['points']))->toBeTrue()
        // and it is the ring, not a channel, so it holds no lane
        ->and($routed['gap'])->toBeNull()
        ->and(EagleLayout::pathClean($routed['points'], [$foreign]))->toBeTrue();
});

it('takes the channel between two compounds in a row rather than crossing the middle one', function () {
    $left = ['x' => 40, 'y' => 100, 'w' => 180, 'h' => 100];
    $middle = ['x' => 248, 'y' => 100, 'w' => 180, 'h' => 100];
    $right = ['x' => 456, 'y' => 100, 'w' => 180, 'h' => 100];
    $leftCard = ['x' => 50, 'y' => 120, 'w' => 156, 'h' => 58];
    $rightCard = ['x' => 466, 'y' => 120, 'w' => 156, 'h' => 58];

    $ctx = eagleRouteContext(['L' => $left, 'M' => $middle, 'R' => $right]);
    $routed = EagleLayout::route($leftCard, $rightCard, [$middle], $ctx, 'edge:underlay:one');

    // the two outer compounds are not neighbours in the band, so no channel joins them: the
    // path leaves on the ring rather than through the middle box
    expect(eagleOrthogonal($routed['points']))->toBeTrue()
        ->and(EagleLayout::pathClean($routed['points'], [$middle]))->toBeTrue()
        ->and($routed['points'][0])->toBe([206, 149])
        ->and(end($routed['points']))->toBe([466, 149]);

    // two neighbouring compounds do share a channel, and it is used. The middle box is where
    // this edge lands, so it is not one of its obstacles
    $neighbour = EagleLayout::route($leftCard, ['x' => 258, 'y' => 120, 'w' => 156, 'h' => 58], [], $ctx, 'edge:underlay:two');
    expect($neighbour['gap'])->toStartWith('v:')
        ->and($neighbour['k'])->toBe(0)
        ->and(eagleOrthogonal($neighbour['points']))->toBeTrue();
});

it('gives each edge in one channel its own lane and lets only the first label it', function () {
    $left = ['x' => 40, 'y' => 100, 'w' => 180, 'h' => 100];
    $right = ['x' => 248, 'y' => 100, 'w' => 180, 'h' => 100];
    $a = ['x' => 50, 'y' => 120, 'w' => 156, 'h' => 58];
    $b = ['x' => 258, 'y' => 120, 'w' => 156, 'h' => 58];

    $ctx = eagleRouteContext(['L' => $left, 'R' => $right]);
    $lanes = [];
    foreach (['edge:underlay:a', 'edge:underlay:b', 'edge:underlay:c', 'edge:underlay:d'] as $i => $id) {
        $routed = EagleLayout::route($a, $b, [], $ctx, $id);
        $lanes[] = [$routed['k'], $routed['points'][1][0]];
    }

    // GUTTER 28 less CLEARANCE on each side is 20 usable, which is three lanes at pitch 6; the
    // fourth edge shares a stroke rather than inventing a lane outside the gap, and its k is
    // still 3, so it is not the one that draws the label
    expect(array_column($lanes, 0))->toBe([0, 1, 2, 3])
        ->and(count(array_unique(array_column($lanes, 1))))->toBe(3)
        ->and($lanes[3][1])->toBe($lanes[0][1]);
});

it('walks the inset of its own compound when two cards do not face each other', function () {
    // the pad fixture: the two cards overlap in y by -2, so this is not an arc, and the ports
    // are A's right midpoint and B's left midpoint. "The nearest point on the inset" would not
    // produce this list -- the exit is along the port's own normal.
    $group = ['x' => 10, 'y' => 10, 'w' => 344, 'h' => 158];
    $a = ['x' => 20, 'y' => 40, 'w' => 156, 'h' => 58];
    $b = ['x' => 188, 'y' => 100, 'w' => 156, 'h' => 58];

    $points = EagleLayout::padRoute($group, $a, $b);

    expect($points)->toBe([[176, 69], [350, 69], [350, 164], [14, 164], [14, 129], [188, 129]])
        ->and(eagleOrthogonal($points))->toBeTrue()
        // a sibling sitting on the straight chord between the two ports is never sampled: the
        // path does not go near it
        ->and(EagleLayout::pathClean($points, [['x' => 180, 'y' => 80, 'w' => 20, 'h' => 40]]))->toBeTrue();
});

it('routes a trunk around the tier between the spine and its site, and labels the long leg', function () {
    // fabric 16: an unmonitored far end on the spine tier, the gateway tier under it, and the
    // leaf sites below that. The straight stroke to a leaf header crosses a gateway compound.
    $nodes = [
        eagleNode('10.0.0.1', 'gateway', ['site' => 'GW-A', 'device_id' => 1, 'irbs' => 9]),
        eagleNode('10.0.0.2', 'gateway', ['site' => 'GW-B', 'device_id' => 2, 'irbs' => 9]),
    ];
    foreach ([['11', 'A'], ['12', 'A'], ['21', 'B'], ['22', 'B']] as $i => [$last, $site]) {
        $nodes[] = eagleNode('10.0.0.' . $last, 'leaf', ['site' => $site, 'device_id' => 10 + $i]);
    }
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_SPINE_LEAF, FabricShape::ROUTING_CRB);
    $links = [];
    foreach ($nodes as $n) {
        $links[] = eagleLink($n['ip'], null, ['b_label' => '10.9.9.1', 'link_key' => 'k-' . $n['ip']]);
    }

    $out = EagleLayout::place($shape, $nodes, $links, [], ['10.9.9.1'], [], []);
    $boxes = array_map(fn ($g) => ['x' => $g['x'], 'y' => $g['y'], 'w' => $g['w'], 'h' => $g['h']], $out['groups']);
    $byKey = array_combine(array_column($out['groups'], 'key'), $boxes);
    $trunks = array_values(array_filter($out['edges'], fn ($e) => $e['kind'] === 'trunk'));

    expect($trunks)->toHaveCount(2);
    foreach ($trunks as $trunk) {
        $points = eaglePoints($trunk['path']);
        // one stroke, still, and it misses every compound it does not land on
        $foreign = array_values(array_filter($byKey, fn ($k) => $k !== $trunk['b'], ARRAY_FILTER_USE_KEY));
        expect($trunk['shape'])->toBe('trunk')
            ->and(eagleOrthogonal($points))->toBeTrue()
            ->and(EagleLayout::pathClean($points, $foreign))->toBeTrue()
            // the label is on the polyline, not at the midpoint of the chord it rejected
            ->and($trunk['lx'])->toBeGreaterThanOrEqual(min(array_column($points, 0)))
            ->and($trunk['lx'])->toBeLessThanOrEqual(max(array_column($points, 0)));
    }
});

it('keeps every routed edge of a real fabric out of the compounds it does not terminate on', function () {
    // the invariant, over the production shape with cross-site sessions between every site
    $nodes = productionNodes();
    $shape = eagleShape($nodes, FabricShape::UNDERLAY_LEAF_MESH, FabricShape::ROUTING_CRB);
    $links = [];
    $ips = array_column($nodes, 'ip');
    foreach ($ips as $i => $a) {
        foreach (array_slice($ips, $i + 1) as $b) {
            $links[] = eagleLink($a, $b, ['link_key' => $a . '|' . $b]);
        }
    }

    $out = EagleLayout::place($shape, $nodes, $links, [], [], [], []);
    $byKey = [];
    foreach ($out['groups'] as $g) {
        $byKey[$g['key']] = ['x' => $g['x'], 'y' => $g['y'], 'w' => $g['w'], 'h' => $g['h']];
    }

    $routed = array_values(array_filter($out['edges'], fn ($e) => in_array($e['shape'] ?? '', ['line', 'trunk'], true)));
    expect($routed)->not->toBeEmpty();
    foreach ($routed as $e) {
        $points = eaglePoints($e['path']);
        $foreign = [];
        foreach ($byKey as $key => $rect) {
            if ($key !== ($e['site_a'] ?? '') && $key !== ($e['site_b'] ?? '')) {
                $foreign[] = $rect;
            }
        }
        // a trunk whose straight stroke is already clean stays that one segment and may run at
        // an angle; everything the router touched is orthogonal
        expect(EagleLayout::pathClean($points, $foreign))->toBeTrue();
        if ($e['shape'] === 'line') {
            expect(eagleOrthogonal($points))->toBeTrue();
        }
    }
});
