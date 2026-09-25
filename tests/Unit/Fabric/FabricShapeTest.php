<?php

use SafferIt\LibrenmsNetconf\Fabric\View\FabricShape;

/**
 * @param  array<string, mixed>  $extra
 * @return array{ip: string, device_id: int|null, role: string, collected: bool, irbs: int, lag_esis: int}
 */
function shapeMember(string $ip, string $role = 'leaf', array $extra = []): array
{
    return $extra + ['ip' => $ip, 'device_id' => (int) substr($ip, strrpos($ip, '.') + 1), 'role' => $role, 'collected' => true, 'irbs' => 0, 'lag_esis' => 0];
}

/**
 * Directed pairs of a complete mesh over the given addresses, listed from both sides.
 *
 * @param  list<string>  $ips
 * @return list<array{0: string, 1: string}>
 */
function shapeMesh(array $ips): array
{
    $out = [];
    foreach ($ips as $a) {
        foreach ($ips as $b) {
            if ($a !== $b) {
                $out[] = [$a, $b];
            }
        }
    }

    return $out;
}

/**
 * The production fabric of plan §10: two MX204 gateways with the IRBs and no ESI-LAG, twelve
 * leaves with ESI-LAGs and no IRB, a complete overlay mesh and no spine.
 *
 * @return list<array{ip: string, device_id: int|null, role: string, collected: bool, irbs: int, lag_esis: int}>
 */
function productionMembers(): array
{
    $members = [
        shapeMember('10.0.0.1', 'gateway', ['irbs' => 57, 'lag_esis' => 0]),
        shapeMember('10.0.0.2', 'gateway', ['irbs' => 57, 'lag_esis' => 0]),
    ];
    for ($i = 11; $i <= 22; $i++) {
        $members[] = shapeMember('10.0.0.' . $i, 'leaf', ['lag_esis' => 4]);
    }

    return $members;
}

it('classifies the production fabric as CRB over a leaf mesh with a full overlay mesh', function () {
    $members = productionMembers();
    $shape = FabricShape::classify($members, shapeMesh(array_column($members, 'ip')), [], []);

    expect($shape['routing'])->toBe(FabricShape::ROUTING_CRB)
        ->and($shape['underlay'])->toBe(FabricShape::UNDERLAY_LEAF_MESH)
        ->and($shape['overlay'])->toBe(FabricShape::OVERLAY_FULL)
        ->and($shape['badge'])->toBe('CRB · leaf mesh')
        ->and($shape['tier']['10.0.0.1'])->toBe(FabricShape::TIER_GATEWAY)
        ->and($shape['tier']['10.0.0.11'])->toBe(FabricShape::TIER_LEAF)
        ->and($shape['counts']['pairs'])->toBe(91)
        ->and($shape['overlay_edges'])->toBe([])          // a healthy mesh is a sentence
        ->and($shape['faults'])->toBe([])
        ->and($shape['evidence'])->toContain('IRBs on 2 collected members that have no ESI-LAG; 12 leaves have ESI-LAGs and no IRB.')
        ->and($shape['evidence'])->toContain('No spine.')
        ->and($shape['evidence'])->toContain('Full mesh of 14, 91 pairs, both sides.');
});

it('does not let two unpolled members break the mesh of the other twelve', function () {
    $members = productionMembers();
    foreach ([0, 1] as $i) {
        $members[$i]['collected'] = false;
        $members[$i]['irbs'] = 0;      // the tables are empty, not the device
    }
    $leaves = array_slice(array_column($members, 'ip'), 2);
    $shape = FabricShape::classify($members, shapeMesh($leaves), [], []);

    expect($shape['overlay'])->toBe(FabricShape::OVERLAY_FULL)
        ->and($shape['counts']['pairs'])->toBe(66)
        ->and($shape['routing'])->toBe(FabricShape::ROUTING_NONE)   // the IRB evidence is gone with them
        ->and($shape['evidence'])->toContain('Full mesh of 12, 66 pairs, both sides. 2 members have no EVPN data.');
});

