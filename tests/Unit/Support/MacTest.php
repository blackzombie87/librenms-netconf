<?php

use SafferIt\LibrenmsNetconf\Support\Mac;

it('keeps only the hex digits', function () {
    expect(Mac::digits('00:11:22:AA:bb:cc'))->toBe('001122aabbcc')
        ->and(Mac::digits('0011.22aa.bbcc'))->toBe('001122aabbcc')
        ->and(Mac::digits('no mac here'))->toBe('acee')
        ->and(Mac::digits(''))->toBe('');
});

it('accepts a full MAC in any notation as storage form and rejects the rest', function () {
    expect(Mac::hex('00-11-22-aa-bb-cc'))->toBe('001122aabbcc')
        ->and(Mac::hex('001122AABBCC'))->toBe('001122aabbcc')
        ->and(Mac::hex('00:11:22'))->toBeNull()
        ->and(Mac::hex('001122aabbccdd'))->toBeNull();
});

it('formats a full MAC with colons and leaves anything else alone', function () {
    expect(Mac::readable('001122aabbcc'))->toBe('00:11:22:aa:bb:cc')
        ->and(Mac::readable('0011.22aa.bbcc'))->toBe('00:11:22:aa:bb:cc')
        ->and(Mac::readable('0011'))->toBe('0011');
});
