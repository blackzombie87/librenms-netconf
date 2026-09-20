<?php

use SafferIt\LibrenmsNetconf\Fabric\View\MacSearch;

it('classifies what the user typed', function (string $q, ?string $kind, string $value) {
    expect(MacSearch::classify($q))->toMatchArray(['kind' => $kind, 'value' => $value]);
})->with([
    ['', null, ''],
    ['00:11:22:aa:bb:cc', 'mac', '001122aabbcc'],
    ['0011.22AA.bbcc', 'mac', '001122aabbcc'],
    ['00-11-22-aa-bb-cc', 'mac', '001122aabbcc'],
    ['001122aabbcc', 'mac', '001122aabbcc'],
    ['00:11:22', 'mac-prefix', '001122'],
    ['00:11:2', 'text', '00:11:2'],
    ['192.0.2.10', 'ip', '192.0.2.10'],
    ['2001:db8::1', 'ip', '2001:db8::1'],
    ['10010', 'vni', '10010'],
    ['server-42', 'text', 'server-42'],
]);

it('formats a 12-hex MAC with colons and leaves anything else alone', function () {
    expect(MacSearch::readable('001122aabbcc'))->toBe('00:11:22:aa:bb:cc')
        ->and(MacSearch::readable('0011'))->toBe('0011');
});
