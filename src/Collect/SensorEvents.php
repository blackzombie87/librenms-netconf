<?php

namespace SafferIt\LibrenmsNetconf\Collect;

/**
 * Pure decision logic for the eventlog entries a sensor reading produces (thresholds and
 * state changes). Mirrors core record_sensor_data(), except that a limit is considered
 * crossed when the previous value was *at* the limit and the new one beyond it, so
 * `limit: 0` counters produce an event on 0 -> 1.
 */
final class SensorEvents
{
    public const WARNING = 'warning';

    public const NOTICE = 'notice';

    /**
     * @return list<array{severity: string, message: string}>
     */
    public static function evaluate(
        string $classLabel,
        string $descr,
        string $unit,
        ?float $previous,
        float $current,
        ?float $limit,
        ?float $limitLow,
        bool $alert = true,
        ?string $previousState = null,
        ?string $currentState = null,
    ): array {
        $events = [];
        $unit = $unit !== '' ? " $unit" : '';

        if ($alert && $previous !== null) {
            if ($limitLow !== null && $previous >= $limitLow && $current < $limitLow) {
                $events[] = [
                    'severity' => self::WARNING,
                    'message' => sprintf('%s under threshold: %s%s (< %s%s) - %s', $classLabel, self::num($current), $unit, self::num($limitLow), $unit, $descr),
                ];
            } elseif ($limit !== null && $previous <= $limit && $current > $limit) {
                $events[] = [
                    'severity' => self::WARNING,
                    'message' => sprintf('%s above threshold: %s%s (> %s%s) - %s', $classLabel, self::num($current), $unit, self::num($limit), $unit, $descr),
                ];
            }
        }

        if ($currentState !== null && $previous !== null && $previous != $current) {
            $events[] = [
                'severity' => self::NOTICE,
                'message' => sprintf(
                    '%s sensor %s has changed from %s (%s) to %s (%s)',
                    $classLabel,
                    $descr,
                    $previousState ?? '#unnamed state#',
                    self::num($previous),
                    $currentState,
                    self::num($current)
                ),
            ];
        }

        return $events;
    }

    public static function num(float $value): string
    {
        return $value == (int) $value && abs($value) < 1e15 ? (string) (int) $value : (string) round($value, 4);
    }
}
