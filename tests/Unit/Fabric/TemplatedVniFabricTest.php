<?php

use SafferIt\LibrenmsNetconf\Collect\Collector;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Fabric\View\VniMatrix;
use SafferIt\LibrenmsNetconf\Transport\FakeTransport;

/**
 * The first production fabric (plan §10): 12 leaves, one fleet-wide VLAN template, 285 VNIs
 * instantiated on every leaf and 12 of them live on this one. The `-templated` fixtures are the
 * anonymised capture of leaf 192.0.2.13 (plan X10), and the fabric below is that leaf's own view
 * mirrored onto the other eleven: every leaf instantiates all 285, and announces exactly the set
 * the captured flood lists show it announcing.
 *
 * Against 1.2.x this produced 21,395 `vni-flood-gap` criticals — the number the instance showed —
 * because the check read the *source* table ("instantiated") as if it were an advertisement.
 */
function templatedFixture(): array
{
    static $tables = null;
    if ($tables === null) {
        $dir = __DIR__ . '/../../fixtures/junos/';
        $transport = (new FakeTransport)
            ->on('show evpn instance extensive', (string) file_get_contents($dir . 'show-evpn-instance-extensive-templated.xml'))
            ->on('show mac-vrf forwarding vxlan-tunnel-end-point source', (string) file_get_contents($dir . 'show-mac-vrf-forwarding-vxlan-tunnel-end-point-source-templated.xml'))
            ->on('show mac-vrf forwarding vxlan-tunnel-end-point remote', (string) file_get_contents($dir . 'show-mac-vrf-forwarding-vxlan-tunnel-end-point-remote-templated.xml'));
        $definitions = (new DefinitionLoader([DefinitionLoader::shippedDirectory()]))->all();
        $result = (new Collector($transport))->collect([$definitions['junos-evpn-fabric']]);
        $tables = ['vni' => [], 'vni_vtep' => [], 'neighbor' => []];
        foreach ($result->definitions['junos-evpn-fabric']->tables as $row) {
            if (isset($tables[$row->mapping->table])) {
                $tables[$row->mapping->table][] = $row->values;
            }
        }
    }

    return $tables;
}

/**
 * VNI => the addresses that announce it, from the captured flood lists, plus the capturing leaf
 * itself for the VNIs whose bridge domain has an up interface.
 *
 * @return array{vnis: list<int>, advertisers: array<int, list<string>>, self: string}
 */
function templatedFabric(): array
{
    $tables = templatedFixture();
    $vnis = array_values(array_unique(array_map(fn ($r) => (int) $r['vni'], $tables['vni'])));
    sort($vnis);
    $self = (string) $tables['vni'][0]['source_vtep'];

    $advertisers = [];
    foreach ($tables['vni_vtep'] as $row) {
        $advertisers[(int) $row['vni']][(string) $row['remote_vtep_ip']] = true;
    }
    // the capturing leaf announces the VNIs whose bridge domain has an up interface
    foreach (templatedLiveVnis() as $vni) {
        $advertisers[$vni][$self] = true;
    }

    return ['vnis' => $vnis, 'advertisers' => array_map(fn ($set) => array_keys($set), $advertisers), 'self' => $self];
}

/**
 * The VNIs of the captured leaf whose bridge domain has an up interface — what Junos sends a
 * type-3 route for. Read straight from `show evpn instance extensive`: the plugin has no column
 * for it yet (plan §10.3, deferred), and the fixture is the evidence that the two sets differ.
 *
 * @return list<int>
 */
function templatedLiveVnis(): array
{
    $xml = new DOMDocument;
    $xml->loadXML((string) file_get_contents(__DIR__ . '/../../fixtures/junos/show-evpn-instance-extensive-templated.xml'));
    $live = [];
    foreach ((new DOMXPath($xml))->query('//*[local-name()="bridge-domain"]') as $domain) {
        $figures = [];
        foreach ($domain->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $figures[$child->localName] = trim($child->textContent);
            }
        }
        if ((int) ($figures['interfaces-up'] ?? 0) > 0) {
            $live[] = (int) $figures['domain-id'];   // VLAN-based bundle: domain-id == VNI here
        }
    }
    sort($live);

    return $live;
}

/**
 * The fabric as VniMatrix sees it: 12 leaves, every leaf carrying all 285 VNIs, every leaf's
 * flood list holding the leaves that announce the VNI. $drop removes one (leaf, VNI, peer).
 *
 * @param  array{0: string, 1: int, 2: string}|null  $drop
 * @return array{vniRows: list<array<string, mixed>>, floodRows: list<array<string, mixed>>, nodes: array<int, list<string>>}
 */
