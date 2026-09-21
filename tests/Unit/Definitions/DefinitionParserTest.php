<?php

use SafferIt\LibrenmsNetconf\Definitions\DefinitionException;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Definitions\DeviceFacts;

function minimal(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'test-def',
        'match' => ['os' => 'junos'],
        'commands' => ['ver' => 'show version'],
        'sensors' => [[
            'class' => 'count',
            'command' => 'ver',
            'index' => "'x'",
            'descr' => 'X',
            'value' => 'count(//a)',
        ]],
    ], $overrides);
}

function parseDef(array $data)
{
    return (new DefinitionParser)->parse($data, 'test.yaml');
}

it('parses a minimal definition with defaults', function () {
    $d = parseDef(minimal());

    expect($d->name)->toBe('test-def')
        ->and($d->commands['ver']->cli)->toBe('show version')
        ->and($d->commands['ver']->optional)->toBeFalse()
        ->and($d->commands['ver']->every)->toBe(1)
        ->and($d->sensors[0]->id)->toBe('sensor1')
        ->and($d->sensors[0]->class)->toBe('count')
        ->and($d->matches(new DeviceFacts(os: 'junos')))->toBeTrue()
        ->and($d->matches(new DeviceFacts(os: 'ios')))->toBeFalse();
});

it('rejects unknown keys, bad names and missing pieces', function () {
    expect(fn () => parseDef(minimal(['bogus' => 1])))->toThrow(DefinitionException::class, 'unknown key')
        ->and(fn () => parseDef(minimal(['name' => 'Bad Name'])))->toThrow(DefinitionException::class, 'lowercase')
        ->and(fn () => parseDef(['name' => 'x', 'commands' => []]))->toThrow(DefinitionException::class, 'at least one command')
        ->and(fn () => parseDef(['name' => 'x', 'commands' => ['a' => 'show a']]))->toThrow(DefinitionException::class, 'no sensors, ports, metrics or tables');
});

it('only allows show commands and exactly one of cli/rpc', function () {
    expect(fn () => parseDef(minimal(['commands' => ['ver' => 'request system reboot']])))->toThrow(DefinitionException::class, 'only "show ..."')
        ->and(fn () => parseDef(minimal(['commands' => ['ver' => ['cli' => 'show a', 'rpc' => '<x/>']]])))->toThrow(DefinitionException::class, 'exactly one of cli or rpc')
        ->and(parseDef(minimal(['commands' => ['ver' => ['rpc' => '<get-software-information/>']]]))->commands['ver']->isRpc())->toBeTrue();
});

it('checks command references and xpath syntax', function () {
    expect(fn () => parseDef(minimal(['sensors' => [['command' => 'nope']]])))->toThrow(DefinitionException::class, 'unknown command "nope"')
        ->and(fn () => parseDef(minimal(['sensors' => [['value' => 'count(//a']]])))->toThrow(DefinitionException::class, 'sensors[0].value')
        ->and(fn () => parseDef(minimal(['sensors' => [['rows' => '//a[']]])))->toThrow(DefinitionException::class, 'sensors[0].rows');
});

it('accepts {n} placeholders in repeat mappings', function () {
    $d = parseDef(minimal(['sensors' => [['repeat' => 'count(v)', 'value' => 'number(v[{n}])', 'index' => "concat('n', '{n}')"]]]));

    expect($d->sensors[0]->repeat)->toBe('count(v)');
});

it('validates state sensors', function () {
    $state = ['class' => 'state', 'value' => 'string(s)', 'states' => ['Up' => ['value' => 1, 'generic' => 0], 'Down' => ['value' => 2, 'generic' => 2]]];

    $d = parseDef(minimal(['sensors' => [$state]]));
    expect($d->sensors[0]->states)->toHaveCount(2)
        ->and($d->sensors[0]->resolveState('Down')?->generic)->toBe(2)
        ->and($d->sensors[0]->resolveState('weird'))->toBeNull();

    expect(fn () => parseDef(minimal(['sensors' => [['class' => 'state', 'value' => 'string(s)']]])))->toThrow(DefinitionException::class, 'states list')
        ->and(fn () => parseDef(minimal(['sensors' => [array_replace($state, ['states' => ['Up' => ['value' => 1, 'generic' => 0], 'Dup' => ['value' => 1, 'generic' => 0]]])]])))->toThrow(DefinitionException::class, 'already used')
        ->and(fn () => parseDef(minimal(['sensors' => [array_replace($state, ['states' => ['Up' => ['value' => 1, 'generic' => 9]]])]])))->toThrow(DefinitionException::class, 'generic must be');
});

