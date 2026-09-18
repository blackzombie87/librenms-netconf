<?php

use SafferIt\LibrenmsNetconf\Transport\KnownHosts;

function keyBlob(string $type, string $seed): string
{
    return base64_encode(pack('N', strlen($type)) . $type . hash('sha256', $seed, true));
}

function knownHostsFile(array $lines): string
{
    $file = tempnam(sys_get_temp_dir(), 'kh');
    file_put_contents($file, implode("\n", $lines) . "\n");

    return $file;
}

$a = keyBlob('ssh-ed25519', 'a');
$b = keyBlob('ssh-rsa', 'b');
$c = keyBlob('ssh-ed25519', 'c');
$d = keyBlob('ssh-ed25519', 'd');
$salt = random_bytes(20);
$hashed = '|1|' . base64_encode($salt) . '|' . base64_encode(hash_hmac('sha1', 'hashed.example.net', $salt, true));

$file = knownHostsFile([
    '# comment line',
    '',
    "leaf1.example.net ssh-ed25519 $a comment",
    "[leaf2.example.net]:830 ssh-rsa $b",
    "$hashed ssh-ed25519 $c",
    "*.lab.example.net,!bad.lab.example.net ssh-ed25519 $a",
    "@revoked leaf3.example.net ssh-ed25519 $d",
    "@cert-authority *.example.net ssh-ed25519 $d",
    'garbage-line-with-two-fields x',
]);
$known = new KnownHosts($file);

it('accepts a matching plain entry and reports mismatch or unknown', function () use ($known, $a, $b) {
    expect($known->check('leaf1.example.net', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::OK)
        ->and($known->check('LEAF1.example.net', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::OK)
        ->and($known->check('leaf1.example.net', 22, "ssh-rsa $b"))->toBe(KnownHosts::MISMATCH)
        ->and($known->check('nobody.example.org', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::UNKNOWN);
});

it('matches the [host]:port form for non-default ports', function () use ($known, $a, $b) {
    expect($known->check('leaf2.example.net', 830, "rsa-sha2-512 $b"))->toBe(KnownHosts::OK)   // phpseclib reports the signature algorithm
        ->and($known->check('leaf2.example.net', 22, "ssh-rsa $b"))->toBe(KnownHosts::UNKNOWN)
        ->and($known->check('leaf1.example.net', 830, "ssh-ed25519 $a"))->toBe(KnownHosts::OK);  // plain entry also covers other ports
});

it('matches hashed entries, wildcards with negation and revoked keys', function () use ($known, $a, $c, $d) {
    expect($known->check('hashed.example.net', 22, "ssh-ed25519 $c"))->toBe(KnownHosts::OK)
        ->and($known->check('hashed.example.net', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::MISMATCH)
        ->and($known->check('x.lab.example.net', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::OK)
        ->and($known->check('bad.lab.example.net', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::UNKNOWN)
        ->and($known->check('leaf3.example.net', 22, "ssh-ed25519 $d"))->toBe(KnownHosts::REVOKED)
        ->and($known->check('leaf3.example.net', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::UNKNOWN);   // cert-authority lines are ignored
});

it('formats fingerprints and known_hosts lines', function () use ($a, $b) {
    expect(KnownHosts::fingerprint("ssh-ed25519 $a"))->toMatch('/^SHA256:[A-Za-z0-9+\/]{43}$/')
        ->and(KnownHosts::fingerprint("ssh-ed25519 $a"))->toBe('SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($a), true)), '='))
        ->and(KnownHosts::typeFromBlob($b))->toBe('ssh-rsa')
        ->and(KnownHosts::typeFromBlob('not-base64!'))->toBeNull()
        ->and(KnownHosts::line('leaf2.example.net', 830, "rsa-sha2-512 $b"))->toBe("[leaf2.example.net]:830 ssh-rsa $b")
        ->and(KnownHosts::line('leaf1.example.net', 22, "ssh-ed25519 $a"))->toBe("leaf1.example.net ssh-ed25519 $a");
});

it('treats a missing file as unreadable with no entries', function () use ($a) {
    $known = new KnownHosts('/nonexistent/known_hosts');

    expect($known->isReadable())->toBeFalse()
        ->and($known->check('leaf1.example.net', 22, "ssh-ed25519 $a"))->toBe(KnownHosts::UNKNOWN);
});

afterAll(function () use ($file) {
    @unlink($file);
});
