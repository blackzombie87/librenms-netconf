<?php

use SafferIt\LibrenmsNetconf\Fabric\Trace\Endpoint;
use SafferIt\LibrenmsNetconf\Fabric\Trace\EndpointResolver;
use SafferIt\LibrenmsNetconf\Fabric\Trace\FabricTrace;
use SafferIt\LibrenmsNetconf\Fabric\Trace\TraceLine;
use SafferIt\LibrenmsNetconf\Fabric\Trace\UnderlayPath;

/**
 * @param  array<string, mixed>  $extra
 */
function traceEndpoint(string $mac, ?string $address, ?string $ifname, array $extra = []): Endpoint
{
    return new Endpoint(
        query: $extra['query'] ?? $mac,
        kind: 'mac',
        mac: $mac,
        ips: $extra['ips'] ?? [],
        vni: $extra['vni'] ?? 10010,
        deviceId: $extra['device_id'] ?? 1,
        address: $address,
        name: $extra['name'] ?? ($address === null ? null : 'node-' . $address),
        ifname: $ifname,
        portId: $extra['port_id'] ?? null,
        esi: $extra['esi'] ?? null,
        source: $extra['source'] ?? Endpoint::SOURCE_EVPN_LOCAL,
        evidence: $extra['evidence'] ?? ['fixture'],
        isDuplicate: $extra['is_duplicate'] ?? false,
        moves: $extra['moves'] ?? 0,
    );
}

/**
 * The owner's example fabric: BER1 — BER2 — RLG1, full tunnels and flood lists both ways.
 *
 * @return array<string, mixed>
 */
function traceContext(): array
{
    $addresses = ['192.0.2.61', '192.0.2.62', '192.0.2.63'];
    $names = ['192.0.2.61' => 'EVPN-CORE01-BER1', '192.0.2.62' => 'EVPN-CORE01-BER2', '192.0.2.63' => 'EVPN-CORE02-RLG1'];
    $tunnels = [];
    $flood = [];
    $neighbours = [];
    foreach ($addresses as $a) {
        foreach ($addresses as $b) {
            if ($a === $b) {
                continue;
            }
            $tunnels[$a][$b] = ['ifname' => 'vtep.32769', 'port_id' => 10, 'mac_count' => 42];
            $flood[$a][10010][] = $b;
            $neighbours[$a][] = $b;
        }
    }

    return [
        'names' => $names,
        'members' => array_fill_keys($addresses, ['collected' => true]),
        'tunnels' => $tunnels,
        'flood' => $flood,
        'neighbours' => $neighbours,
        'irb_vnis' => [],
        'esis' => [],
    ];
}

it('renders the owner one-liner for a two-hop trace between two leaves', function () {
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38');
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28', ['device_id' => 3]);
    $paths = UnderlayPath::between(lineFabric(), '192.0.2.61', '192.0.2.63');
    $trace = FabricTrace::build($a, $b, $paths, traceContext());

    expect($trace['line'])->toBe(
        '02:00:00:00:11:40 (ge-0/0/38)[EVPN-CORE01-BER1](et-0/0/52.2121) <-> (et-0/0/52.2121)[EVPN-CORE01-BER2](et-0/0/50.2261) <-> (et-0/0/53.2261)[EVPN-CORE02-RLG1](ge-0/0/28) 02:00:00:00:11:41',
    )
        ->and(TraceLine::underlay($paths[0], traceContext()['names']))->toBe(
            'EVPN-CORE01-BER1 (et-0/0/52.2121) ↔ (et-0/0/52.2121) EVPN-CORE01-BER2 (et-0/0/50.2261) ↔ (et-0/0/53.2261) EVPN-CORE02-RLG1',
        )
        ->and($trace['warnings'])->toBe([])
        ->and(array_column($trace['checks'], 'ok'))->toBe([true, true, true, true, true, true])
        ->and($trace['live'])->toBeFalse();
});

it('says local switching when both endpoints hang off the same leaf', function () {
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38');
    $b = traceEndpoint('020000001141', '192.0.2.61', 'ae2');
    $trace = FabricTrace::build($a, $b, [], traceContext());

    expect($trace['same_leaf'])->toBeTrue()
        ->and($trace['line'])->toBe('02:00:00:00:11:40 (ge-0/0/38)[EVPN-CORE01-BER1](ae2) 02:00:00:00:11:41')
        ->and($trace['checks'])->toBe([['id' => 'same-vni', 'label' => 'Both endpoints are in the same VNI', 'ok' => true, 'detail' => 'VNI 10010']]);
});

