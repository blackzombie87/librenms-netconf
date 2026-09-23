<?php

use SafferIt\LibrenmsNetconf\Support\IpSort;

it('orders IPv4 addresses numerically, not as text', function () {
    expect(IpSort::sort(['10.10.0.1', '10.9.0.1', '10.100.0.1', '10.9.0.2']))
        ->toBe(['10.9.0.1', '10.9.0.2', '10.10.0.1', '10.100.0.1'])
        ->and(IpSort::lowest(['10.10.0.1', '10.9.0.1']))->toBe('10.9.0.1');
});

it('orders IPv6 addresses by their bytes and puts them after IPv4', function () {
    expect(IpSort::sort(['2001:db8::10', '192.0.2.1', '2001:db8::2', '::1']))
        ->toBe(['192.0.2.1', '::1', '2001:db8::2', '2001:db8::10'])
        // the same address written two ways is one position
        ->and(IpSort::compare('2001:db8::1', '2001:0db8:0000::0001'))->toBe(0);
});

it('keeps what is not an address last, in a stable order', function () {
    expect(IpSort::sort(['leaf-b', '10.0.0.1', 'leaf-a', '2001:db8::1', '']))
        ->toBe(['10.0.0.1', '2001:db8::1', '', 'leaf-a', 'leaf-b'])
        ->and(IpSort::lowest([]))->toBeNull()
        ->and(IpSort::lowest(['not-an-ip']))->toBe('not-an-ip');
});
