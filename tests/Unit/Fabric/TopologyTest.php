<?php

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