it('keeps ERB leaves with the leaves although they are stored as gateways', function () {
    $members = [];
    for ($i = 11; $i <= 14; $i++) {
        $members[] = shapeMember('10.0.0.' . $i, 'gateway', ['irbs' => 20, 'lag_esis' => 3]);
    }
    $shape = FabricShape::classify($members, shapeMesh(array_column($members, 'ip')), [], []);

    expect($shape['routing'])->toBe(FabricShape::ROUTING_ERB)
        ->and(array_unique(array_values($shape['tier'])))->toBe([FabricShape::TIER_LEAF])
        ->and($shape['badge'])->toBe('ERB · leaf mesh')
        ->and($shape['evidence'])->toContain('Stored role is gateway because any IRB marks it.');
});

it('keeps only the IRB-without-LAG members on the gateway tier of a mixed fabric', function () {
    $members = [
        shapeMember('10.0.0.1', 'gateway', ['irbs' => 40, 'lag_esis' => 0]),
        shapeMember('10.0.0.11', 'gateway', ['irbs' => 5, 'lag_esis' => 2]),
        shapeMember('10.0.0.12', 'leaf', ['lag_esis' => 2]),
    ];
    $shape = FabricShape::classify($members, shapeMesh(array_column($members, 'ip')), [], []);

    expect($shape['routing'])->toBe(FabricShape::ROUTING_MIXED)
        ->and($shape['tier'])->toBe([
            '10.0.0.1' => FabricShape::TIER_GATEWAY,
            '10.0.0.11' => FabricShape::TIER_LEAF,
            '10.0.0.12' => FabricShape::TIER_LEAF,
        ])
        ->and($shape['evidence'])->toContain('Gateway tier kept; those 1 sit with the leaves.');
});

it('calls a fabric spine-leaf for a stored spine or for a far end several members peer with', function () {
    $leaves = [shapeMember('10.0.0.11'), shapeMember('10.0.0.12')];
    $withSpine = FabricShape::classify([...$leaves, shapeMember('10.0.0.1', 'spine')], [], [], []);
    $withFarEnd = FabricShape::classify($leaves, [], ['10.9.9.1'], []);

    expect($withSpine['underlay'])->toBe(FabricShape::UNDERLAY_SPINE_LEAF)
        ->and($withSpine['tier']['10.0.0.1'])->toBe(FabricShape::TIER_SPINE)
        ->and($withFarEnd['underlay'])->toBe(FabricShape::UNDERLAY_SPINE_LEAF)
        ->and($withFarEnd['shared_far_ends'])->toBe(['10.9.9.1'])   // echoed, never recomputed
        ->and($withFarEnd['evidence'])->toContain('0 spines and 1 unmonitored shared far end above 2 leaves.');
});

it('recognises a route-reflector fabric and joins a missing session to two member addresses', function () {
    $members = [
        shapeMember('10.0.0.1', 'spine'),
        shapeMember('10.0.0.2', 'spine'),
        shapeMember('10.0.0.11'),
        shapeMember('10.0.0.12'),
    ];
    $overlay = [];
    foreach (['10.0.0.11', '10.0.0.12'] as $leaf) {
        foreach (['10.0.0.1', '10.0.0.2'] as $spine) {
            $overlay[] = [$leaf, $spine];
            $overlay[] = [$spine, $leaf];
        }
    }
    $shape = FabricShape::classify($members, $overlay, [], [['device_id' => 12, 'peer_ip' => '10.0.0.99']]);

    expect($shape['overlay'])->toBe(FabricShape::OVERLAY_RR)
        ->and($shape['badge'])->toBe('spine-leaf · RR')
        ->and($shape['evidence'])->toContain('Every collected leaf peers with 10.0.0.1 and 10.0.0.2')
        ->and($shape['faults'])->toBe([
            ['kind' => 'missing', 'a' => '10.0.0.12', 'b' => '10.0.0.99', 'device_id' => 12, 'peer_ip' => '10.0.0.99'],
        ])
        // faults are drawn even while the healthy leaf-to-reflector pairs are not
        ->and($shape['overlay_edges'])->toBe([['kind' => 'missing', 'a' => '10.0.0.12', 'b' => '10.0.0.99', 'id' => '12|10.0.0.99']]);
});

