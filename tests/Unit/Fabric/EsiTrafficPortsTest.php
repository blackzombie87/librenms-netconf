<?php

use SafferIt\LibrenmsNetconf\Fabric\View\EsiTrafficPorts;

it('returns the AE port of every PE, sorted, and never a member port', function () {
    // the member port is handed in beside the AEs; if it reached the id list the graph would
    // sum the same traffic twice
    $sides = [
        ['device_id' => 7, 'ifname' => 'ae2.0', 'port_id' => 91, 'esi' => '01:aa'],
        ['device_id' => 3, 'ifname' => 'ae2.0', 'port_id' => 44, 'esi' => '01:aa'],
    ];
    $out = EsiTrafficPorts::select($sides);

    expect($out['port_ids'])->toBe([44, 91])
        ->and($out['per_pe'])->toBe([3 => 44, 7 => 91])
        ->and($out['skipped'])->toBe([])
        ->and($out['excluded'])->toBeFalse()
        ->and($out['port_ids'])->not->toContain(1234);
});

it('names a side whose AE has no core port instead of inventing one', function () {
    $out = EsiTrafficPorts::select([
        ['device_id' => 3, 'ifname' => 'ae2.0', 'port_id' => 44, 'esi' => '01:aa'],
        ['device_id' => 7, 'ifname' => 'ae4', 'port_id' => null, 'esi' => '01:aa'],
    ]);

    expect($out['port_ids'])->toBe([44])
        ->and($out['skipped'])->toBe([['device_id' => 7, 'ifname' => 'ae4']])
        ->and($out['excluded'])->toBeFalse();
});

it('excludes an anycast gateway segment and an empty one', function () {
    $gateway = EsiTrafficPorts::select([
        ['device_id' => 1, 'ifname' => 'irb.101', 'port_id' => 10, 'esi' => '05:aa'],
        ['device_id' => 2, 'ifname' => 'irb.101', 'port_id' => 11, 'esi' => '05:aa'],
    ]);

    expect($gateway['excluded'])->toBeTrue()
        ->and($gateway['port_ids'])->toBe([])
        ->and(EsiTrafficPorts::select([])['excluded'])->toBeTrue();
});

it('still returns one id for a single PE and leaves the view to decide it is not a multiport', function () {
    $out = EsiTrafficPorts::select([['device_id' => 3, 'ifname' => 'ae2.0', 'port_id' => 44, 'esi' => '01:aa']]);

    expect($out['port_ids'])->toBe([44])
        ->and($out['excluded'])->toBeFalse();
});
