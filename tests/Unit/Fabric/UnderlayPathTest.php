<?php

use SafferIt\LibrenmsNetconf\Fabric\Trace\UnderlayPath;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pathEdge(string $a, string $b, array $extra = []): array
{
    return $extra + [
        'link_key' => $a . '|' . $b, 'a' => $a, 'b' => $b, 'b_label' => null,
        'protocol' => 'ospf', 'state' => 'Full', 'up' => true, 'lldp' => false, 'wan' => false,
        'a_port' => 'et-0/0/1', 'b_port' => 'et-0/0/2', 'network' => '10.0.0.0/31',
    ];
}

/**
 * The owner's example shape: three leaves in a row, BER1 — BER2 — RLG1.
 *
 * @param  array<string, mixed>  $extra  applied to every edge, to swap the underlay protocol
 * @return list<array<string, mixed>>
 */
function lineFabric(array $extra = []): array
{
    return [
        pathEdge('192.0.2.61', '192.0.2.62', $extra + ['a_port' => 'et-0/0/52.2121', 'b_port' => 'et-0/0/52.2121', 'link_key' => 'k1']),
        pathEdge('192.0.2.62', '192.0.2.63', $extra + ['a_port' => 'et-0/0/50.2261', 'b_port' => 'et-0/0/53.2261', 'link_key' => 'k2']),
    ];
}

it('finds the two-hop path of the owner example with both interface names', function () {
    $paths = UnderlayPath::between(lineFabric(), '192.0.2.61', '192.0.2.63');

    expect($paths)->toHaveCount(1)
        ->and($paths[0])->toHaveCount(2)
        ->and($paths[0][0])->toMatchArray(['a' => '192.0.2.61', 'a_ifname' => 'et-0/0/52.2121', 'b' => '192.0.2.62', 'b_ifname' => 'et-0/0/52.2121', 'protocol' => 'ospf'])
        ->and($paths[0][1])->toMatchArray(['a' => '192.0.2.62', 'a_ifname' => 'et-0/0/50.2261', 'b' => '192.0.2.63', 'b_ifname' => 'et-0/0/53.2261'])
        ->and($paths[0][0]['live'])->toBeFalse();
});

it('orients a hop in the direction of travel whichever way the row was stored', function () {
    $reverse = UnderlayPath::between(lineFabric(), '192.0.2.63', '192.0.2.61');

    expect($reverse[0][0])->toMatchArray(['a' => '192.0.2.63', 'a_ifname' => 'et-0/0/53.2261', 'b' => '192.0.2.62', 'b_ifname' => 'et-0/0/50.2261']);
});

it('reads the same paths on a pure eBGP underlay as on the OSPF one', function () {
    // plan §12.4a: eBGP (RFC 7938) is as normal as OSPF, and nothing here may branch on it
    $ospf = UnderlayPath::between(lineFabric(), '192.0.2.61', '192.0.2.63');
    $bgp = UnderlayPath::between(lineFabric(['protocol' => 'bgp', 'state' => 'Established']), '192.0.2.61', '192.0.2.63');

    $shape = fn (array $paths) => array_map(fn ($p) => array_map(fn ($h) => [$h['a'], $h['b']], $p), $paths);

    expect($shape($bgp))->toBe($shape($ospf))
        ->and($bgp[0][0]['protocol'])->toBe('bgp')
        ->and($bgp[0][0]['state'])->toBe('Established');
});

it('chooses the same path on a mixed-protocol fabric as on the OSPF-only one of the same shape', function () {
    // one `bgp`, one `ospf`, one `bgp,ospf` and one `ip` edge in the same positions
    $mixed = [
        pathEdge('a', 'b', ['protocol' => 'bgp', 'state' => 'Established', 'link_key' => 'ab']),
        pathEdge('b', 'c', ['protocol' => 'bgp,ospf', 'state' => 'Established/Full', 'link_key' => 'bc']),
        pathEdge('a', 'd', ['protocol' => 'ip', 'state' => null, 'up' => null, 'link_key' => 'ad']),
        pathEdge('d', 'e', ['protocol' => 'ospf', 'state' => 'Full', 'link_key' => 'de']),
        pathEdge('e', 'c', ['protocol' => 'ospf', 'state' => 'Full', 'link_key' => 'ec']),
    ];
    $ospfOnly = array_map(fn ($e) => $e + [], $mixed);
    foreach ($ospfOnly as &$edge) {
        $edge['protocol'] = 'ospf';
        $edge['state'] = 'Full';
        $edge['up'] = true;
    }
    unset($edge);

    $keys = fn (array $paths) => array_map(fn ($p) => array_column($p, 'link_key'), $paths);

    // two hops through b, three through d: fewest hops wins and the protocol never does
    expect($keys(UnderlayPath::between($mixed, 'a', 'c')))->toBe([['ab', 'bc']])
        ->and($keys(UnderlayPath::between($mixed, 'a', 'c')))->toBe($keys(UnderlayPath::between($ospfOnly, 'a', 'c')));
});

it('returns every equal-hop path rather than picking one, parallel links included', function () {
    $edges = [
        pathEdge('a', 'b', ['link_key' => 'ab1', 'a_port' => 'et-0/0/1']),
        pathEdge('a', 'b', ['link_key' => 'ab2', 'a_port' => 'et-0/0/2']),
        pathEdge('a', 'c', ['link_key' => 'ac']),
        pathEdge('b', 'd', ['link_key' => 'bd']),
        pathEdge('c', 'd', ['link_key' => 'cd']),
    ];
    $paths = UnderlayPath::between($edges, 'a', 'd');

    expect(array_map(fn ($p) => array_column($p, 'link_key'), $paths))
        ->toBe([['ab1', 'bd'], ['ab2', 'bd'], ['ac', 'cd']]);
});

it('walks a down or stateless edge like any other, because reachability is not its question', function () {
    $edges = [
        pathEdge('a', 'b', ['link_key' => 'ab', 'state' => 'Init', 'up' => false]),
        pathEdge('b', 'c', ['link_key' => 'bc', 'protocol' => 'ip', 'state' => null, 'up' => null]),
    ];
    $paths = UnderlayPath::between($edges, 'a', 'c');

    expect($paths)->toHaveCount(1)
        ->and($paths[0][0]['up'])->toBeFalse()
        ->and($paths[0][1]['up'])->toBeNull();
});

it('answers nothing for an unreachable, unknown or identical endpoint', function () {
    $edges = [pathEdge('a', 'b'), pathEdge('c', 'd')];

    expect(UnderlayPath::between($edges, 'a', 'd'))->toBe([])
        ->and(UnderlayPath::between($edges, 'a', 'zz'))->toBe([])
        ->and(UnderlayPath::between($edges, 'a', 'a'))->toBe([])
        ->and(UnderlayPath::between([], 'a', 'b'))->toBe([]);
});

it('stops at the hop cap rather than walking a long chain', function () {
    $edges = [];
    for ($i = 0; $i < UnderlayPath::MAX_HOPS + 2; $i++) {
        $edges[] = pathEdge('n' . $i, 'n' . ($i + 1), ['link_key' => 'k' . $i]);
    }

    expect(UnderlayPath::between($edges, 'n0', 'n' . (UnderlayPath::MAX_HOPS)))->toHaveCount(1)
        ->and(UnderlayPath::between($edges, 'n0', 'n' . (UnderlayPath::MAX_HOPS + 2)))->toBe([]);
});
