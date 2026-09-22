<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * The stored summary of a run as one readable line: "20 ok / 7 skipped / 0 failed ·
 * 141 sensors · 8.0 s" instead of the raw key=value dump (plan §8 U4).
 */
final class RunSummary
{
    /**
     * @param  array<string, int|float|string>  $summary
     */
    public static function line(array $summary, ?float $duration = null): string
    {
        $parts = [];
        if (isset($summary['commands_ok']) || isset($summary['commands_failed'])) {
            $parts[] = sprintf('%d ok / %d skipped / %d failed', $summary['commands_ok'] ?? 0, $summary['commands_skipped'] ?? 0, $summary['commands_failed'] ?? 0);
        }
        if (isset($summary['sensors'])) {
            $parts[] = $summary['sensors'] . ' sensors';
        }
        if (($summary['warnings'] ?? 0) > 0) {
            $parts[] = $summary['warnings'] . ' warnings';
        }
        if ($duration !== null) {
            $parts[] = sprintf('%.1f s', $duration);
        }

        return implode(' · ', $parts);
    }
}
