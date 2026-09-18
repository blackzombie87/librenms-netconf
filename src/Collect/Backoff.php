<?php

namespace SafferIt\LibrenmsNetconf\Collect;

/**
 * Exponential back-off after failed sessions: skip 1, 2, 4, ... polls, capped.
 */
final class Backoff
{
    public static function skipPolls(int $consecutiveFailures, int $max): int
    {
        if ($consecutiveFailures <= 0) {
            return 0;
        }

        return (int) min(2 ** min($consecutiveFailures - 1, 30), max(1, $max));
    }

    /** Seconds until the next attempt, given the poller interval. */
    public static function delaySeconds(int $consecutiveFailures, int $max, int $pollInterval): int
    {
        return self::skipPolls($consecutiveFailures, $max) * max(60, $pollInterval);
    }
}
