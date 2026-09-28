<?php

use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\Topology;

it('classifies session states', function () {
    expect(Topology::sessionUp('ospf', 'full'))->toBeTrue()
        ->and(Topology::sessionUp('bgp', 'established'))->toBeTrue()
        ->and(Topology::sessionUp('bgp', 'idle'))->toBeFalse()
        ->and(Topology::sessionUp('lldp-only', null))->toBeNull()
        ->and(Topology::sessionUp('ip', 'x'))->toBeNull()
        ->and(Topology::sessionUp('ospf', ''))->toBeNull()
        // IS-IS reports a plain adjacency state, and nothing here branches on the protocol
        ->and(Topology::sessionUp('isis', 'up'))->toBeTrue()
        ->and(Topology::sessionUp('isis', 'initializing'))->toBeFalse()
        ->and(Topology::sessionUp('ospf,isis', 'full/up'))->toBeTrue()
        ->and(Topology::sessionUp('ospf,isis', 'full/down'))->toBeFalse();
});

it('reads a multi-protocol edge per component, in either order of the joined state', function () {
    // plan §12.7 T9: the edge carries `bgp,ospf` and `Established/Down`, and the old helper
    // said up because the joined string contains `established`
    expect(Topology::sessionUp('bgp,ospf', 'Established/Down'))->toBeFalse()
        ->and(Topology::sessionUp('ospf,bgp', 'Down/Established'))->toBeFalse()
        ->and(Topology::sessionUp('bgp,ospf', 'Established/Full'))->toBeTrue()
        ->and(Topology::sessionUp('bgp,ospf', 'Idle/Down'))->toBeFalse()
        // both protocols report the same word, so array_unique() left one component
        ->and(Topology::sessionUp('bgp,ospf', 'up'))->toBeTrue();
});

it('names the protocol of each session component when the two lists line up', function () {
    expect(Topology::sessionComponents('bgp,ospf', 'Established/Down'))->toBe([
        ['protocol' => 'bgp', 'state' => 'Established', 'up' => true],
        ['protocol' => 'ospf', 'state' => 'Down', 'up' => false],
    ])
        // three states from two protocols: the two sides disagree, so no component is named
        ->and(Topology::sessionComponents('bgp,ospf', 'Established/Full/Idle'))->toBe([
            ['protocol' => null, 'state' => 'Established', 'up' => true],
            ['protocol' => null, 'state' => 'Full', 'up' => true],
            ['protocol' => null, 'state' => 'Idle', 'up' => false],
        ])
        ->and(Topology::sessionComponents('ip', ''))->toBe([])
        ->and(Topology::sessionComponents('lldp-only', null))->toBe([]);
});

it('canonicalises a neighbour listed by its router-id alias', function () {
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
});
