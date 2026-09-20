<?php

use SafferIt\LibrenmsNetconf\Fabric\View\TunnelMatrix;

it('groups tunnels by device and checks the reverse tunnel where the far end is monitored', function () {
    $nodes = [1 => ['192.0.2.11'], 2 => ['192.0.2.12', '10.0.0.2']];
    $result = TunnelMatrix::build(
        [
            ['device_id' => 1, 'remote_vtep_ip' => '10.0.0.2', 'ifname' => 'vtep.32770', 'port_id' => 500],
            ['device_id' => 1, 'remote_vtep_ip' => '192.0.2.99', 'ifname' => 'vtep.32771', 'port_id' => null],
            ['device_id' => 2, 'remote_vtep_ip' => '192.0.2.99', 'ifname' => 'vtep.32769', 'port_id' => null],
        ],
        $nodes,
        fn (int $id) => null,
    );

    expect(array_keys($result['by_device']))->toBe([1, 2])
        ->and($result['by_device'][1][0]['remote_device_id'])->toBe(2)
        ->and($result['by_device'][1][0]['reverse'])->toBeFalse()   // leaf 2 has no tunnel to leaf 1
        ->and($result['by_device'][1][1]['reverse'])->toBeNull()    // far end not monitored
        ->and($result['total'])->toBe(3)
        ->and($result['with_port'])->toBe(0)
        ->and($result['asymmetric'])->toBe(1);
});