it('parses list-style states with regex and default', function () {
    $d = parseDef(minimal(['sensors' => [['class' => 'state', 'value' => 'string(s)', 'states' => [
        ['label' => 'Resolved', 'match' => '/^Resolved/', 'value' => 1, 'generic' => 0],
        ['label' => 'Other', 'match' => '/.*/', 'value' => 3, 'generic' => 3, 'default' => true],
    ]]]]));

    expect($d->sensors[0]->resolveState('Resolved by IFL ae2.0')?->label)->toBe('Resolved')
        ->and($d->sensors[0]->resolveState('???')?->label)->toBe('Other');
});

it('parses ports and metrics with field shorthand and types', function () {
    $d = parseDef(minimal([
        'sensors' => null,
        'ports' => [[
            'command' => 'ver', 'rows' => '//physical-interface',
            'match' => ['port_field' => 'ifIndex', 'xpath' => 'string(snmp-index)'],
            'metrics' => ['crc' => ['xpath' => 'number(crc)', 'type' => 'counter'], 'bpdu' => 'number(b)'],
        ]],
        'metrics' => [[
            'command' => 'ver', 'rows' => '//t', 'index' => 'string(name)',
            'fields' => [['name' => 'total', 'xpath' => 'number(total)']],
        ]],
    ]));

    expect($d->sensors)->toBe([])
        ->and($d->ports[0]->matchField)->toBe('ifIndex')
        ->and($d->ports[0]->metrics[0]->type)->toBe('COUNTER')
        ->and($d->ports[0]->metrics[1]->type)->toBe('GAUGE')
        ->and($d->metrics[0]->fields[0]->name)->toBe('total')
        ->and($d->metrics[0]->descr)->toBe('{index}');

    expect(fn () => parseDef(minimal(['ports' => [['command' => 'ver', 'rows' => '//a', 'match' => ['port_field' => 'ifWhat', 'xpath' => 'x'], 'metrics' => ['a' => 'b']]]])))
        ->toThrow(DefinitionException::class, 'port_field')
        ->and(fn () => parseDef(minimal(['metrics' => [['command' => 'ver', 'index' => "'i'", 'fields' => ['this_name_is_far_too_long' => 'x']]]])))
        ->toThrow(DefinitionException::class, '1-19 characters')
        ->and(fn () => parseDef(minimal(['metrics' => [['command' => 'ver', 'index' => "'i'", 'fields' => ['a' => ['xpath' => 'x', 'type' => 'ABSOLUTE']]]]])))
        ->toThrow(DefinitionException::class, 'GAUGE, COUNTER, DERIVE or string');
});

it('accepts label-only string fields in metrics but not in ports', function () {
    $d = parseDef(minimal(['metrics' => [[
        'command' => 'ver', 'index' => "'i'",
        'fields' => ['total' => 'number(t)', 'state' => ['xpath' => 'string(s)', 'type' => 'string']],
    ]]]));

    expect($d->metrics[0]->fields[1]->type)->toBe('STRING')
        ->and($d->metrics[0]->fields[1]->isText())->toBeTrue()
        ->and($d->metrics[0]->fields[0]->isText())->toBeFalse();

    expect(fn () => parseDef(minimal(['ports' => [['command' => 'ver', 'rows' => '//a', 'match' => ['port_field' => 'ifIndex', 'xpath' => 'x'], 'metrics' => ['a' => ['xpath' => 'x', 'type' => 'string']]]]])))
        ->toThrow(DefinitionException::class, 'GAUGE, COUNTER or DERIVE');
});

it('rejects duplicate ids', function () {
    $s = ['class' => 'count', 'command' => 'ver', 'index' => "'x'", 'descr' => 'X', 'value' => 'count(//a)', 'id' => 'same'];

    expect(fn () => parseDef(minimal(['sensors' => [$s, $s]])))->toThrow(DefinitionException::class, 'duplicate id "same"');
});

it('matches with regex, lists and attribs', function () {
    $d = parseDef(minimal(['match' => ['os' => ['junos', 'junos-evo'], 'hardware' => '/^ex46/i', 'attrib' => 'netconf_evpn']]));

    expect($d->matches(new DeviceFacts(os: 'junos-evo', hardware: 'EX4650-48Y', attribs: ['netconf_evpn' => '1'])))->toBeTrue()
        ->and($d->matches(new DeviceFacts(os: 'junos', hardware: 'EX4650-48Y', attribs: ['netconf_evpn' => '0'])))->toBeFalse()
        ->and($d->matches(new DeviceFacts(os: 'junos', hardware: 'SRX345', attribs: ['netconf_evpn' => 'yes'])))->toBeFalse()
        ->and($d->match->describe())->toBe(['os' => 'junos|junos-evo', 'hardware' => '/^ex46/i', 'attrib' => 'netconf_evpn']);
});

