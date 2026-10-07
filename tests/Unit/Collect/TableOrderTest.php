<?php

use SafferIt\LibrenmsNetconf\Collect\TableWriter;

it('orders upsert records by their key columns, one per key, the last one winning', function () {
    $records = [
        ['vni' => 83, 'mac_address' => 'bb', 'source' => 'first'],
        ['vni' => 10, 'mac_address' => 'zz', 'source' => 'x'],
        ['vni' => 83, 'mac_address' => 'aa', 'source' => 'y'],
        ['vni' => 83, 'mac_address' => 'bb', 'source' => 'second'],
    ];

    $ordered = TableWriter::ordered($records, ['vni', 'mac_address']);

    expect(array_map(fn ($r) => $r['vni'] . '/' . $r['mac_address'] . '/' . $r['source'], $ordered))
        ->toBe(['10/zz/x', '83/aa/y', '83/bb/second']);
});

it('gives the same order whatever order the device answered in', function () {
    $a = [['k' => 3], ['k' => 1], ['k' => 2]];
    $b = [['k' => 2], ['k' => 3], ['k' => 1]];

    expect(TableWriter::ordered($a, ['k']))->toBe(TableWriter::ordered($b, ['k']));
});