it('stops at different VNIs and names the gateway that has an IRB in both', function () {
    $context = traceContext();
    $context['irb_vnis'] = ['192.0.2.1' => [10010, 10020], '192.0.2.62' => [10010]];
    $context['names']['192.0.2.1'] = 'GW1';
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38', ['vni' => 10010]);
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28', ['vni' => 10020]);

    $trace = FabricTrace::build($a, $b, [], $context);
    $vniCheck = $trace['checks'][0];

    expect($vniCheck['ok'])->toBeFalse()
        ->and($vniCheck['detail'])->toContain('VNI 10010 and VNI 10020')
        ->and($vniCheck['detail'])->toContain('IRB on GW1')
        ->and($vniCheck['detail'])->toContain('T6');
});

it('flags a missing tunnel, a flood-list gap and a one-sided EVPN session without guessing', function () {
    $context = traceContext();
    unset($context['tunnels']['192.0.2.63']['192.0.2.61']);
    $context['flood']['192.0.2.61'][10010] = ['192.0.2.62'];
    $context['neighbours']['192.0.2.63'] = ['192.0.2.62'];
    $context['flood']['192.0.2.63'] = [];   // no list stored at all: unknown, not "no"

    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38');
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28');
    $trace = FabricTrace::build($a, $b, UnderlayPath::between(lineFabric(), '192.0.2.61', '192.0.2.63'), $context);
    $by = array_column($trace['checks'], null, 'id');

    expect($by['tunnel:192.0.2.63:192.0.2.61']['ok'])->toBeFalse()
        ->and($by['flood:192.0.2.61:192.0.2.63:10010']['ok'])->toBeFalse()
        ->and($by['flood:192.0.2.63:192.0.2.61:10010']['ok'])->toBeNull()
        ->and($by['flood:192.0.2.63:192.0.2.61:10010']['detail'])->toBe('no flood list stored for that VNI on this member')
        ->and($by['session:192.0.2.61:192.0.2.63']['ok'])->toBeFalse()
        ->and($by['session:192.0.2.61:192.0.2.63']['detail'])->toBe('listed by one side only');
});

it('names the protocol that is down on a half-up hop instead of folding it into one word', function () {
    // plan §12.6 and T9: `bgp,ospf` / `Established/Down` must not read as a working hop
    $edges = lineFabric();
    $edges[0]['protocol'] = 'bgp,ospf';
    $edges[0]['state'] = 'Established/Down';
    $edges[0]['up'] = \SafferIt\LibrenmsNetconf\Fabric\View\Topology::sessionUp('bgp,ospf', 'Established/Down');

    $trace = FabricTrace::build(
        traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38'),
        traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28'),
        UnderlayPath::between($edges, '192.0.2.61', '192.0.2.63'),
        traceContext(),
    );

    expect($trace['path'][0]['up'])->toBeFalse()
        ->and($trace['warnings'])->toContain('Hop EVPN-CORE01-BER1 ↔ EVPN-CORE01-BER2 is half up: ospf Down.');
});

it('warns about an unmonitored VTEP and a leg that leaves the fabric', function () {
    $context = traceContext();
    unset($context['members']['192.0.2.62']);
    $edges = lineFabric();
    $edges[1]['wan'] = true;

    $trace = FabricTrace::build(
        traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38'),
        traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28'),
        UnderlayPath::between($edges, '192.0.2.61', '192.0.2.63'),
        $context,
    );

    expect($trace['warnings'])->toContain('Unknown VTEP 192.0.2.62 on the path: add it to LibreNMS to see this hop.')
        ->and(implode(' ', $trace['warnings']))->toContain('leaves the fabric');
});

