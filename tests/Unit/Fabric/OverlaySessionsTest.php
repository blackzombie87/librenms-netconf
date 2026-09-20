<?php

use SafferIt\LibrenmsNetconf\Fabric\View\OverlaySessions;

/**
 * "Missing session" heuristic of the BGP overlay tab (plan §7.5 check 2): a member lacks a
 * peer that the other monitored members have.
 */
function overlaySession(int $device, string $peer): array
{
    return ['device_id' => $device, 'peer_ip' => $peer, 'state' => 'Established', 'up' => true];
}

it('flags a member that lacks a peer two others have', function () {
    $sessions = [
        overlaySession(1, '192.0.2.1'), overlaySession(1, '192.0.2.2'),
        overlaySession(2, '192.0.2.1'), overlaySession(2, '192.0.2.2'),
        overlaySession(3, '192.0.2.1'),
    ];
    $nodes = [1 => ['192.0.2.11'], 2 => ['192.0.2.12'], 3 => ['192.0.2.13']];

    expect(OverlaySessions::missing($sessions, $nodes))->toBe([
        ['device_id' => 3, 'peer_ip' => '192.0.2.2', 'have' => [1, 2]],
    ]);
});

it('never reports a member as missing a session towards itself', function () {
    // full mesh of three leaves: everyone peers with everyone else
    $sessions = [
        overlaySession(1, '192.0.2.12'), overlaySession(1, '192.0.2.13'),
        overlaySession(2, '192.0.2.11'), overlaySession(2, '192.0.2.13'),
        overlaySession(3, '192.0.2.11'), overlaySession(3, '192.0.2.12'),
    ];
    $nodes = [1 => ['192.0.2.11'], 2 => ['192.0.2.12'], 3 => ['192.0.2.13']];

    expect(OverlaySessions::missing($sessions, $nodes))->toBe([]);
});

it('needs at least two monitored members and lowers the threshold for exactly two', function () {
    $nodes2 = [1 => ['192.0.2.11'], 2 => ['192.0.2.12']];
    $sessions = [overlaySession(1, '192.0.2.1'), overlaySession(1, '192.0.2.12'), overlaySession(2, '192.0.2.11')];

    expect(OverlaySessions::missing($sessions, [1 => ['192.0.2.11']]))->toBe([])
        ->and(OverlaySessions::missing($sessions, $nodes2))->toBe([
            ['device_id' => 2, 'peer_ip' => '192.0.2.1', 'have' => [1]],
        ]);
});
