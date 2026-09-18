<?php

use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionMatcher;
use SafferIt\LibrenmsNetconf\Definitions\DeviceFacts;

it('loads every shipped definition without errors', function () {
    $loader = new DefinitionLoader([DefinitionLoader::shippedDirectory()]);
    $all = $loader->all();

    expect($loader->errors())->toBe([])
        ->and(array_keys($all))->toContain('junos-evpn', 'junos-routing', 'junos-interfaces', 'junos-srx-cluster', 'junos-l2', 'junos-alarms', 'junos-evpn-esi');

    foreach ($all as $definition) {
        expect($definition->source)->toEndWith('.yaml');
    }
});

it('lets a later directory override by name and reports broken files', function () {
    $dir = sys_get_temp_dir() . '/netconf-defs-' . uniqid();
    mkdir($dir);
    file_put_contents("$dir/evpn.yaml", "name: junos-evpn\ndescription: user override\nmatch: {os: junos}\ncommands: {a: show a}\nsensors:\n  - {class: count, command: a, index: \"'x'\", descr: X, value: count(//x)}\n");
    file_put_contents("$dir/broken.yaml", "name: broken\ncommands: {a: show a}\n");
    file_put_contents("$dir/notyaml.txt", 'ignored');

    $loader = new DefinitionLoader([DefinitionLoader::shippedDirectory(), $dir]);

    expect($loader->get('junos-evpn')?->description)->toBe('user override')
        ->and($loader->get('junos-evpn')?->source)->toBe("$dir/evpn.yaml")
        ->and($loader->errors())->toHaveCount(1)
        ->and($loader->errors()[0])->toContain('broken.yaml');

    array_map('unlink', glob("$dir/*") ?: []);
    rmdir($dir);
});

it('reloads when a definition file is added, changed or removed', function () {
    $dir = sys_get_temp_dir() . '/netconf-defs-' . uniqid();
    mkdir($dir);
    $yaml = "name: reload-me\ndescription: %s\nmatch: {os: junos}\ncommands: {a: show a}\nsensors:\n  - {class: count, command: a, index: \"'x'\", descr: X, value: count(//x)}\n";
    $loader = new DefinitionLoader([$dir]);

    expect($loader->all())->toBe([]);

    file_put_contents("$dir/r.yaml", sprintf($yaml, 'first'));
    expect($loader->get('reload-me')?->description)->toBe('first');

    file_put_contents("$dir/r.yaml", sprintf($yaml, 'second'));
    touch("$dir/r.yaml", time() + 2);   // same second as the first write on fast machines
    expect($loader->get('reload-me')?->description)->toBe('second');

    unlink("$dir/r.yaml");
    expect($loader->get('reload-me'))->toBeNull();

    rmdir($dir);
});

it('collects parser hints per loaded file', function () {
    $dir = sys_get_temp_dir() . '/netconf-defs-' . uniqid();
    mkdir($dir);
    // index "total" is a bare word: evaluated as an XPath element, most likely meant as a literal
    file_put_contents("$dir/h.yaml", "name: hinted\nmatch: {os: junos}\ncommands: {a: show a}\nsensors:\n  - {class: count, command: a, index: total, descr: X, value: count(//x)}\n");
    $loader = new DefinitionLoader([$dir]);

    expect($loader->hints())->toHaveCount(1)
        ->and($loader->hints()[0])->toContain('h.yaml')->toContain('literal')
        ->and($loader->errors())->toBe([]);

    unlink("$dir/h.yaml");
    rmdir($dir);
});

it('matches shipped definitions to device facts', function () {
    $loader = new DefinitionLoader([DefinitionLoader::shippedDirectory()]);
    $matcher = new DefinitionMatcher;

    $leaf = array_map(fn ($d) => $d->name, $matcher->matching(new DeviceFacts(os: 'junos', hardware: 'EX4650-48Y'), $loader->all()));
    $srx = array_map(fn ($d) => $d->name, $matcher->matching(new DeviceFacts(os: 'junos', hardware: 'Juniper SRX345'), $loader->all()));
    $other = $matcher->matching(new DeviceFacts(os: 'ios'), $loader->all());

    expect($leaf)->toContain('junos-evpn', 'junos-interfaces')
        ->and($leaf)->not->toContain('junos-srx-cluster', 'junos-interface-queues')
        ->and($srx)->toContain('junos-srx-cluster')
        ->and($other)->toBe([]);
});
