<?php

use SafferIt\LibrenmsNetconf\Fabric\Trace\Endpoint;
use SafferIt\LibrenmsNetconf\Fabric\Trace\EndpointResolver;
use SafferIt\LibrenmsNetconf\Fabric\Trace\FabricTrace;
use SafferIt\LibrenmsNetconf\Fabric\Trace\RoutedPath;
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
 * @return array{ifname: string, status: string|null, context: string|null, port_id: int|null}
 */
function irb(string $ifname, ?string $status = 'Up', ?string $context = 'master', ?int $portId = null): array
{
    return ['ifname' => $ifname, 'status' => $status, 'context' => $context, 'port_id' => $portId];
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
        'irbs' => [],
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

it('says a routed flow is routed, and why, when no gateway has an IRB in both VNIs', function () {
    $context = traceContext();
    $context['irbs'] = ['192.0.2.62' => [10010 => irb('irb.10')]];
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38', ['vni' => 10010]);
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28', ['vni' => 10020]);

    $routed = RoutedPath::through($context['irbs'], lineFabric(), '192.0.2.61', 10010, '192.0.2.63', 10020);
    $trace = FabricTrace::build($a, $b, [], $context, $routed);
    $vniCheck = $trace['checks'][0];

    expect($vniCheck['ok'])->toBeFalse()
        ->and($vniCheck['label'])->toBe('Routed between VNI 10010 and VNI 10020')
        ->and($vniCheck['detail'])->toContain('no member has an IRB in both VNI 10010 and VNI 10020')
        ->and($trace['routed']['gateway'])->toBeNull()
        ->and($trace['warnings'])->toContain('This is a routed flow and no gateway could be found for it: no member has an IRB in both VNI 10010 and VNI 10020.');
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

it('answers an IP from the device\'s own IP/MAC table, above every inferred source', function () {
    // the live report's first half: one address, one MAC, and a dozen leaves that all report
    // the MAC on its Ethernet Segment. The two PEs that own the segment each have a row of
    // their own here, and the row carries the access interface outright - nothing has to be
    // reconstructed from an ESI, and nothing depends on which leaf sorted first.
    $result = EndpointResolver::select(
        ['kind' => 'ip', 'value' => '203.0.113.4', 'q' => '203.0.113.4'],
        [
            'mac_ip' => [
                ['device_id' => 1, 'mac' => '02000000001a', 'ip' => '203.0.113.4', 'vni' => 91, 'bridge_domain' => 'VX91', 'ifname' => 'ae36.0', 'port_id' => 12788],
                ['device_id' => 9, 'mac' => '02000000001a', 'ip' => '203.0.113.4', 'vni' => 91, 'bridge_domain' => 'VX91', 'ifname' => 'ae36.0', 'port_id' => 12810],
            ],
            // the same MAC as the fabric sees it: the segment, with no interface at all
            'plugin' => [
                ['device_id' => 5, 'mac' => '02000000001a', 'vni' => 91, 'ips' => ['203.0.113.4'], 'source_type' => 'esi', 'source' => '00:11:22:33:44:55:00:00:24:00', 'ifname' => null, 'port_id' => null, 'esi' => '00:11:22:33:44:55:00:00:24:00', 'peers' => [], 'is_duplicate' => false, 'moves' => 0],
            ],
            'fdb' => [],
            'arp' => [['device_id' => 5, 'mac' => '02000000001a', 'ip' => '203.0.113.4', 'port_id' => null, 'ifname' => null]],
        ],
        [1 => ['address' => '192.0.2.61', 'name' => 'BER1'], 5 => ['address' => '192.0.2.62', 'name' => 'BER2'], 9 => ['address' => '192.0.2.63', 'name' => 'HRO1']],
    );

    expect(array_map(fn ($c) => $c->source, $result['candidates']))
        ->toBe([Endpoint::SOURCE_EVPN_MAC_IP, Endpoint::SOURCE_EVPN_MAC_IP, Endpoint::SOURCE_ARP, Endpoint::SOURCE_EVPN_ESI_UNKNOWN])
        ->and($result['candidates'][0]->address)->toBe('192.0.2.61')
        ->and($result['candidates'][0]->ifname)->toBe('ae36.0')
        ->and($result['candidates'][0]->portId)->toBe(12788)
        ->and($result['candidates'][0]->vni)->toBe(91)
        ->and($result['candidates'][0]->evidence[0])->toContain('203.0.113.4 is 02:00:00:00:00:1a in VX91 (VNI 91), on ae36.0')
        // both PEs of the segment, not one of them chosen by address order
        ->and($result['candidates'][1]->address)->toBe('192.0.2.63')
        ->and(array_column($result['consulted'], 'rows'))->toBe([2, 1, 0, 1]);
});

it('says the bridge domain and withholds the VNI when the VNI table has not been polled', function () {
    // a leaf collected for IP/MAC but not yet for VNIs: `VX91` is what the device said, and
    // 91 is a guess about a naming convention. The row still locates the endpoint.
    $result = EndpointResolver::select(
        ['kind' => 'ip', 'value' => '203.0.113.4', 'q' => '203.0.113.4'],
        [
            'mac_ip' => [['device_id' => 1, 'mac' => '02000000001a', 'ip' => '203.0.113.4', 'vni' => null, 'bridge_domain' => 'VX91', 'ifname' => 'ae36.0', 'port_id' => null]],
            'plugin' => [], 'fdb' => [], 'arp' => [],
        ],
        [1 => ['address' => '192.0.2.61', 'name' => 'BER1']],
    );

    expect($result['candidates'][0]->vni)->toBeNull()
        ->and($result['candidates'][0]->isAttachment())->toBeTrue()
        ->and($result['candidates'][0]->evidence[0])->toContain('in VX91 (VNI not polled yet), on ae36.0');
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
        ->and(array_column($result['consulted'], 'rows'))->toBe([0, 2, 1, 1])   // IP/MAC table, MAC database, fdb, arp
        ->and($result['consulted'][1]['note'])->toBe('collected on 2 of 3 devices here')
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

it('renders a routed trace as two legs with the routing step on the gateway', function () {
    $context = traceContext();
    $context['names']['192.0.2.1'] = 'GW1';
    $context['members']['192.0.2.1'] = ['collected' => true];
    $context['irbs'] = ['192.0.2.1' => [10010 => irb('irb.10'), 10020 => irb('irb.20')]];
    // the gateway has a tunnel and a session with both leaves, and floods each VNI to its own leg
    foreach (['192.0.2.61', '192.0.2.63'] as $leaf) {
        $context['tunnels']['192.0.2.1'][$leaf] = ['ifname' => 'vtep.32769', 'port_id' => 11, 'mac_count' => 7];
        $context['tunnels'][$leaf]['192.0.2.1'] = ['ifname' => 'vtep.32769', 'port_id' => 12, 'mac_count' => 7];
        $context['neighbours']['192.0.2.1'][] = $leaf;
        $context['neighbours'][$leaf][] = '192.0.2.1';
    }
    $context['flood']['192.0.2.1'][10010] = ['192.0.2.61'];
    $context['flood']['192.0.2.1'][10020] = ['192.0.2.63'];
    $context['flood']['192.0.2.61'][10010][] = '192.0.2.1';
    $context['flood']['192.0.2.63'][10020] = ['192.0.2.1'];

    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38', ['vni' => 10010]);
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28', ['vni' => 10020]);
    $routed = RoutedPath::through($context['irbs'], gatewayFabric(), '192.0.2.61', 10010, '192.0.2.63', 10020);
    $trace = FabricTrace::build($a, $b, [], $context, $routed);

    expect($trace['line'])->toBe(
        '02:00:00:00:11:40 (ge-0/0/38)[EVPN-CORE01-BER1](et-0/0/52.2121) <-> (et-0/0/52.2121)[EVPN-CORE01-BER2](et-0/0/10)'
        . ' <-> (et-0/0/11)[GW1: irb.10 → irb.20](et-0/0/11) <-> (et-0/0/10)[EVPN-CORE01-BER2](et-0/0/50.2261)'
        . ' <-> (et-0/0/53.2261)[EVPN-CORE02-RLG1](ge-0/0/28) 02:00:00:00:11:41',
    )
        ->and($trace['routed']['gateway_name'])->toBe('GW1')
        ->and($trace['routed']['context'])->toBe('master')
        ->and($trace['local_switching'])->toBeFalse()
        ->and($trace['warnings'])->toBe([])
        ->and($trace['checks'][0])->toMatchArray(['id' => 'routed', 'ok' => true])
        ->and($trace['checks'][1])->toMatchArray(['id' => 'irb-up', 'ok' => true])
        // the flood list of each leg is asked with that leg's VNI, which is the whole
        // reason a routed flow is two legs and not one path
        ->and(array_column($trace['checks'], 'id'))->toContain('flood:192.0.2.61:192.0.2.1:10010')
        ->and(array_column($trace['checks'], 'id'))->toContain('flood:192.0.2.1:192.0.2.63:10020')
        ->and(array_filter($trace['checks'], fn ($c) => $c['ok'] === false))->toBe([]);
});

it('does not claim a routed flow is up when the gateway IRB of one VNI is down', function () {
    $context = traceContext();
    $context['names']['192.0.2.1'] = 'GW1';
    $context['irbs'] = ['192.0.2.1' => [10010 => irb('irb.10'), 10020 => irb('irb.20', status: 'Down')]];
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38', ['vni' => 10010]);
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28', ['vni' => 10020]);

    $routed = RoutedPath::through($context['irbs'], gatewayFabric(), '192.0.2.61', 10010, '192.0.2.63', 10020);
    $trace = FabricTrace::build($a, $b, [], $context, $routed);
    $irbCheck = $trace['checks'][1];

    expect($irbCheck['id'])->toBe('irb-up')
        ->and($irbCheck['ok'])->toBeFalse()
        ->and($irbCheck['detail'])->toBe('irb.10 Up, irb.20 Down');
});

it('says a routed gateway is unconfirmed rather than good when its L3 context was never polled', function () {
    $context = traceContext();
    $context['names']['192.0.2.1'] = 'GW1';
    $context['irbs'] = ['192.0.2.1' => [10010 => irb('irb.10', context: null), 10020 => irb('irb.20', context: null)]];
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38', ['vni' => 10010]);
    $b = traceEndpoint('020000001141', '192.0.2.63', 'ge-0/0/28', ['vni' => 10020]);

    $routed = RoutedPath::through($context['irbs'], gatewayFabric(), '192.0.2.61', 10010, '192.0.2.63', 10020);
    $trace = FabricTrace::build($a, $b, [], $context, $routed);

    expect($trace['checks'][0]['ok'])->toBeNull()
        ->and($trace['checks'][0]['detail'])->toContain('has not been polled yet');
});

it('routes two endpoints on one leaf through that leaf, with no VXLAN and no local-switching claim', function () {
    $context = traceContext();
    $context['irbs'] = ['192.0.2.61' => [10010 => irb('irb.10'), 10020 => irb('irb.20')]];
    $a = traceEndpoint('020000001140', '192.0.2.61', 'ge-0/0/38', ['vni' => 10010]);
    $b = traceEndpoint('020000001141', '192.0.2.61', 'ae2', ['vni' => 10020]);

    $routed = RoutedPath::through($context['irbs'], gatewayFabric(), '192.0.2.61', 10010, '192.0.2.61', 10020);
    $trace = FabricTrace::build($a, $b, [], $context, $routed);

    expect($trace['same_leaf'])->toBeTrue()
        ->and($trace['local_switching'])->toBeFalse()
        ->and($trace['line'])->toBe('02:00:00:00:11:40 (ge-0/0/38)[EVPN-CORE01-BER1: irb.10 → irb.20](ae2) 02:00:00:00:11:41')
        ->and($trace['path'])->toBe([])
        // nothing is encapsulated, so there is no tunnel or flood question to ask
        ->and(array_column($trace['checks'], 'id'))->toBe(['routed', 'irb-up']);
});

it('puts a multihomed MAC on the PEs that own the segment, not on the leaf that reported it', function () {
    // the live defect: every leaf in the fabric reports an ESI-attached MAC with the segment
    // as its active source, so the old code attributed it to whichever leaf sorted first --
    // EVPN-CORE01-BER1 for an endpoint that hangs off EVPN-CORE01-HRO1 ae48
    $members = [
        1 => ['address' => '192.0.2.61', 'name' => 'EVPN-CORE01-BER1'],
        2 => ['address' => '192.0.2.62', 'name' => 'EVPN-CORE01-BER2'],
        3 => ['address' => '192.0.2.63', 'name' => 'EVPN-CORE01-HRO1'],
    ];
    $esi = '01:00:50:00:11:00:00:00:30:00';
    $peers = [
        ['device_id' => 3, 'ifname' => 'ae48', 'port_id' => 91, 'is_df' => true],
        ['device_id' => 2, 'ifname' => 'ae48', 'port_id' => 92, 'is_df' => false],
    ];
    // BER1, BER2 and HRO1 each report the same segment for the same MAC
    $plugin = array_map(fn (int $deviceId) => [
        'device_id' => $deviceId, 'mac' => '7c5a1c78e149', 'vni' => 103, 'ips' => ['185.71.122.244'],
        'source_type' => 'esi', 'source' => $esi, 'ifname' => null, 'port_id' => null, 'esi' => $esi,
        'peers' => $peers, 'is_duplicate' => false, 'moves' => 0,
    ], [1, 2, 3]);

    $result = EndpointResolver::select(['kind' => 'ip', 'value' => '185.71.122.244', 'q' => '185.71.122.244'], ['plugin' => $plugin, 'fdb' => [], 'arp' => []], $members);
    $best = \SafferIt\LibrenmsNetconf\Fabric\Trace\TraceRunner::pick($result['candidates']);

    expect($best->name)->toBe('EVPN-CORE01-HRO1')
        ->and($best->ifname)->toBe('ae48')
        ->and($best->portId)->toBe(91)
        ->and($best->vni)->toBe(103)
        ->and($best->isAttachment())->toBeTrue()
        ->and($best->evidence[0])->toContain('DF')
        // three reporting leaves and two PEs is two candidates, not six
        ->and($result['candidates'])->toHaveCount(2)
        ->and(array_map(fn ($c) => $c->name, $result['candidates']))->toBe(['EVPN-CORE01-HRO1', 'EVPN-CORE01-BER2']);
});

it('does not claim a segment is on the reporting leaf when no PE has a LAG for it', function () {
    $members = [1 => ['address' => '192.0.2.61', 'name' => 'EVPN-CORE01-BER1']];
    $plugin = [[
        'device_id' => 1, 'mac' => '7c5a1c78e149', 'vni' => 103, 'ips' => [],
        'source_type' => 'esi', 'source' => '01:00:50:00:11:00:00:00:30:00', 'ifname' => null, 'port_id' => null,
        'esi' => '01:00:50:00:11:00:00:00:30:00', 'peers' => [], 'is_duplicate' => false, 'moves' => 0,
    ]];

    $result = EndpointResolver::select(['kind' => 'mac', 'value' => '7c5a1c78e149', 'q' => '7c5a1c78e149'], ['plugin' => $plugin, 'fdb' => [], 'arp' => []], $members);

    expect($result['candidates'][0]->isAttachment())->toBeFalse()
        ->and($result['candidates'][0]->evidence[0])->toContain('where it attaches is unknown')
        ->and($result['notes'])->toContain('Learnt from the fabric, but no leaf claims it locally: no access port anywhere.');
});
