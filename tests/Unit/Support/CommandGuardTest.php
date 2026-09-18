<?php

use SafferIt\LibrenmsNetconf\Support\CommandGuard;

it('accepts plain show commands including the pipes the transport strips', function () {
    expect(CommandGuard::reject('show version'))->toBeNull()
        ->and(CommandGuard::reject('  SHOW   evpn instance extensive '))->toBeNull()
        ->and(CommandGuard::reject('show route summary | display xml | no-more'))->toBeNull()
        ->and(CommandGuard::reject('show configure-me-not'))->toBeNull()   // not "show configuration"
        ->and(CommandGuard::reject('show interfaces terse et-0/0/0'))->toBeNull();
});

it('rejects chaining, other pipes, multi-line input and configuration dumps', function () {
    expect(CommandGuard::reject('show version; request system reboot'))->toContain('chaining')
        ->and(CommandGuard::reject('show version | match foo'))->toContain('pipes')
        ->and(CommandGuard::reject("show version\nrequest system reboot"))->toContain('single line')
        ->and(CommandGuard::reject('request system reboot'))->toContain('only "show')
        ->and(CommandGuard::reject('show'))->toContain('only "show')
        ->and(CommandGuard::reject('showversion'))->toContain('only "show')
        ->and(CommandGuard::reject('show configuration'))->toContain('configuration')
        ->and(CommandGuard::reject('show configuration system login'))->toContain('configuration')
        ->and(CommandGuard::reject('show conf | display set'))->toContain('pipes')
        ->and(CommandGuard::reject('show config'))->toContain('configuration');
});