it('states the DF and aliasing of a multihomed attachment, and warns about a moving MAC', function () {
    $context = traceContext();
    $context['esis']['01:aa'] = ['mode' => 'all-active', 'df_ip' => '192.0.2.61', 'aliasing' => false];
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ae2', ['esi' => '01:aa', 'source' => Endpoint::SOURCE_EVPN_ESI, 'moves' => 4, 'is_duplicate' => true]);
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28');

    $trace = FabricTrace::build($a, $b, [], $context);
    $by = array_column($trace['checks'], null, 'id');

    expect($by['esi-01:aa']['ok'])->toBeTrue()
        ->and($by['esi-01:aa']['detail'])->toContain('all-active, DF EVPN-CORE01-BER1')
        ->and($by['esi-01:aa']['detail'])->toContain('aliasing off')
        ->and($trace['warnings'])->toContain('A: 020000001140 is suppressed by duplicate-MAC detection.')
        ->and($trace['warnings'])->toContain('A: 020000001140 changed its active source 4 times.');
});

it('says so when a MAC is only known from the fabric and no leaf claims it', function () {
    $remote = traceEndpoint('020000001140', '192.0.2.61', null, ['source' => Endpoint::SOURCE_EVPN_REMOTE]);
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28');

    $trace = FabricTrace::build($remote, $b, [], traceContext());

    expect($remote->isAttachment())->toBeFalse()
        ->and($trace['warnings'][0])->toContain('no local attachment');
});

it('ranks a local EVPN row over the bridge table and names every source it consulted', function () {
    $result = EndpointResolver::select(
        ['kind' => 'mac', 'value' => '020000001140', 'q' => '02:00:00:00:11:40'],
        [
            'plugin' => [
                ['device_id' => 9, 'mac' => '020000001140', 'vni' => 10010, 'ips' => ['203.0.113.40'], 'source_type' => 'remote', 'source' => '192.0.2.61', 'ifname' => null, 'port_id' => null, 'esi' => null, 'is_duplicate' => false, 'moves' => 0],
                ['device_id' => 1, 'mac' => '020000001140', 'vni' => 10010, 'ips' => ['203.0.113.40'], 'source_type' => 'local', 'source' => 'ge-0/0/38.0', 'ifname' => 'ge-0/0/38', 'port_id' => 77, 'esi' => null, 'is_duplicate' => false, 'moves' => 0],
            ],
            'fdb' => [['device_id' => 1, 'mac' => '020000001140', 'port_id' => 77, 'ifname' => 'ge-0/0/38']],
            'arp' => [['device_id' => 5, 'mac' => '020000001140', 'ip' => '203.0.113.40', 'port_id' => null, 'ifname' => null]],
        ],
        [1 => ['address' => '192.0.2.61', 'name' => 'BER1'], 9 => ['address' => '192.0.2.63', 'name' => 'RLG1']],
        ['devices' => 2, 'candidates' => 3, 'rows' => 10, 'global' => true, 'fabric' => true],
    );

    expect(array_map(fn ($c) => $c->source, $result['candidates']))
        ->toBe([Endpoint::SOURCE_EVPN_LOCAL, Endpoint::SOURCE_FDB, Endpoint::SOURCE_ARP, Endpoint::SOURCE_EVPN_REMOTE])
        ->and($result['candidates'][0]->address)->toBe('192.0.2.61')
        ->and($result['candidates'][0]->portId)->toBe(77)
        ->and($result['candidates'][3]->isAttachment())->toBeFalse()
        ->and(array_column($result['consulted'], 'rows'))->toBe([2, 1, 1])
        ->and($result['consulted'][0]['note'])->toBe('collected on 2 of 3 devices here')
        ->and($result['notes'])->toBe([]);
});

it('drops a bridge-table row learnt over a tunnel and reports an empty MAC table honestly', function () {
    $result = EndpointResolver::select(
        ['kind' => 'mac', 'value' => '020000001140', 'q' => '02:00:00:00:11:40'],
        ['plugin' => [], 'fdb' => [['device_id' => 1, 'mac' => '020000001140', 'port_id' => 3, 'ifname' => 'vtep.32769']], 'arp' => []],
        [1 => ['address' => '192.0.2.61', 'name' => 'BER1']],
        ['devices' => 0, 'candidates' => 12, 'rows' => 0, 'global' => false, 'fabric' => true],
    );

    expect($result['candidates'])->toBe([])
        ->and($result['consulted'][0]['note'])->toBe('collection is off on every device here, so this table is empty')
        ->and($result['notes'])->toBe(['Not found in any source.']);
});
