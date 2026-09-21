<?php

use SafferIt\LibrenmsNetconf\Fabric\MacMobility;

/**
 * MAC move counting (plan §7.5 check 7): a changed active source is a move, moves inside the
 * window accumulate, a move after the window opens a new one.
 */
it('counts a changed source as a move and opens a window', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $row = ['source' => 'ae1.0', 'moves' => 0, 'moves_recent' => 0, 'moves_since' => null];

    expect(MacMobility::next($row, 'ae1.0', $now))->toBe(['moves' => 0, 'moves_recent' => 0, 'moves_since' => null])
        ->and(MacMobility::next($row, '192.0.2.12', $now))->toBe(['moves' => 1, 'moves_recent' => 1, 'moves_since' => '2026-09-21 10:00:00'])
        ->and(MacMobility::next(null, 'ae1.0', $now))->toBe(['moves' => 0, 'moves_recent' => 0, 'moves_since' => null])
        ->and(MacMobility::next(['source' => null, 'moves' => 2], 'ae1.0', $now))->toBe(['moves' => 2, 'moves_recent' => 0, 'moves_since' => null]);
});

it('accumulates inside the window and restarts after it', function () {
    $row = ['source' => 'ae1.0', 'moves' => 4, 'moves_recent' => 3, 'moves_since' => '2026-09-21 09:30:00'];

    expect(MacMobility::next($row, 'ae2.0', new DateTimeImmutable('2026-09-21 10:00:00')))->toBe(['moves' => 5, 'moves_recent' => 4, 'moves_since' => '2026-09-21 09:30:00'])
        ->and(MacMobility::next($row, 'ae2.0', new DateTimeImmutable('2026-09-21 10:30:00')))->toBe(['moves' => 5, 'moves_recent' => 1, 'moves_since' => '2026-09-21 10:30:00'])
        ->and(MacMobility::inWindow('2026-09-21 09:30:00', new DateTimeImmutable('2026-09-21 10:29:59')))->toBeTrue()
        ->and(MacMobility::inWindow('2026-09-21 09:30:00', new DateTimeImmutable('2026-09-21 10:30:00')))->toBeFalse()
        ->and(MacMobility::inWindow(null, new DateTimeImmutable('2026-09-21 10:30:00')))->toBeFalse();
});
