<?php

use SafferIt\LibrenmsNetconf\NetconfSettings;

it('ships sane defaults', function () {
    $defaults = NetconfSettings::defaults();

    expect($defaults['transport'])->toBe('cli')
        ->and($defaults['port'])->toBe(22)
        ->and($defaults['auth_order'])->toBe('key,password')
        ->and($defaults['enable_by_default'])->toBeFalse();
});

it('overlays stored values and casts them like the defaults', function () {
    $effective = NetconfSettings::effective([
        'port' => '830',
        'transport' => 'netconf',
        'enable_by_default' => '1',
        'username' => '',
        'command_timeout' => 'abc',
    ]);

    expect($effective['port'])->toBe(830)
        ->and($effective['transport'])->toBe('netconf')
        ->and($effective['enable_by_default'])->toBeTrue()
        ->and($effective['username'])->toBe('')
        ->and($effective['command_timeout'])->toBe('abc');
});
