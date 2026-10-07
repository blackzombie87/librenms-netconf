<?php

use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

/**
 * The tunnel mappings against an MX (23.4R2): its remote tunnels are `Remote`, where a switch
 * says `Shared Remote`, and it lists each tunnel once, in the per-instance entry, which has to
 * carry the mode and the next-hop as well. Addresses in the fixtures are documentation ranges.
 *
 * @return array<string, array<string, mixed>> remote VTEP => values
 */
function mxTunnels(string $mapping, string $fixture): array
{
    $definition = (new DefinitionLoader([DefinitionLoader::shippedDirectory()]))->get('junos-evpn-fabric');
    $rows = [];
    foreach ($definition->tables as $table) {
        if ($table->id === $mapping) {
            foreach ((new Extractor)->tables($definition, $table, new XmlDocument(fixture($fixture))) as $row) {
                $rows[$row->key] = $row->values;
            }
        }
    }

    return $rows;
}

it('reads the remote tunnels of an MX, whose type is Remote and not Shared Remote, and not its source IFL', function () {
    $rows = mxTunnels('tunnel', 'junos/show-interfaces-vtep-mx.xml');

    expect(array_keys($rows))->toBe(['198.51.100.253', '192.0.2.11'])
        ->and($rows['192.0.2.11'])->toBe(['remote_vtep_ip' => '192.0.2.11', 'ifname' => 'vtep.32772', 'snmp_index' => 697]);
});

it('still reads the Shared Remote tunnels of a switch', function () {
    $rows = mxTunnels('tunnel', 'junos/show-interfaces-vtep.xml');

    expect($rows)->not->toBeEmpty()
        ->and($rows['192.0.2.21']['ifname'])->toBe('vtep.32770');
});

it('takes the mode and the next-hop from the instance entry, the only one an MX has', function () {
    $mx = mxTunnels('tunnel-instance', 'junos/show-mac-vrf-forwarding-vxlan-tunnel-end-point-remote-mx.xml');
    $switch = mxTunnels('tunnel-instance', 'junos/show-mac-vrf-forwarding-vxlan-tunnel-end-point-remote.xml');
    $real = mxTunnels('tunnel-nexthop', 'junos/show-mac-vrf-forwarding-vxlan-tunnel-end-point-remote.xml');

    expect($mx['192.0.2.11'])->toBe(['remote_vtep_ip' => '192.0.2.11', 'ri_ifname' => 'vtep.32772', 'mode' => 'RNVE', 'nh_id' => 2990])
        // on a switch the two entries of a tunnel agree, so the later mapping overwrites with the same value
        ->and($switch['192.0.2.11']['nh_id'])->toBe($real['192.0.2.11']['nh_id'])
        ->and($switch['192.0.2.11']['mode'])->toBe($real['192.0.2.11']['mode']);
});
