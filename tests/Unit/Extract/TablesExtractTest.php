<?php

use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

function tableDef(array $mapping): \SafferIt\LibrenmsNetconf\Definitions\Definition
{
    return (new DefinitionParser)->parse([
        'name' => 'tbl',
        'commands' => ['c' => 'show x'],
        'tables' => [['command' => 'c'] + $mapping],
    ], 'tbl.yaml');
}

it('extracts VNI rows with typed columns and the source VTEP from an ancestor', function () {
    $def = tableDef([
        'table' => 'vni',
        'rows' => '//vxlan-format[vn-id]',
        'columns' => [
            'vni' => 'number(vn-id)',
            'instance' => 'string(routing-instance-name)',
            'vlan_name' => "substring-before(bridge-domain-vni-name, '+')",
            'source_vtep' => 'string(ancestor::svtep-format/source-vtep-address)',
            'multicast_group' => 'string(multicast-address)',
            'irb_ifname' => 'string(nothing-here)',
        ],
    ]);
    $doc = new XmlDocument(fixture('junos/show-mac-vrf-forwarding-vxlan-tunnel-end-point-source.xml'));

    $extractor = new Extractor;
    $rows = $extractor->tables($def, $def->tables[0], $doc);

    expect($rows)->toHaveCount(5)
        ->and($rows[0]->key)->toBe('10')
        ->and($rows[0]->values)->toBe([
            'vni' => 10, 'instance' => 'default-switch', 'vlan_name' => 'VX10', 'source_vtep' => '192.0.2.61',
            'multicast_group' => '0.0.0.0', 'irb_ifname' => null,
        ])
        ->and($rows[4]->values['vni'])->toBe(1003)
        ->and($rows[0]->keyValues())->toBe(['vni' => 10])
        ->and($extractor->warnings())->toBe([]);
});

it('collects json columns from node-sets and from space separated text', function () {
    $def = tableDef([
        'table' => 'esi',
        'rows' => '//evpn-esi[evpn-esi-remote-pe-information/evpn-esi-remote-pe]',
        'columns' => [
            'esi' => 'string(evpn-esi-value)',
            'remote_vtep_ips' => 'evpn-esi-remote-pe-information/evpn-esi-remote-pe/evpn-esi-remote-pe-ipaddr',
            'is_df' => 'evpn-esi-df-information/esi-designated-forwarder = ../evpn-router-id',
            'df_ip' => 'string(evpn-esi-df-information/esi-designated-forwarder)',
            'mode' => 'string(evpn-esi-remote-pe-information/evpn-esi-remote-pe[1]/evpn-esi-remote-pe-mode)',
        ],
    ]);
    $rows = (new Extractor)->tables($def, $def->tables[0], new XmlDocument(fixture('junos/show-evpn-instance-extensive.xml')));

    expect(array_map(fn ($r) => $r->key, $rows))->toBe(['00:11:22:33:44:55:00:00:02:00', '00:11:22:33:44:66:00:00:01:00'])
        ->and($rows[0]->values['remote_vtep_ips'])->toBe(['192.0.2.12'])
        ->and($rows[0]->values['is_df'])->toBeTrue()
        ->and($rows[0]->values['df_ip'])->toBe('192.0.2.11')
        ->and($rows[0]->values['mode'])->toBe('all-active')
        ->and($rows[1]->values['remote_vtep_ips'])->toBe(['192.0.2.61', '192.0.2.62'])
        ->and($rows[1]->values['is_df'])->toBeFalse()
        ->and($rows[1]->values['df_ip'])->toBeNull();

    $mac = tableDef([
        'table' => 'mac',
        'rows' => '//l2ald-mac-entry',
        'columns' => [
            'vni' => 'number(preceding-sibling::l2rtb-active-bd-vnid[1])',
            'mac_address' => 'string(l2-mac-address)',
            'ip_addresses' => 'string(l2-remote-vtep-address)',
            'source' => 'string(l2-active-source)',
            'source_type' => ['xpath' => 'string(l2-active-source)', 'transform' => 'evpn_source'],
        ],
    ]);
    $rows = (new Extractor)->tables($mac, $mac->tables[0], new XmlDocument(fixture('junos/show-mac-vrf-forwarding-vxlan-tunnel-end-point-remote-mac-table.xml')));

    expect($rows)->toHaveCount(6)
        ->and($rows[0]->key)->toBe('10/020000000001')
        ->and($rows[0]->values['ip_addresses'])->toBe(['192.0.2.22', '192.0.2.21'])
        ->and($rows[0]->values['source_type'])->toBe('esi')
        ->and($rows[3]->values['vni'])->toBe(1001);
});

