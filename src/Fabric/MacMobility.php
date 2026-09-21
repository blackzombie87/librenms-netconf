<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

/**
 * MAC move counting for the EVPN MAC database rows (plan §7.5 check 7): every change of the
 * active source is a move. `moves` counts them for the life of the row, `moves_recent` those
 * inside the current window that started at `moves_since`; a move after the window has
 * passed starts a new one. Pure PHP.
 */
final class MacMobility
{
    public const WINDOW_SECONDS = 3600;

    /**
     * @param  array{source?: string|null, moves?: int|null, moves_recent?: int|null, moves_since?: string|null}|null  $previous  the stored row, null for a new MAC
     * @return array{moves: int, moves_recent: int, moves_since: string|null}
     */
    public static function next(?array $previous, ?string $source, \DateTimeInterface $now, int $window = self::WINDOW_SECONDS): array
    {
        $moves = (int) ($previous['moves'] ?? 0);
        $recent = (int) ($previous['moves_recent'] ?? 0);
        $since = $previous['moves_since'] ?? null;
        $since = $since === null ? null : (string) $since;

        $before = $previous['source'] ?? null;
        if ($previous === null || $before === null || $before === '' || $source === null || $source === '' || $before === $source) {
            return ['moves' => $moves, 'moves_recent' => $recent, 'moves_since' => $since];
        }

        $moves++;
        if ($since === null || ! self::inWindow($since, $now, $window)) {
            return ['moves' => $moves, 'moves_recent' => 1, 'moves_since' => $now->format('Y-m-d H:i:s')];
        }

        return ['moves' => $moves, 'moves_recent' => $recent + 1, 'moves_since' => $since];
    }

    /** Whether a window that started at $since is still open at $now. */
    public static function inWindow(?string $since, \DateTimeInterface $now, int $window = self::WINDOW_SECONDS): bool
    {
        if ($since === null || $since === '') {
            return false;
        }
        $start = strtotime($since);

        return $start !== false && $now->getTimestamp() - $start < $window;
    }
}