function templatedMatrixInput(?array $drop = null): array
{
    $fabric = templatedFabric();
    // the monitored leaves: the capturing one and its documentation-range peers (the two
    // 198.51.100.x neighbours are the MX204 edge routers, which were never NETCONF-polled)
    $leaves = array_values(array_unique(array_merge([$fabric['self']], array_filter(
        array_merge(...array_values($fabric['advertisers'])),
        fn (string $ip) => str_starts_with($ip, '192.0.2.'),
    ))));
    sort($leaves);

    $nodes = [];
    $deviceOf = [];
    foreach ($leaves as $i => $ip) {
        $nodes[$i + 1] = [$ip];
        $deviceOf[$ip] = $i + 1;
    }

    $vniRows = [];
    $floodRows = [];
    foreach ($leaves as $ip) {
        $deviceId = $deviceOf[$ip];
        foreach ($fabric['vnis'] as $vni) {
            $vniRows[] = ['device_id' => $deviceId, 'vni' => $vni, 'instance' => 'default-switch', 'vlan_id' => null, 'vlan_name' => "VX$vni", 'source_vtep' => $ip, 'multicast_group' => '0.0.0.0', 'irb_ifname' => null, 'irb_status' => null, 'remote_macs' => 0];
            foreach ($fabric['advertisers'][$vni] ?? [] as $peer) {
                if ($peer !== $ip && isset($deviceOf[$peer]) && $drop !== [$ip, $vni, $peer]) {
                    $floodRows[] = ['device_id' => $deviceId, 'vni' => $vni, 'remote_vtep_ip' => $peer];
                }
            }
        }
    }

    return ['vniRows' => $vniRows, 'floodRows' => $floodRows, 'nodes' => $nodes];
}

it('keeps the evidence the capture was taken for', function () {
    $tables = templatedFixture();
    $flood = [];
    foreach ($tables['vni_vtep'] as $row) {
        $flood[(string) $row['remote_vtep_ip']][(int) $row['vni']] = true;
    }
    $imet = [];
    foreach ($tables['neighbor'] as $row) {
        $imet[(string) $row['neighbor_ip']] = ($imet[(string) $row['neighbor_ip']] ?? 0) + (int) $row['imet_routes'];
    }
    ksort($flood);
    ksort($imet);

    expect($tables['vni'])->toHaveCount(285)                       // instantiated from the VLAN template
        ->and(templatedLiveVnis())->toBe([70, 71, 72, 401, 403, 407, 1123, 1701, 1702, 1703, 1704, 1705])
        ->and($tables['vni_vtep'])->toHaveCount(1577)
        // every peer's flood list is exactly as long as its IMET route count: a flood list is
        // what the peer *advertises*, which is the whole of plan §10.3
        ->and(array_map('count', $flood))->toBe($imet);
});

it('reports no flood-list gap on the production fabric it was captured from', function () {
    ['vniRows' => $vniRows, 'floodRows' => $floodRows, 'nodes' => $nodes] = templatedMatrixInput();
    $rows = VniMatrix::build($vniRows, $floodRows, $nodes);

    $gaps = array_sum(array_map(fn ($r) => count($r['gaps']), $rows));
    $flagged = array_values(array_filter($rows, fn ($r) => $r['flags'] !== []));

    // 12 leaves × 285 VNIs, 1,475 of the 3,420 (leaf, VNI) pairs advertised
    $advertised = array_sum(array_map(fn ($r) => count($r['advertised']), $rows));
    expect($nodes)->toHaveCount(12)
        ->and($rows)->toHaveCount(285)
        ->and($advertised)->toBe(1475)
        // what 1.2.x flagged: every silent (leaf, VNI) pair, once per other carrier
        ->and((12 * 285 - $advertised) * 11)->toBe(21395)
        ->and($gaps)->toBe(0)
        ->and($flagged)->toBe([]);
});

it('still reports the one leaf that really drops out of a flood list', function () {
    $live = templatedLiveVnis()[0];
    $fabric = templatedFabric();
    $peer = $fabric['self'];                       // announces $live, so its absence is a gap
    $blind = '192.0.2.11';                         // the leaf whose flood list loses it
    ['vniRows' => $vniRows, 'floodRows' => $floodRows, 'nodes' => $nodes] = templatedMatrixInput([$blind, $live, $peer]);

    $rows = array_column(VniMatrix::build($vniRows, $floodRows, $nodes), null, 'vni');
    $gaps = array_sum(array_map(fn ($r) => count($r['gaps']), $rows));

    expect($gaps)->toBe(1)
        ->and($rows[$live]['flags'])->toBe(['flood-gap'])
        ->and($rows[$live]['gaps'][0]['device_id'])->toBe(array_search([$blind], $nodes, true))
        ->and($rows[$live]['gaps'][0]['missing'])->toBe(array_search([$peer], $nodes, true));
});
