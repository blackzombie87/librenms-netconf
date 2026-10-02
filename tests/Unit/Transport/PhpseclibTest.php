<?php

use SafferIt\LibrenmsNetconf\Transport\Phpseclib;

it('uses the installed phpseclib major', function () {
    // the plugin's own vendor carries phpseclib 3; LibreNMS from librenms#20677 on ships 4
    expect(Phpseclib::major())->toBe(class_exists('phpseclib4\Net\SSH2') ? 4 : 3)
        ->and(Phpseclib::ssh('192.0.2.1', 830, 1))->toBeInstanceOf(Phpseclib::major() === 4 ? 'phpseclib4\Net\SSH2' : 'phpseclib3\Net\SSH2');
});

it('reads the protocol errors where phpseclib still exposes them', function () {
    $v3 = new class {
        /** @return list<string|null> */
        public function getErrors(): array
        {
            return ['SSH_MSG_USERAUTH_FAILURE', '', null];
        }
    };

    expect(Phpseclib::errors($v3))->toBe(['SSH_MSG_USERAUTH_FAILURE'])
        ->and(Phpseclib::errors(new stdClass))->toBe([]);   // phpseclib 4 keeps them private
});

it('loads an unencrypted key without a passphrase', function () {
    $key = \phpseclib3\Crypt\EC::createKey('Ed25519')->toString('OpenSSH');

    expect(Phpseclib::loadPrivateKey($key, null))->toBeInstanceOf(\phpseclib3\Crypt\Common\PrivateKey::class);
});
