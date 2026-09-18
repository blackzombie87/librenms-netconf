<?php

use SafferIt\LibrenmsNetconf\Support\SettingsSecrets;

$encrypt = fn (string $plain) => 'ENC(' . $plain . ')';

it('encrypts newly entered secrets and strips the clear flags', function () use ($encrypt) {
    $result = SettingsSecrets::protect(
        ['username' => 'u', 'password' => 'new-pw', 'key_passphrase' => '', 'password_clear' => '0'],
        [],
        $encrypt
    );

    expect($result)->toBe(['username' => 'u', 'password' => 'crypt:ENC(new-pw)', 'key_passphrase' => '']);
});

it('keeps the stored secret when the field is submitted empty', function () use ($encrypt) {
    $result = SettingsSecrets::protect(
        ['password' => '', 'key_passphrase' => ''],
        ['password' => 'crypt:OLD', 'key_passphrase' => 'crypt:OLDPP'],
        $encrypt
    );

    expect($result['password'])->toBe('crypt:OLD')
        ->and($result['key_passphrase'])->toBe('crypt:OLDPP');
});

it('removes a secret when the clear flag is set', function () use ($encrypt) {
    $result = SettingsSecrets::protect(
        ['password' => '', 'password_clear' => '1', 'key_passphrase' => 'keep-me'],
        ['password' => 'crypt:OLD'],
        $encrypt
    );

    expect($result['password'])->toBe('')
        ->and($result['key_passphrase'])->toBe('crypt:ENC(keep-me)')
        ->and($result)->not->toHaveKey('password_clear');
});

it('does not double-encrypt values that are already sealed', function () use ($encrypt) {
    $result = SettingsSecrets::protect(['password' => 'crypt:ALREADY', 'key_passphrase' => ''], [], $encrypt);

    expect($result['password'])->toBe('crypt:ALREADY');
});
