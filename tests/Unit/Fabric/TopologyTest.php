<?php

use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\Topology;

function topoNode(string $ip, string $role = 'leaf', array $extra = []): array
{
    return $extra + ['ip' => $ip, 'name' => $ip, 'role' => $role, 'device_id' => null, 'border' => false, 'site' => null, 'status' => null];
}

it('places gateways and spines on top and leaves below, grouped by site then ESI pair', function () {
    $layout = Topology::layout(
        [
            topoNode('192.0.2.1', 'spine', ['device_id' => 1]),
            topoNode('192.0.2.200', 'gateway', ['device_id' => 5]),
            topoNode('192.0.2.11', 'leaf', ['device_id' => 11, 'site' => 'B']),
            topoNode('192.0.2.12', 'leaf', ['device_id' => 12, 'site' => 'B']),
            topoNode('192.0.2.13', 'leaf'),
            topoNode('192.0.2.14', 'leaf'),
            topoNode('192.0.2.15', 'leaf'),
        ],
        [],
        [],
        [['a' => '192.0.2.13', 'b' => '192.0.2.14', 'esis' => 3]],
    );

    $top = array_keys(array_filter($layout['nodes'], fn ($n) => $n['layer'] === 'top'));
    $leaves = array_keys(array_filter($layout['nodes'], fn ($n) => $n['layer'] === 'leaf'));

    expect($top)->toBe(['192.0.2.200', '192.0.2.1'])   // gateway before spine
        ->and($leaves)->toBe(['192.0.2.11', '192.0.2.12', '192.0.2.13', '192.0.2.14', '192.0.2.15'])
        ->and(array_column($layout['groups'], 'label'))->toBe(['B', null, null])
        ->and($layout['groups'][1]['members'])->toBe(['192.0.2.13', '192.0.2.14'])
        ->and($layout['groups'][2]['members'])->toBe(['192.0.2.15'])
        ->and($layout['nodes']['192.0.2.1']['y'])->toBeLessThan($layout['nodes']['192.0.2.11']['y'])
        ->and($layout['esi'])->toHaveCount(1)
        ->and($layout['esi'][0]['esis'])->toBe(3);
});

it('draws underlay links between placed nodes, stubs for unknown far ends and one overlay arc per pair', function () {
    $layout = Topology::layout(
        [topoNode('192.0.2.1', 'spine'), topoNode('192.0.2.11', 'leaf', ['device_id' => 11]), topoNode('192.0.2.12', 'leaf', ['device_id' => 12])],
        [
            ['a' => '192.0.2.11', 'b' => '192.0.2.1', 'b_label' => null, 'protocol' => 'bgp', 'state' => 'established', 'up' => true, 'lldp' => true, 'wan' => false, 'a_port' => 'et-0/0/48', 'b_port' => null, 'network' => '10.0.0.0/31'],
            ['a' => '192.0.2.11', 'b' => null, 'b_label' => '10.9.9.1', 'protocol' => 'ospf', 'state' => 'full', 'up' => true, 'lldp' => false, 'wan' => true, 'a_port' => 'et-0/0/49', 'b_port' => null, 'network' => null],
            ['a' => '192.0.2.11', 'b' => '192.0.2.12', 'b_label' => null, 'protocol' => 'ospf', 'state' => 'init', 'up' => false, 'lldp' => false, 'wan' => false, 'a_port' => null, 'b_port' => null, 'network' => null],
        ],
        [['192.0.2.11', '192.0.2.12'], ['192.0.2.12', '192.0.2.11'], ['192.0.2.11', '192.0.2.1'], ['192.0.2.11', '192.0.2.99']],
        [],
    );

    expect($layout['underlay'])->toHaveCount(2)
        ->and($layout['underlay'][0]['path'])->toStartWith('M')
        ->and($layout['underlay'][1]['path'])->toContain('Q')   // same layer: arc
        ->and($layout['stubs'])->toHaveCount(1)
        ->and($layout['stubs'][0]['label'])->toBe('10.9.9.1')
        ->and($layout['overlay'])->toHaveCount(2)               // 11-12 (both ways) and 11-1; 99 is not a node
        ->and(array_column($layout['overlay'], 'symmetric', 'b'))->toBe(['192.0.2.12' => true, '192.0.2.11' => false])   // the spine lists nothing
        ->and($layout['width'])->toBeGreaterThanOrEqual(720);
});

it('classifies session states', function () {
    expect(Topology::sessionUp('ospf', 'full'))->toBeTrue()
        ->and(Topology::sessionUp('bgp', 'established'))->toBeTrue()
        ->and(Topology::sessionUp('bgp', 'idle'))->toBeFalse()
        ->and(Topology::sessionUp('lldp-only', null))->toBeNull()
        ->and(Topology::sessionUp('ip', 'x'))->toBeNull();
});

it('draws overlay arcs to a neighbour listed by its router-id alias', function () {
    // device 11: VTEP 192.0.2.61 is the member, router-id 192.0.2.11 an alias (F1a); device 12 lists 11 by router-id
    $nodes = FabricNodes::fromArray([
        ['vtep_ip' => '192.0.2.61', 'device_id' => 11, 'role' => 'leaf'],
        ['vtep_ip' => '192.0.2.62', 'device_id' => 12, 'role' => 'leaf'],
        ['vtep_ip' => '192.0.2.1', 'role' => 'spine'],
        ['vtep_ip' => '192.0.2.11', 'device_id' => 11, 'role' => 'leaf', 'member' => false],
    ]);
    $pairs = Topology::overlayPairs($nodes, [[11, '192.0.2.62'], [12, '192.0.2.11'], [11, '192.0.2.1'], [12, '192.0.2.99']]);

    expect($pairs)->toBe([['192.0.2.61', '192.0.2.62'], ['192.0.2.62', '192.0.2.61'], ['192.0.2.61', '192.0.2.1'], ['192.0.2.62', '192.0.2.99']])
        ->and($nodes->canonical('192.0.2.11'))->toBe('192.0.2.61')
        ->and($nodes->canonical('192.0.2.99'))->toBe('192.0.2.99');

    $layout = Topology::layout(
        [topoNode('192.0.2.1', 'spine'), topoNode('192.0.2.61', 'leaf', ['device_id' => 11]), topoNode('192.0.2.62', 'leaf', ['device_id' => 12])],
        [],
        $pairs,
        [],
    );
    expect($layout['overlay'])->toHaveCount(2)
        ->and(array_column($layout['overlay'], 'symmetric', 'b'))->toBe(['192.0.2.62' => true, '192.0.2.61' => false]);
});