it('drops a missing row whose device is not a member of this fabric', function () {
    $members = [shapeMember('10.0.0.11'), shapeMember('10.0.0.12')];
    $shape = FabricShape::classify($members, shapeMesh(['10.0.0.11', '10.0.0.12']), [], [['device_id' => 777, 'peer_ip' => '10.0.0.99']]);

    expect($shape['faults'])->toBe([])
        ->and($shape['overlay'])->toBe(FabricShape::OVERLAY_FULL);
});

it('is not a route reflector when the neighbour table is empty or one leaf has no session', function () {
    $members = [
        shapeMember('10.0.0.1', 'spine'),
        shapeMember('10.0.0.2', 'spine'),
        shapeMember('10.0.0.11'),
        shapeMember('10.0.0.12'),
    ];

    $empty = FabricShape::classify($members, [], [], []);
    expect($empty['overlay'])->toBe(FabricShape::OVERLAY_PARTIAL)
        ->and($empty['evidence'])->toContain('No EVPN neighbour pairs among 4 collected members.')
        ->and($empty['evidence'])->not->toContain('peers with');

    // leaf .12 lists nobody, so the fabric is not an RR even though .11 looks like one
    $oneLeaf = FabricShape::classify($members, [['10.0.0.11', '10.0.0.1'], ['10.0.0.1', '10.0.0.11']], [], []);
    expect($oneLeaf['overlay'])->toBe(FabricShape::OVERLAY_PARTIAL)
        ->and($oneLeaf['overlay_edges'])->toHaveCount(1)              // the one symmetric pair, drawn
        ->and($oneLeaf['overlay_edges'][0]['kind'])->toBe('symmetric');
});

it('draws an asymmetric pair as a fault and downgrades an otherwise complete mesh', function () {
    $members = [shapeMember('10.0.0.11'), shapeMember('10.0.0.12')];
    $shape = FabricShape::classify($members, [['10.0.0.11', '10.0.0.12']], [], []);

    expect($shape['overlay'])->toBe(FabricShape::OVERLAY_PARTIAL)
        ->and($shape['faults'])->toBe([['kind' => 'asymmetric', 'a' => '10.0.0.11', 'b' => '10.0.0.12', 'device_id' => null, 'peer_ip' => null]])
        ->and($shape['overlay_edges'])->toBe([['kind' => 'asymmetric', 'a' => '10.0.0.11', 'b' => '10.0.0.12', 'id' => '10.0.0.11|10.0.0.12']])
        ->and($shape['evidence'])->toContain('1 pair listed by one side only.');
});

it('carries the symmetric pairs in the sentence rather than on the picture above the arc limit', function () {
    $ips = [];
    for ($i = 1; $i <= 12; $i++) {
        $ips[] = '10.0.0.' . $i;
    }
    $members = array_map(fn ($ip) => shapeMember($ip), $ips);
    // drop one pair so the mesh is not complete: 65 pairs, above OVERLAY_ARC_LIMIT
    $overlay = array_values(array_filter(shapeMesh($ips), fn ($p) => ! in_array($p, [['10.0.0.1', '10.0.0.2'], ['10.0.0.2', '10.0.0.1']], true)));
    $shape = FabricShape::classify($members, $overlay, [], []);

    expect($shape['overlay'])->toBe(FabricShape::OVERLAY_PARTIAL)
        ->and($shape['counts']['pairs'])->toBe(65)
        ->and($shape['overlay_edges'])->toBe([])
        ->and($shape['evidence'])->toContain('65 of 66 possible neighbour pairs among 12 collected members.');
});
