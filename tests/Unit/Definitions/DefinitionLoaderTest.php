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
