<?php

use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

function defWith(array $sections): \SafferIt\LibrenmsNetconf\Definitions\Definition
{
    return (new DefinitionParser)->parse(['name' => 'ex', 'commands' => ['c' => 'show x']] + $sections, 'ex.yaml');
}

it('creates one sensor per row with templated descriptions', function () {
    $def = defWith(['sensors' => [[
        'class' => 'count', 'command' => 'c', 'rows' => '//route-table', 'index' => 'string(table-name)',
        'descr' => 'Hidden routes {index}', 'value' => 'number(hidden-route-count)', 'group' => 'Routing', 'limit' => 0,
    ]]]);
    $doc = new XmlDocument(fixture('junos/show-route-summary.xml'));

    $sensors = (new Extractor)->sensors($def, $def->sensors[0], $doc);

    expect($sensors)->toHaveCount(4)
        ->and($sensors[0]->index)->toBe('inet.0')
        ->and($sensors[0]->descr)->toBe('Hidden routes inet.0')
        ->and($sensors[0]->value)->toBe(0.0)
        ->and($sensors[0]->type())->toBe('netconf-ex-sensor1')
        ->and($sensors[0]->toArray()['limit'])->toBe(0.0);
});

it('evaluates a single sensor against the whole document when rows is omitted', function () {
    $def = defWith(['sensors' => [['class' => 'count', 'command' => 'c', 'index' => "'total'", 'descr' => 'Dup MACs', 'value' => 'count(//mac-entry)']]]);
    $doc = new XmlDocument(fixture('junos/show-evpn-database-state-duplicate-empty.xml'));

    $sensors = (new Extractor)->sensors($def, $def->sensors[0], $doc);

    expect($sensors)->toHaveCount(1)
        ->and($sensors[0]->index)->toBe('total')
        ->and($sensors[0]->value)->toBe(0.0);
});

it('counts the suppressed MACs per instance in the positive duplicate-MAC sample', function () {
    // G3 sample 2026-09-22 (EX4650 VC, 23.4R2-S8.7): the element shape equals the normal
    // "show evpn database", one mac-entry whose active-source is an ESI
    $def = defWith(['sensors' => [
        ['id' => 'per-instance', 'class' => 'count', 'command' => 'c', 'rows' => '//evpn-database-instance', 'index' => 'string(instance-name)', 'descr' => '{index}', 'value' => 'count(mac-entry)'],
        ['id' => 'total', 'class' => 'count', 'command' => 'c', 'index' => "'total'", 'descr' => 'Dup MACs', 'value' => 'count(//mac-entry)'],
    ]]);
    $doc = new XmlDocument(fixture('junos/show-evpn-database-state-duplicate.xml'));

    $perInstance = (new Extractor)->sensors($def, $def->sensors[0], $doc);
    $total = (new Extractor)->sensors($def, $def->sensors[1], $doc);

    expect(array_map(fn ($s) => [$s->index, $s->value], $perInstance))->toBe([['mgmt-vrf', 1.0]])
        ->and($total[0]->value)->toBe(1.0)
        ->and($doc->scalar('string(//mac-entry/active-source)'))->toBe('00:11:22:33:44:55:00:00:06:00')
        ->and($doc->scalar('string(//mac-entry/vni-id)'))->toBe('3001250');
});

it('reads the mobility history of the per-MAC extensive sample', function () {
    // the same MAC ten minutes earlier on the peer ESI-LAG: one local mobility event, sequence 1
    $doc = new XmlDocument(fixture('junos/show-evpn-database-mac-address-extensive.xml'));

    expect($doc->scalar('count(//mobility-history/mobility-event)'))->toBe(1.0)
        ->and($doc->scalar('string(//mobility-event/event-type)'))->toBe('local')
        ->and($doc->scalar('string(//mac-source/source-local-origin)'))->toBe('ae5.0')
        ->and($doc->scalar('string(//mac-source/source-mobility-seq-num)'))->toBe('1')
        ->and($doc->scalar('string(//mac-source/source-status)'))->toBe('Active');
});

it('skips rows without a value, filters with when and warns on duplicates', function () {
    $doc = new XmlDocument('<r><i><n>a</n><v>1</v></i><i><n>b</n></i><i><n>a</n><v>3</v></i><i><n>c</n><v>x</v><skip/></i></r>');
    $def = defWith(['sensors' => [['class' => 'count', 'command' => 'c', 'rows' => '//i', 'when' => 'not(skip)', 'index' => 'string(n)', 'descr' => '{index}', 'value' => 'number(v)']]]);
    $x = new Extractor;

    $sensors = $x->sensors($def, $def->sensors[0], $doc);

    expect(array_map(fn ($s) => $s->index, $sensors))->toBe(['a'])
        ->and($x->warnings())->toHaveCount(2)
        ->and($x->warnings()[0])->toContain('[b]: no value')
        ->and($x->warnings()[1])->toContain('duplicate index "a"');
});