it('parses table mappings against the table schema', function () {
    $d = parseDef(minimal(['sensors' => null, 'tables' => [[
        'table' => 'vni',
        'command' => 'ver',
        'rows' => '//vxlan-format',
        'columns' => [
            'vni' => 'number(vn-id)',
            'instance' => ['xpath' => 'string(routing-instance-name)'],
            'source_vtep' => 'string(../../source-vtep-address)',
        ],
    ]]]));

    expect($d->tables)->toHaveCount(1)
        ->and($d->tables[0]->id)->toBe('table1')
        ->and($d->tables[0]->table)->toBe('vni')
        ->and($d->tables[0]->keyColumns())->toBe(['vni'])
        ->and($d->tables[0]->columnNames())->toBe(['vni', 'instance', 'source_vtep'])
        ->and($d->tables[0]->columns[0]->type)->toBe('int')
        ->and($d->tables[0]->columns[2]->type)->toBe('ip')
        ->and($d->counts()['tables'])->toBe(1);
});

it('rejects table mappings that do not fit the schema', function () {
    $table = fn (array $overrides) => parseDef(minimal(['tables' => [array_replace_recursive([
        'table' => 'vni', 'command' => 'ver', 'columns' => ['vni' => 'number(vn-id)'],
    ], $overrides)]]));

    expect(fn () => $table(['table' => 'vtep']))->toThrow(DefinitionException::class, 'unknown table "vtep"')
        ->and(fn () => $table(['columns' => ['bogus' => 'string(x)']]))->toThrow(DefinitionException::class, 'unknown column of table "vni"')
        ->and(fn () => parseDef(minimal(['tables' => [['table' => 'vni', 'command' => 'ver', 'columns' => ['instance' => 'string(x)']]]])))->toThrow(DefinitionException::class, 'key column "vni" of table "vni" is required')
        ->and(fn () => $table(['columns' => ['vni' => ['xpath' => 'number(vn-id)', 'transform' => 'shout']]]))->toThrow(DefinitionException::class, 'transform')
        ->and(fn () => $table(['columns' => ['vni' => 'number(vn-id']]))->toThrow(DefinitionException::class, 'tables[0].columns.vni')
        ->and(fn () => parseDef(minimal(['tables' => [['table' => 'vni', 'command' => 'ver', 'columns' => []]]])))->toThrow(DefinitionException::class, 'at least one column');
});

it('splices a command filter in at {filter} or appends it', function () {
    $appended = parseDef(minimal(['commands' => ['ver' => ['cli' => 'show interfaces extensive', 'filter' => 'xe-0/0/*']]]))->commands['ver'];
    $placed = parseDef(minimal(['commands' => ['ver' => ['cli' => 'show interfaces {filter} extensive', 'filter' => ' et-* ']]]))->commands['ver'];
    $unfiltered = parseDef(minimal(['commands' => ['ver' => ['cli' => 'show interfaces {filter} extensive']]]))->commands['ver'];

    expect($appended->filter)->toBe('xe-0/0/*')
        ->and($appended->command())->toBe('show interfaces extensive xe-0/0/*')
        ->and($appended->label())->toBe('show interfaces extensive xe-0/0/*')
        ->and($appended->identity())->toBe('cli:show interfaces extensive xe-0/0/*')
        ->and($placed->command())->toBe('show interfaces et-* extensive')
        ->and($unfiltered->filter)->toBeNull()
        ->and($unfiltered->command())->toBe('show interfaces extensive')
        ->and($appended->merge($placed)->filter)->toBe('xe-0/0/*');
});

it('rejects filters on rpc commands and filters that chain or pipe', function () {
    expect(fn () => parseDef(minimal(['commands' => ['ver' => ['rpc' => '<x/>', 'filter' => 'a']]])))->toThrow(DefinitionException::class, 'only cli commands')
        ->and(fn () => parseDef(minimal(['commands' => ['ver' => ['cli' => 'show a', 'filter' => 'x | display']]])))->toThrow(DefinitionException::class, 'single line')
        ->and(fn () => parseDef(minimal(['commands' => ['ver' => ['cli' => 'show a', 'filter' => "x\nrequest"]]])))->toThrow(DefinitionException::class, 'single line')
        ->and(fn () => parseDef(minimal(['commands' => ['ver' => ['cli' => 'show a', 'filter' => ['x']]]])))->toThrow(DefinitionException::class, 'must be a string')
        ->and(parseDef(minimal(['commands' => ['ver' => ['cli' => 'show a', 'filter' => '']]]))->commands['ver']->filter)->toBeNull();
});
