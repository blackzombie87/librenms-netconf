<?php

use SafferIt\LibrenmsNetconf\Fabric\EsiLinks;
use SafferIt\LibrenmsNetconf\Fabric\View\EsiAttached;

/**
 * @param  array<string, mixed>  $extra
 * @return array{local_port_id: int, protocol: ?string, remote_device_id: int, remote_hostname: string, remote_port: string}
 */
function attachedLink(int $portId, int $remoteDevice, string $hostname, array $extra = []): array
{
    return $extra + ['local_port_id' => $portId, 'protocol' => 'lldp', 'remote_device_id' => $remoteDevice, 'remote_hostname' => $hostname, 'remote_port' => 'eth0'];
}

it('merges one far end seen on the AE and on a member port into one record with two attachments', function () {
    $sides = [['esi' => '01:aa', 'device_id' => 3, 'port_id' => 44]];
    $stack = [['high_port_id' => 44, 'low_port_id' => 45]];
    $links = [attachedLink(44, 5, 'server-a'), attachedLink(45, 5, 'server-a', ['remote_port' => 'eth1'])];

    $out = EsiAttached::select($sides, $stack, $links, [], [5 => ['server-a']]);

    expect($out)->toHaveCount(1)
        ->and($out[0]['device_id'])->toBe(5)
        ->and($out[0]['label'])->toBe('server-a')
        ->and($out[0]['attachments'])->toHaveCount(2)
        ->and(array_column($out[0]['attachments'], 'port_id'))->toBe([44, 45]);
});

it('merges the same server seen from both PEs, one of which never resolved the device', function () {
    $sides = [['esi' => '01:aa', 'device_id' => 3, 'port_id' => 44], ['esi' => '01:aa', 'device_id' => 7, 'port_id' => 91]];
    $links = [attachedLink(44, 5, 'server-a'), attachedLink(91, 0, 'Server-A ')];

    $out = EsiAttached::select($sides, [], $links, [], [5 => ['server-a', 'Server A']]);

    expect($out)->toHaveCount(1)
        ->and($out[0]['device_id'])->toBe(5)
        ->and(array_column($out[0]['attachments'], 'device_id'))->toBe([3, 7]);
});

it('keeps an unresolved far end with a different hostname as its own record', function () {
    $sides = [['esi' => '01:aa', 'device_id' => 3, 'port_id' => 44], ['esi' => '01:aa', 'device_id' => 7, 'port_id' => 91]];
    $links = [attachedLink(44, 5, 'server-a'), attachedLink(91, 0, 'server-b')];

    $out = EsiAttached::select($sides, [], $links, [], [5 => ['server-a']]);

    expect($out)->toHaveCount(2)
        ->and(array_column($out, 'label'))->toBe(['server-a', 'server-b'])
        ->and($out[1]['device_id'])->toBeNull();
});

it('drops the plugin own multihoming links and any far end that is a fabric member', function () {
    $sides = [['esi' => '01:aa', 'device_id' => 3, 'port_id' => 44]];
    $links = [
        attachedLink(44, 7, 'leaf-2', ['protocol' => EsiLinks::PROTOCOL]),   // the plugin wrote this one
        attachedLink(44, 9, 'leaf-3'),                                       // LLDP, but it is a member
        attachedLink(44, 5, 'server-a'),
    ];

    $out = EsiAttached::select($sides, [], $links, [7 => true, 9 => true], []);

    expect($out)->toHaveCount(1)
        ->and($out[0]['device_id'])->toBe(5);
});

it('keeps the records of two ESIs apart and is deterministic', function () {
    $sides = [['esi' => '01:bb', 'device_id' => 3, 'port_id' => 44], ['esi' => '01:aa', 'device_id' => 3, 'port_id' => 50]];
    $links = [attachedLink(44, 0, 'zulu'), attachedLink(50, 0, 'alpha')];

    $out = EsiAttached::select($sides, [], $links, [], []);

    expect(array_column($out, 'esi'))->toBe(['01:aa', '01:bb'])
        ->and(array_column($out, 'label'))->toBe(['alpha', 'zulu'])
        ->and($out)->toEqual(EsiAttached::select($sides, [], $links, [], []));
});

it('ignores a stack row whose aggregator is not an ESI-LAG side', function () {
    // a device that stored the stack the other way round only yields neighbours on the AE
    // itself, which is the fail-closed direction
    $sides = [['esi' => '01:aa', 'device_id' => 3, 'port_id' => 44]];
    $reversed = [['high_port_id' => 45, 'low_port_id' => 44]];
    $links = [attachedLink(45, 0, 'server-a')];

    expect(EsiAttached::select($sides, $reversed, $links, [], []))->toBe([]);
});
