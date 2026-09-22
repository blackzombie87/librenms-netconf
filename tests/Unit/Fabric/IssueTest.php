<?php

use SafferIt\LibrenmsNetconf\Fabric\Checks\Issue;

/**
 * The identity of a finding (F5 5): check plus a digest of the subject, the same string for
 * lookup, dedupe, insert and clear, and short enough for the 191-character column however
 * long the subject (an ESI plus an instance name, a MAC plus VNI plus device) gets.
 */
it('keys an issue by check and the digest of its subject', function () {
    $issue = new Issue('dup-mac', Issue::CRITICAL, '12/MACVRF-A', 'msg');

    expect($issue->key())->toBe('dup-mac|' . sha1('12/MACVRF-A'))
        ->and(Issue::keyFor('dup-mac', '12/MACVRF-A'))->toBe($issue->key())
        ->and(strlen((new Issue('esi-lacp-degraded', Issue::WARNING, str_repeat('x', 500), 'm'))->key()))->toBeLessThanOrEqual(191);
});

it('keeps two subjects apart that agree on their first 191 characters', function () {
    $prefix = str_repeat('00:11:22:33:44:55:00:00:01:00/', 7);
    $a = new Issue('esi-lag-down', Issue::CRITICAL, $prefix . '/leaf-a', 'a');
    $b = new Issue('esi-lag-down', Issue::CRITICAL, $prefix . '/leaf-b', 'b');

    expect(mb_strlen($prefix))->toBeGreaterThan(191)
        ->and(Issue::legacyKey('esi-lag-down', $a->subject))->toBe(Issue::legacyKey('esi-lag-down', $b->subject))
        ->and($a->key())->not->toBe($b->key());
});
