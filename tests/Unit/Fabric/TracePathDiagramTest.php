<?php

use SafferIt\LibrenmsNetconf\Fabric\View\TracePathDiagram;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function diagramEndpoint(string $ip, string $mac, string $ifname, array $extra = []): array
{
    return $extra + [
        'query' => $ip, 'kind' => 'ip', 'mac' => $mac, 'ips' => [$ip], 'vni' => 91, 'device_id' => 1, 'address' => null,
        'name' => null, 'ifname' => $ifname, 'port_id' => null, 'esi' => null, 'source' => 'evpn-mac-ip',
        'evidence' => [], 'is_duplicate' => false, 'moves' => 0, 'df' => false,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function diagramHop(string $a, string $aIf, string $b, string $bIf, array $extra = []): array
{
    return $extra + [
        'a' => $a, 'a_ifname' => $aIf, 'a_port_id' => null, 'b' => $b, 'b_ifname' => $bIf, 'b_port_id' => null,
        'protocol' => 'ospf', 'state' => 'full', 'up' => true, 'lldp' => false, 'wan' => false, 'link_key' => $a . '|' . $b,
    ];
}

/**
 * The items of a diagram that is one run (one route, one path).
 *
 * @param  array<string, mixed>  $diagram
 * @return list<array<string, mixed>>
 */
function diagramItems(array $diagram): array
{
    expect($diagram['blocks'])->toHaveCount(1)->and($diagram['blocks'][0]['type'])->toBe('seq');

    return $diagram['blocks'][0]['items'];
}

/** @return array{name: string, role: string, border: bool} */
function diagramNode(string $address): array
{
    return match ($address) {
        '10.0.0.1' => ['name' => 'LEAF1', 'role' => 'leaf', 'border' => false],
        '10.0.0.2' => ['name' => 'SPINE1', 'role' => 'spine', 'border' => false],
        '10.0.0.3' => ['name' => 'GW1', 'role' => 'gateway', 'border' => true],
        '10.0.0.4' => ['name' => 'LEAF2', 'role' => 'leaf', 'border' => false],
        default => ['name' => $address, 'role' => 'unknown', 'border' => false],
    };
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function diagramResult(array $legs, array $extra = []): array
{
    return $extra + [
        'ok' => true,
        'a' => diagramEndpoint('192.0.2.4', '98:ee:cb:d0:5b:5b', 'ae36.0', ['port_id' => 70, 'esi' => '00:11:22:33:44:55:66:77:88:99', 'df' => true]),
        'b' => diagramEndpoint('192.0.2.9', '020000001141', 'ge-0/0/28', ['vni' => 103, 'source' => 'arp']),
        'routed' => null,
        'legs' => $legs,
    ];
}

it('draws a bridged trace as endpoints, devices and the links between them', function () {
    $legs = [[
        'vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.4', 'pivot' => null,
        'path' => [
            diagramHop('10.0.0.1', 'et-0/0/48.0', '10.0.0.2', 'et-0/0/2.0', ['a_port_id' => 11, 'b_port_id' => 12]),
            diagramHop('10.0.0.2', 'et-0/0/3.0', '10.0.0.4', 'et-0/0/52.0', ['up' => false, 'state' => 'down', 'ecmp' => 2]),
        ],
        'paths' => [],
    ]];

    $diagram = TracePathDiagram::build(diagramResult($legs), 'diagramNode');
    $items = diagramItems($diagram);

    expect(array_map(fn ($i) => $i['type'] . ($i['type'] === 'link' ? ':' . $i['kind'] : ''), $items))->toBe([
        'endpoint', 'link:access', 'station', 'link:underlay', 'station', 'link:underlay', 'station', 'link:access', 'endpoint',
    ])
        ->and($items[0])->toMatchArray(['role' => 'source', 'title' => '192.0.2.4', 'sub' => ['98:ee:cb:d0:5b:5b'], 'esi' => '00:11:22:33:44:55:66:77:88:99', 'df' => true, 'source' => 'EVPN IP/MAC table'])
        ->and($items[1])->toMatchArray(['right_if' => 'ae36.0', 'port_ids' => [70], 'esi' => '00:11:22:33:44:55:66:77:88:99', 'tone' => 'up'])
        ->and(array_column(array_filter($items, fn ($i) => $i['type'] === 'station'), 'name'))->toBe(['LEAF1', 'SPINE1', 'LEAF2'])
        ->and($items[3])->toMatchArray(['left_if' => 'et-0/0/48.0', 'right_if' => 'et-0/0/2.0', 'vni' => 91, 'protocol' => 'ospf', 'tone' => 'up', 'port_ids' => [11, 12]])
        ->and($items[5])->toMatchArray(['tone' => 'down', 'ecmp' => 2, 'state' => 'down'])
        ->and($items[7])->toMatchArray(['right_if' => 'ge-0/0/28', 'vni' => 103])
        ->and($items[8])->toMatchArray(['role' => 'destination', 'title' => '192.0.2.9', 'source' => 'core ARP table'])
        ->and($diagram['overlays'])->toBe([['vni' => 91, 'from' => 'LEAF1', 'to' => 'LEAF2', 'from_address' => '10.0.0.1', 'to_address' => '10.0.0.4']]);
});

it('puts the routing step on the one gateway card the two legs share', function () {
    $legs = [
        ['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.3', 'pivot' => 'irb.91 → irb.103', 'path' => [diagramHop('10.0.0.1', 'et-0/0/48.0', '10.0.0.3', 'et-0/0/2.0')], 'paths' => []],
        ['vni' => 103, 'from' => '10.0.0.3', 'to' => '10.0.0.4', 'pivot' => null, 'path' => [diagramHop('10.0.0.3', 'et-0/0/2.0', '10.0.0.4', 'et-0/0/52.0')], 'paths' => []],
    ];

    $diagram = TracePathDiagram::build(diagramResult($legs, ['routed' => ['gateway' => '10.0.0.3', 'gateway_name' => 'GW1', 'context' => 'master']]), 'diagramNode');
    $stations = array_values(array_filter(diagramItems($diagram), fn ($i) => $i['type'] === 'station'));
    $links = array_values(array_filter(diagramItems($diagram), fn ($i) => $i['type'] === 'link' && $i['kind'] === 'underlay'));

    expect(array_column($stations, 'name'))->toBe(['LEAF1', 'GW1', 'LEAF2'])
        ->and($stations[1])->toMatchArray(['pivot' => 'irb.91 → irb.103', 'context' => 'master', 'role' => 'gateway', 'border' => true])
        ->and($stations[0]['pivot'])->toBeNull()
        ->and(array_column($links, 'vni'))->toBe([91, 103])
        ->and(array_column($diagram['overlays'], 'vni'))->toBe([91, 103]);
});

it('draws one device with an in and an out interface for local switching', function () {
    $legs = [['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.1', 'pivot' => null, 'path' => [], 'paths' => []]];

    $diagram = TracePathDiagram::build(diagramResult($legs), 'diagramNode');

    expect(array_column(diagramItems($diagram), 'type'))->toBe(['endpoint', 'link', 'station', 'link', 'endpoint'])
        ->and(diagramItems($diagram)[2])->toMatchArray(['name' => 'LEAF1', 'in' => 'ae36.0', 'out' => 'ge-0/0/28'])
        ->and($diagram['overlays'])->toBe([]);
});

it('shows a gap where two known devices have no stored path between them', function () {
    $legs = [['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.4', 'pivot' => null, 'path' => [], 'paths' => []]];

    $items = diagramItems(TracePathDiagram::build(diagramResult($legs), 'diagramNode'));
    $gap = $items[3];

    expect(array_column($items, 'type'))->toBe(['endpoint', 'link', 'station', 'link', 'station', 'link', 'endpoint'])
        ->and($gap)->toMatchArray(['kind' => 'gap', 'tone' => 'gap', 'state' => 'no stored path', 'left_if' => null, 'right_if' => null])
        ->and($items[2]['name'])->toBe('LEAF1')
        ->and($items[4])->toMatchArray(['name' => 'LEAF2', 'gap' => true]);
});

it('marks a live hop and a WAN hop', function () {
    $hop = diagramHop('10.0.0.1', 'et-0/0/48.0', '10.0.0.4', 'et-0/0/52.0', ['live' => true, 'wan' => true]);
    $legs = [['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.4', 'pivot' => null, 'path' => [$hop], 'paths' => [[$hop]]]];

    $items = diagramItems(TracePathDiagram::build(diagramResult($legs), 'diagramNode'));

    expect($items[3])->toMatchArray(['live' => true, 'wan' => true, 'tone' => 'wan']);
});

it('splits the picture where the underlay has two paths of the same length, and joins it again', function () {
    $viaA = [diagramHop('10.0.0.1', 'et-0/0/1.0', '10.0.0.2', 'et-0/0/9.0'), diagramHop('10.0.0.2', 'et-0/0/8.0', '10.0.0.4', 'et-0/0/52.0')];
    $viaB = [diagramHop('10.0.0.1', 'et-0/0/2.0', '10.0.0.3', 'et-0/0/9.0'), diagramHop('10.0.0.3', 'et-0/0/8.0', '10.0.0.4', 'et-0/0/53.0')];
    $legs = [['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.4', 'pivot' => null, 'path' => $viaA, 'paths' => [$viaA, $viaB]]];

    $diagram = TracePathDiagram::build(diagramResult($legs), 'diagramNode');
    [$before, $split, $after] = $diagram['blocks'];

    expect(array_column($diagram['blocks'], 'type'))->toBe(['seq', 'split', 'seq'])
        // what both paths share: the endpoint, its access link and the first leaf
        ->and(array_column($before['items'], 'type'))->toBe(['endpoint', 'link', 'station'])
        ->and(array_column($after['items'], 'type'))->toBe(['station', 'link', 'endpoint'])
        ->and(array_column($split['branches'], 'label'))->toBe(['via SPINE1', 'via GW1'])
        ->and(array_column($split['branches'][0]['blocks'][0]['items'], 'type'))->toBe(['link', 'station', 'link'])
        ->and($diagram['paths'])->toBe(2)
        ->and($diagram['routes'])->toBe(1);
});

it('names a branch that is only a parallel link after its interfaces', function () {
    $one = [diagramHop('10.0.0.1', 'et-0/0/1.0', '10.0.0.4', 'et-0/0/1.0')];
    $two = [diagramHop('10.0.0.1', 'et-0/0/2.0', '10.0.0.4', 'et-0/0/2.0')];
    $legs = [['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.4', 'pivot' => null, 'path' => $one, 'paths' => [$one, $two]]];

    $split = TracePathDiagram::build(diagramResult($legs), 'diagramNode')['blocks'][1];

    expect(array_column($split['branches'], 'label'))->toBe(['et-0/0/1.0 ↔ et-0/0/1.0', 'et-0/0/2.0 ↔ et-0/0/2.0']);
});

it('draws an endpoint on an ESI-LAG as two routes that part at the host and meet at the destination', function () {
    $direct = [diagramHop('10.0.0.1', 'et-0/0/48.0', '10.0.0.4', 'et-0/0/52.0')];
    $other = [diagramHop('10.0.0.2', 'et-0/0/50.0', '10.0.0.4', 'et-0/0/53.0')];
    $result = diagramResult([['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.4', 'pivot' => null, 'path' => $direct, 'paths' => [$direct]]]);
    $result['a'] = diagramEndpoint('192.0.2.4', '98:ee:cb:d0:5b:5b', 'ae36.0', ['esi' => '00:11:22:33:44:55:66:77:88:99', 'df' => true, 'address' => '10.0.0.1']);
    $result['branches'] = [[
        'a' => diagramEndpoint('192.0.2.4', '98:ee:cb:d0:5b:5b', 'ae36.0', ['esi' => '00:11:22:33:44:55:66:77:88:99', 'address' => '10.0.0.2']),
        'b' => $result['b'],
        'legs' => [['vni' => 91, 'from' => '10.0.0.2', 'to' => '10.0.0.4', 'pivot' => null, 'path' => $other, 'paths' => [$other]]],
        'routed' => null,
    ]];

    $diagram = TracePathDiagram::build($result, 'diagramNode');
    [$before, $split, $after] = $diagram['blocks'];

    expect($diagram['routes'])->toBe(2)
        ->and(array_column($before['items'], 'type'))->toBe(['endpoint'])
        ->and(array_column($split['branches'], 'label'))->toBe(['via LEAF1 · DF', 'via SPINE1'])
        // the destination and its access link are shared; the two ends of the tunnel are not
        ->and(array_column($after['items'], 'type'))->toBe(['station', 'link', 'endpoint'])
        ->and(array_column($diagram['overlays'], 'from_address'))->toBe(['10.0.0.1', '10.0.0.2']);
});

it('merges what branches share, however deep', function () {
    $item = fn (string $key, string $type = 'station') => ['key' => $key, 'type' => $type];
    // two ways to the first fork, then two ways to the second: four sequences, two nested splits
    $sequences = [
        [$item('s'), $item('l1', 'link'), $item('x'), $item('l3', 'link'), $item('z')],
        [$item('s'), $item('l1', 'link'), $item('x'), $item('l4', 'link'), $item('z')],
        [$item('s'), $item('l2', 'link'), $item('y'), $item('l3b', 'link'), $item('z')],
    ];

    $blocks = TracePathDiagram::factor($sequences);

    expect(array_column($blocks, 'type'))->toBe(['seq', 'split', 'seq'])
        ->and($blocks[1]['branches'])->toHaveCount(2)
        // the first branch has the second fork inside it
        ->and(array_column($blocks[1]['branches'][0]['blocks'], 'type'))->toBe(['seq', 'split'])
        ->and(array_column($blocks[1]['branches'][1]['blocks'], 'type'))->toBe(['seq']);
    // identical sequences are one
    expect(TracePathDiagram::factor([$sequences[0], $sequences[0]]))->toBe([['type' => 'seq', 'items' => $sequences[0]]]);
});

it('keeps the first path of every leg once the combinations are too many to draw', function () {
    $paths = [];
    foreach (range(1, 4) as $n) {
        $paths[] = [diagramHop('10.0.0.1', "et-0/0/$n.0", '10.0.0.4', "et-0/0/$n.0")];
    }
    $leg = fn (string $from, string $to) => ['vni' => 91, 'from' => $from, 'to' => $to, 'pivot' => null, 'path' => $paths[0], 'paths' => $paths];
    // two legs of four paths are 16 combinations, over the cap of 8
    $diagram = TracePathDiagram::build(diagramResult([$leg('10.0.0.1', '10.0.0.4'), $leg('10.0.0.4', '10.0.0.1')]), 'diagramNode');

    expect($diagram['paths'])->toBe(1);
});

it('walks the same devices in the same order as the one-line form', function () {
    $legs = [
        ['vni' => 91, 'from' => '10.0.0.1', 'to' => '10.0.0.3', 'pivot' => 'irb.91 → irb.103', 'path' => [diagramHop('10.0.0.1', 'et-0/0/48.0', '10.0.0.3', 'et-0/0/2.0')], 'paths' => []],
        ['vni' => 103, 'from' => '10.0.0.3', 'to' => '10.0.0.4', 'pivot' => null, 'path' => [diagramHop('10.0.0.3', 'et-0/0/2.0', '10.0.0.4', 'et-0/0/52.0')], 'paths' => []],
    ];
    $result = diagramResult($legs);
    $names = ['10.0.0.1' => 'LEAF1', '10.0.0.3' => 'GW1', '10.0.0.4' => 'LEAF2'];
    $a = new SafferIt\LibrenmsNetconf\Fabric\Trace\Endpoint('x', 'ip', null, ['192.0.2.4'], 91, 1, null, null, 'ae36.0', null, null, 'arp', []);
    $b = new SafferIt\LibrenmsNetconf\Fabric\Trace\Endpoint('y', 'ip', null, ['192.0.2.9'], 103, 1, null, null, 'ge-0/0/28', null, null, 'arp', []);

    $line = SafferIt\LibrenmsNetconf\Fabric\Trace\TraceLine::render($a, $b, $legs, $names);
    $stations = array_column(array_filter(diagramItems(TracePathDiagram::build($result, 'diagramNode')), fn ($i) => $i['type'] === 'station'), 'name');

    $positions = array_map(fn ($name) => strpos($line, '[' . $name), $stations);
    $sorted = $positions;
    sort($sorted);

    expect($stations)->toBe(['LEAF1', 'GW1', 'LEAF2'])
        ->and($positions)->not->toContain(false)
        ->and($positions)->toBe($sorted);
});