it('resolves state sensors including regex and default states', function () {
    $def = defWith(['sensors' => [[
        'class' => 'state', 'command' => 'c', 'rows' => '//evpn-esi[evpn-esi-local-intf-information]',
        'index' => 'string(evpn-esi-local-intf-information/evpn-esi-local-intf-name)', 'descr' => 'ESI {index} ({row:evpn-esi-value})',
        'value' => 'string(evpn-esi-status)',
        'states' => ['Resolved' => ['match' => '/^Resolved/', 'value' => 1, 'generic' => 0], 'Unresolved' => ['value' => 2, 'generic' => 2], 'Unknown' => ['match' => '/.*/', 'value' => 3, 'generic' => 3, 'default' => true]],
    ]]]);
    $doc = new XmlDocument(fixture('junos/show-evpn-instance-extensive.xml'));

    $sensors = (new Extractor)->sensors($def, $def->sensors[0], $doc);

    expect($sensors)->toHaveCount(2)
        ->and($sensors[0]->state?->label)->toBe('Resolved')
        ->and($sensors[0]->value)->toBe(1.0)
        ->and($sensors[0]->rawText)->toStartWith('Resolved')
        ->and($sensors[0]->descr)->toMatch('/^ESI ae\d\.0 \(00:11:22/');
});

it('expands flattened tables with repeat and {n}', function () {
    $def = defWith(['sensors' => [[
        'class' => 'state', 'command' => 'c', 'rows' => '//redundancy-group', 'repeat' => 'count(device-stats/device-name)',
        'index' => "concat('RG', redundancy-group-id, '/', device-stats/device-name[{n}])", 'descr' => 'Cluster {index}',
        'value' => 'string(device-stats/redundancy-group-status[{n}])',
        'states' => ['primary' => ['value' => 1, 'generic' => 0], 'secondary' => ['value' => 2, 'generic' => 0]],
    ]]]);
    $doc = new XmlDocument(fixture('junos/show-chassis-cluster-status.xml'));

    $sensors = (new Extractor)->sensors($def, $def->sensors[0], $doc);

    expect(array_map(fn ($s) => $s->index . '=' . $s->state?->label, $sensors))
        ->toBe(['RG0/node0=primary', 'RG0/node1=secondary', 'RG1/node0=primary', 'RG1/node1=secondary']);
});

it('applies multiplier and divisor and value_any fallbacks', function () {
    $doc = new XmlDocument('<r><new>1500</new></r>');
    $def = defWith(['sensors' => [
        ['class' => 'count', 'command' => 'c', 'index' => "'t'", 'descr' => 'T', 'value_any' => ['number(//old)', 'number(//new)'], 'divisor' => 10],
        ['class' => 'count', 'command' => 'c', 'index' => "'u'", 'descr' => 'U', 'value' => 'number(//new)', 'multiplier' => 2],
    ]]);
    $x = new Extractor;

    expect($x->sensors($def, $def->sensors[0], $doc)[0]->value)->toBe(150.0)
        ->and($x->sensors($def, $def->sensors[1], $doc)[0]->value)->toBe(3000.0);
});

it('extracts port metric rows keyed by ifIndex and skips non-numeric fields', function () {
    $def = defWith(['ports' => [[
        'command' => 'c', 'rows' => '//physical-interface[snmp-index]', 'match' => ['port_field' => 'ifIndex', 'xpath' => 'string(snmp-index)'],
        'metrics' => [
            'crc_in' => ['xpath' => 'number(ethernet-mac-statistics/input-crc-errors)', 'type' => 'COUNTER'],
            'bpdu' => "number(bpdu-error != 'none')",
            'missing' => 'number(no-such-element)',
            'text' => 'string(name)',
        ],
    ]]]);
    $doc = new XmlDocument(fixture('junos/show-interfaces-extensive.xml'));
    $x = new Extractor;

    $rows = $x->ports($def, $def->ports[0], $doc);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->matchField)->toBe('ifIndex')
        ->and($rows[0]->matchValue)->toBe('626')
        ->and($rows[0]->values)->toHaveKeys(['crc_in', 'bpdu'])
        ->and($rows[0]->values['bpdu'])->toBe(0.0)
        ->and($rows[0]->types['crc_in'])->toBe('COUNTER')
        ->and($rows[0]->values)->not->toHaveKey('missing')
        ->and($x->warnings())->toHaveCount(1)
        ->and($x->warnings()[0])->toContain('text "et-0/0/49" is not numeric');
});

