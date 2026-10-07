<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * Re-runs a database write that InnoDB rolled back because of a lock conflict (deadlock,
 * SQLSTATE 40001 / error 1213, or a lock wait timeout, 1205). The victim statement is rolled
 * back completely, so running it again is safe for idempotent writes (upserts, deletes by id).
 */
final class DeadlockRetry
{
    public const ATTEMPTS = 4;

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @param  (callable(int): void)|null  $pause  gets the failed attempt number; sleeps with jitter by default
     * @return T
     */
    public static function run(callable $write, int $attempts = self::ATTEMPTS, ?callable $pause = null): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $write();
            } catch (\Throwable $e) {
                if ($attempt >= $attempts || ! self::isLockConflict($e)) {
                    throw $e;
                }
                $pause === null ? usleep(random_int(50_000, 250_000) * $attempt) : $pause($attempt);
            }
        }
    }

    public static function isLockConflict(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $info = property_exists($cause, 'errorInfo') ? $cause->errorInfo : null;
            if (is_array($info) && (($info[0] ?? null) === '40001' || in_array((int) ($info[1] ?? 0), [1213, 1205], true))) {
                return true;
            }
            if (preg_match('/SQLSTATE\[40001\]|Deadlock found when trying to get lock|Lock wait timeout exceeded/', $cause->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * What to log for a failure: a database error without the statement. A QueryException
     * carries the whole SQL with every bound value (thousands of MACs and addresses for a
     * table upsert), which belongs neither in the eventlog nor in the status row.
     */
    public static function describe(\Throwable $e): string
    {
        $info = property_exists($e, 'errorInfo') ? $e->errorInfo : null;
        if (is_array($info) && isset($info[0])) {
            return sprintf('SQLSTATE[%s] %s %s', $info[0], $info[1] ?? '', $info[2] ?? '');
        }

        // Laravel appends " (Connection: …, SQL: …)" to the driver message
        return preg_replace('/ \(Connection: .*$/s', '', $e->getMessage()) ?? $e->getMessage();
    }
}
