<?php

use SafferIt\LibrenmsNetconf\Transport\CredentialResolver;
use SafferIt\LibrenmsNetconf\Transport\Credentials;

$settings = [
    'username' => 'global-user',
    'password' => 'crypt:GLOBALPW',
    'key_file' => '/keys/global',
    'key_passphrase' => '',
    'port' => 22,
    'transport' => 'cli',
    'auth_order' => 'key,password',
    'connect_timeout' => 7,
    'command_timeout' => 45,
];

it('uses global settings when the device has no attribs', function () use ($settings) {
    $resolver = new CredentialResolver(fn (string $c) => strtolower($c));

    $c = $resolver->resolve('leaf1.example.net', $settings);

    expect($c->host)->toBe('leaf1.example.net')
        ->and($c->username)->toBe('global-user')
        ->and($c->password)->toBe('globalpw')
        ->and($c->keyFile)->toBe('/keys/global')
        ->and($c->keyPassphrase)->toBeNull()
        ->and($c->port)->toBe(22)
        ->and($c->transport)->toBe('cli')
        ->and($c->authOrder)->toBe(['key', 'password'])
        ->and($c->connectTimeout)->toBe(7)
        ->and($c->commandTimeout)->toBe(45);
});

it('lets device attribs override globals and decrypts prefixed secrets only', function () use ($settings) {
    $resolver = new CredentialResolver(fn (string $c) => "dec($c)");

    $c = $resolver->resolve('srx1', $settings, [
        'netconf_username' => 'dev-user',
        'netconf_password' => 'crypt:DEVPW',
        'netconf_keyfile' => '',
        'netconf_key_passphrase' => 'plain-passphrase',
        'netconf_port' => '830',
        'netconf_transport' => 'netconf',
        'unrelated_attrib' => 'x',
    ]);

    expect($c->username)->toBe('dev-user')
        ->and($c->password)->toBe('dec(DEVPW)')
        ->and($c->keyFile)->toBe('/keys/global')
        ->and($c->keyPassphrase)->toBe('plain-passphrase')
        ->and($c->port)->toBe(830)
        ->and($c->isNetconf())->toBeTrue();
});

it('gives cli overrides the highest priority', function () use ($settings) {
    $resolver = new CredentialResolver;

    $c = $resolver->resolve('h', $settings, ['netconf_username' => 'attrib'], [
        'username' => 'cli',
        'password' => 'typed',
        'auth_order' => 'password',
        'command_timeout' => '5',
    ]);

    expect($c->username)->toBe('cli')
        ->and($c->password)->toBe('typed')
        ->and($c->authOrder)->toBe(['password'])
        ->and($c->commandTimeout)->toBe(5);
});

it('falls back to the transport default port when none is usable', function () {
    $resolver = new CredentialResolver;

    expect($resolver->resolve('h', ['transport' => 'netconf', 'port' => ''])->port)->toBe(830)
        ->and($resolver->resolve('h', ['transport' => 'cli', 'port' => '0'])->port)->toBe(22)
        ->and($resolver->resolve('h', ['port' => 70000])->port)->toBe(22);
});

it('passes the known_hosts file through and lets "-" disable it', function () use ($settings) {
    $resolver = new CredentialResolver;

    expect($resolver->resolve('h', $settings)->verifiesHostKey())->toBeFalse()
        ->and($resolver->resolve('h', $settings)->describe()['known_hosts'])->toBe('not verified')
        ->and($resolver->resolve('h', $settings + ['known_hosts' => ' /etc/ssh/known_hosts '])->knownHosts)->toBe('/etc/ssh/known_hosts')
        ->and($resolver->resolve('h', $settings + ['known_hosts' => '/a'], [], ['known_hosts' => '/b'])->knownHosts)->toBe('/b')
        ->and($resolver->resolve('h', $settings + ['known_hosts' => '/a'], [], ['known_hosts' => '-'])->verifiesHostKey())->toBeFalse();
});

it('rejects unknown transports', function () {
    expect(fn () => (new CredentialResolver)->resolve('h', ['transport' => 'telnet']))
        ->toThrow(InvalidArgumentException::class, "Unknown transport 'telnet'");
});

it('parses auth order leniently', function () {
    expect(CredentialResolver::parseAuthOrder('password, key'))->toBe(['password', 'key'])
        ->and(CredentialResolver::parseAuthOrder('key,key,bogus'))->toBe(['key'])
        ->and(CredentialResolver::parseAuthOrder(''))->toBe(['key', 'password'])
        ->and(CredentialResolver::parseAuthOrder(['password']))->toBe(['password']);
});

it('reports usable auth methods and a redacted description', function () {
    $c = new Credentials('h', username: 'u', password: 'secret', authOrder: ['key', 'password']);

    expect($c->usableAuthMethods())->toBe(['password'])
        ->and($c->describe()['password'])->toBe('set')
        ->and($c->describe()['key_file'])->toBe('missing')
        ->and(json_encode($c->describe()))->not->toContain('secret');

    $withKey = $c->with(['keyFile' => '/k', 'password' => null]);
    expect($withKey->usableAuthMethods())->toBe(['key', 'password'])
        ->and($withKey->password)->toBe('secret');
});