it('extracts metric rows with numeric and string fields', function () {
    $def = defWith(['metrics' => [[
        'command' => 'c', 'rows' => '//bgp-peer', 'index' => 'string(peer-address)', 'descr' => 'BGP peer {index} AS{row:peer-as}',
        'fields' => ['flaps' => ['xpath' => 'number(flap-count)', 'type' => 'COUNTER'], 'uptime' => 'number(elapsed-time/@seconds)', 'state' => 'string(peer-state)'],
    ]]]);
    $doc = new XmlDocument(fixture('junos/show-bgp-summary.xml'));

    $rows = (new Extractor)->metrics($def, $def->metrics[0], $doc);

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->descr)->toMatch('/^BGP peer \S+ AS\d+$/')
        ->and($rows[0]->values)->toHaveKeys(['flaps', 'uptime'])
        ->and($rows[0]->strings['state'])->toBe('Established')
        ->and($rows[0]->types['flaps'])->toBe('COUNTER');
});

it('keeps the RRD data source set stable whatever a row contains', function () {
    $def = defWith(['metrics' => [[
        'command' => 'c', 'index' => "'system'",
        'fields' => [
            'offset_ms' => 'string(//ntp-status/offset)',       // "+0.325185": numeric although string()
            'missing' => 'number(//ntp-status/no-such-node)',   // absent in this reply
            'text' => 'string(//ntp-status/statusinfo)',        // GAUGE typed but not a number
            'flaps' => ['xpath' => 'number(//nope)', 'type' => 'COUNTER'],
            'refid' => ['xpath' => 'string(//ntp-status/refid)', 'type' => 'string'],
            'reach' => ['xpath' => "string('377')", 'type' => 'string'],   // numeric-looking label stays a label
        ],
    ]]]);
    $doc = new XmlDocument(fixture('junos/show-ntp-status.xml'));

    $row = (new Extractor)->metrics($def, $def->metrics[0], $doc)[0];

    expect($row->values)->toBe(['offset_ms' => 0.325185])
        ->and($row->strings)->toHaveKeys(['text', 'refid', 'reach'])
        ->and($row->strings['reach'])->toBe('377')
        ->and(array_keys($row->types))->toBe(['offset_ms', 'missing', 'text', 'flaps'])
        ->and($row->types['flaps'])->toBe('COUNTER')
        ->and($row->rrdValues())->toBe(['offset_ms' => 0.325185, 'missing' => 'U', 'text' => 'U', 'flaps' => 'U'])
        // file order wins over YAML order, extra data sources of the file receive U
        ->and($row->rrdValues(['flaps' => 'COUNTER', 'legacy' => 'GAUGE', 'offset_ms' => 'GAUGE']))->toBe(['flaps' => 'U', 'legacy' => 'U', 'offset_ms' => 0.325185]);

    // port rows: same contract
    $pdef = defWith(['ports' => [[
        'command' => 'c', 'rows' => '//physical-interface[snmp-index]', 'match' => ['port_field' => 'ifIndex', 'xpath' => 'string(snmp-index)'],
        'metrics' => ['missing' => 'number(no-such-element)', 'crc_in' => ['xpath' => 'number(ethernet-mac-statistics/input-crc-errors)', 'type' => 'COUNTER']],
    ]]]);
    $prow = (new Extractor)->ports($pdef, $pdef->ports[0], new XmlDocument(fixture('junos/show-interfaces-extensive.xml')))[0];

    expect(array_keys($prow->rrdValues()))->toBe(['missing', 'crc_in'])
        ->and($prow->rrdValues()['missing'])->toBe('U');
});

it('parses numbers with units and durations', function () {
    expect(Extractor::duration('75w0d 12:49'))->toBe(75 * 604800 + 12 * 3600 + 49 * 60.0)
        ->and(Extractor::duration('1d 02:03:04'))->toBe(86400 + 2 * 3600 + 3 * 60 + 4.0)
        ->and(Extractor::duration('nonsense'))->toBeNull();

    $doc = new XmlDocument('<r><a>1,234 bps</a><b>12.5 %</b><c>abc</c></r>');
    $def = defWith(['metrics' => [['command' => 'c', 'index' => "'x'", 'fields' => ['a' => 'string(a)', 'b' => 'string(b)', 'c' => 'string(c)']]]]);

    $row = (new Extractor)->metrics($def, $def->metrics[0], $doc)[0];

    expect($row->values)->toBe(['a' => 1234.0, 'b' => 12.5])
        ->and($row->strings)->toBe(['c' => 'abc']);
});

it('exposes the routing engine of multi-RE rows', function () {
    $def = defWith(['metrics' => [['command' => 'c', 'rows' => '//software-information', 'index' => '{re}', 'descr' => 'RE {re} {row:product-model}', 'fields' => ['host' => 'string(host-name)']]]]);
    $doc = new XmlDocument(fixture('junos/show-version-multi-re.xml'));

    $row = (new Extractor)->metrics($def, $def->metrics[0], $doc)[0];

    expect($row->index)->toBe('localre')
        ->and($row->re)->toBe('localre')
        ->and($row->descr)->toBe('RE localre ex4650-48y-8c');
});