/**
 * The interactive map (plan §10.11): the same graph as nodes and edges, with the static
 * layout's coordinates as starting positions. The static picture puts every member in one row —
 * 1,968 px at 14 members, 3,780 px at 26 — and the browser scales it down until the labels are
 * unreadable, which is what a growing fabric does to it.
 */
it('hands the same graph to the interactive map, seeded from the static layout', function () {
    $nodes = FabricNodes::fromArray([
        ['vtep_ip' => '192.0.2.1', 'device_id' => 1, 'name' => 'gw', 'role' => 'gateway', 'border' => true],
        ['vtep_ip' => '192.0.2.11', 'device_id' => 11, 'name' => 'leaf-a', 'role' => 'leaf'],
        ['vtep_ip' => '192.0.2.12', 'device_id' => 12, 'name' => 'leaf-b', 'role' => 'leaf'],
        ['vtep_ip' => '192.0.2.13', 'device_id' => 13, 'name' => 'router', 'role' => 'leaf', 'collected' => false],
    ]);
    $layout = Topology::layout(
        [
            topoNode('192.0.2.1', 'gateway', ['device_id' => 1, 'name' => 'gw', 'border' => true]),
            topoNode('192.0.2.11', 'leaf', ['device_id' => 11, 'name' => 'leaf-a', 'site' => 'BER1', 'status' => true]),
            topoNode('192.0.2.12', 'leaf', ['device_id' => 12, 'name' => 'leaf-b', 'site' => 'BER1', 'status' => false]),
            topoNode('192.0.2.13', 'leaf', ['device_id' => 13, 'name' => 'router']),
        ],
        [
            ['a' => '192.0.2.11', 'b' => '192.0.2.1', 'b_label' => null, 'protocol' => 'ospf', 'state' => 'Full', 'up' => true, 'lldp' => true, 'wan' => false, 'a_port' => 'et-0/0/1', 'b_port' => 'et-0/0/2', 'network' => '10.0.0.0/31'],
            ['a' => '192.0.2.12', 'b' => null, 'b_label' => '203.0.113.9', 'protocol' => 'bgp', 'state' => 'Established', 'up' => true, 'lldp' => false, 'wan' => true, 'a_port' => 'et-0/0/3', 'b_port' => null, 'network' => null],
        ],
        [['192.0.2.11', '192.0.2.1'], ['192.0.2.1', '192.0.2.11'], ['192.0.2.12', '192.0.2.1']],
        [['a' => '192.0.2.11', 'b' => '192.0.2.12', 'esis' => 2]],
    );

    $graph = Topology::graph($layout, $nodes);
    $byId = array_column($graph['nodes'], null, 'id');
    $kinds = array_count_values(array_column($graph['edges'], 'kind'));

    expect($byId['192.0.2.11']['x'])->toBe($layout['nodes']['192.0.2.11']['x'])   // seeded, not random
        ->and($byId['192.0.2.11']['site'])->toBe('BER1')
        ->and($byId['192.0.2.12']['down'])->toBeTrue()
        ->and($byId['192.0.2.13']['collected'])->toBeFalse()
        ->and($byId['192.0.2.1']['border'])->toBeTrue()
        ->and($kinds)->toBe(['underlay' => 1, 'wan' => 1, 'overlay' => 2, 'esi' => 1])
        // the far end of the half link becomes a node of its own, so the session stays visible
        ->and($byId['stub:1']['role'])->toBe('stub')
        ->and($byId['stub:1']['name'])->toBe('203.0.113.9')
        ->and(array_column($graph['sites'], 'label'))->toContain('BER1')
        // one of the two overlay pairs is listed by one side only: not a full mesh
        ->and($graph['mesh'])->toBe(['complete' => false, 'members' => 4, 'pairs' => 2, 'asymmetric' => 1])
        ->and($graph['overlay_default'])->toBeTrue();
});

it('turns the arcs off when the overlay is a mesh of any size', function () {
    $members = [];
    $overlay = [];
    for ($i = 1; $i <= 12; $i++) {
        $members[] = topoNode('192.0.2.' . $i, 'leaf', ['device_id' => $i]);
    }
    foreach ($members as $a) {
        foreach ($members as $b) {
            if ($a['ip'] !== $b['ip']) {
                $overlay[] = [$a['ip'], $b['ip']];
            }
        }
    }
    $nodes = FabricNodes::fromArray(array_map(fn ($m) => ['vtep_ip' => $m['ip'], 'device_id' => $m['device_id'], 'name' => $m['ip'], 'role' => 'leaf'], $members));

    $graph = Topology::graph(Topology::layout($members, [], $overlay, []), $nodes);

    expect($graph['mesh'])->toBe(['complete' => true, 'members' => 12, 'pairs' => 66, 'asymmetric' => 0])
        ->and($graph['overlay_pairs'])->toBe(66)
        ->and($graph['overlay_default'])->toBeFalse();   // 66 arcs say nothing 12 members cannot
});
