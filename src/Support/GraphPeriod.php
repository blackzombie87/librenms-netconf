<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * The graph period of a page: an rrdtool-style relative start from the query string, checked
 * against the unit list LibreNMS itself parses (`LibreNMS\Util\Time::parseAt()`).
 *
 * The month token is `mo`; `m` is a minute there, so a pattern that ends at one-letter units
 * silently turns the month link into a day (F6 2). The pages offer the same five tokens, and
 * a token they offer must be one this class keeps.
 */
final class GraphPeriod
{
    public const DEFAULT = '-1d';

    /** The periods the metric pages offer: token => label. */
    public const OFFERED = ['-6h' => '6h', '-1d' => 'day', '-1w' => 'week', '-1mo' => 'month', '-1y' => 'year'];

    /** The `period` query parameter, or the default for anything Time::parseAt() would not take. */
    public static function fromRequest(): string
    {
        $period = request()->query('period');

        return self::sanitise(is_string($period) ? $period : null);
    }

    public static function sanitise(?string $period): string
    {
        return $period !== null && preg_match('/^[+-]\d+(mo|[smhdwy])$/', $period) === 1 ? $period : self::DEFAULT;
    }
}