it('applies transforms, skips rows without a key and warns on duplicates and bad values', function () {
    $def = tableDef([
        'table' => 'mac',
        'rows' => '//mac-entry',
        'columns' => [
            'vni' => 'number(vni-id)',
            'mac_address' => 'string(mac-address)',
            'instance' => 'string(../instance-name)',
            'ip_addresses' => 'ip-address',
            'source' => 'string(active-source)',
            'source_type' => ['xpath' => 'string(active-source)', 'transform' => 'evpn_source'],
            'active_since' => ['xpath' => 'string(active-source-timestamp)', 'transform' => 'timestamp'],
            'seq' => 'string(mac-address)',
        ],
    ]);
    $extractor = new Extractor;
    $rows = $extractor->tables($def, $def->tables[0], new XmlDocument(fixture('junos/show-evpn-database.xml')));

    $types = array_count_values(array_map(fn ($r) => $r->values['source_type'], $rows));
    expect(count($rows))->toBeGreaterThanOrEqual(8)
        ->and($types)->toHaveKeys(['esi', 'remote', 'local'])
        ->and($rows[0]->values['mac_address'])->toBe('020000000007')
        ->and($rows[0]->values['instance'])->toBe('default-switch')
        ->and($rows[0]->values['ip_addresses'])->toBe(['203.0.113.10'])
        ->and($rows[0]->values['active_since'])->toMatch('/^\d{4}-08-15 22:16:18$/')
        ->and($rows[0]->values['seq'])->toBeNull()
        ->and($extractor->warnings()[0])->toContain('seq "02:00:00:00:00:07" is not a valid int');

    $xml = '<r><e><k>1</k><v>x</v></e><e><k></k><v>y</v></e><e><k>1</k><v>z</v></e></r>';
    $dup = tableDef(['table' => 'vni', 'rows' => '//e', 'columns' => ['vni' => 'string(k)', 'instance' => 'string(v)']]);
    $extractor->resetWarnings();
    $rows = $extractor->tables($dup, $dup->tables[0], new XmlDocument($xml));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->values['instance'])->toBe('x')
        ->and($extractor->warnings())->toBe(['tbl/table1: row without vni, skipped', 'tbl/table1: duplicate key "1", keeping the first row']);
});

it('coerces booleans, IPs, MACs and Junos timestamps', function () {
    expect(Extractor::coerce('Up/Forwarding', 'bool'))->toBeTrue()
        ->and(Extractor::coerce('Down', 'bool'))->toBeFalse()
        ->and(Extractor::coerce('Aliasing', 'bool'))->toBeTrue()
        ->and(Extractor::coerce('maybe', 'bool'))->toBeNull()
        ->and(Extractor::coerce('192.0.2.1', 'ip'))->toBe('192.0.2.1')
        ->and(Extractor::coerce('DF not elected yet', 'ip'))->toBeNull()
        ->and(Extractor::coerce('02:00:00:00:00:0A', 'mac'))->toBe('02000000000a')
        ->and(Extractor::coerce('1,234', 'int'))->toBe(1234)
        ->and(Extractor::evpnSourceType('00:11:22:33:44:55:00:00:02:00'))->toBe('esi')
        ->and(Extractor::evpnSourceType('192.0.2.12'))->toBe('remote')
        ->and(Extractor::evpnSourceType('ge-0/0/28.0'))->toBe('local')
        ->and(Extractor::junosTimestamp('Sep 18 14:15:11', mktime(12, 0, 0, 9, 19, 2026)))->toBe('2026-09-18 14:15:11')
        ->and(Extractor::junosTimestamp('Dec 24 08:00:00', mktime(12, 0, 0, 1, 5, 2026)))->toBe('2025-12-24 08:00:00')
        ->and(Extractor::transform('duration', '1d 02:03:04'))->toBe('93784');
});
