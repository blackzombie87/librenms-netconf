<?php

namespace SafferIt\LibrenmsNetconf\Extract;

/**
 * The stored identity of an extracted row (F5 4). Core's sensors.sensor_index is
 * varchar(128) and the plugin's metric_index varchar(191); an index longer than its column
 * is shortened once, at extraction time, so discovery, the record lookup, the custom-limit
 * preservation and the RRD file name all see the same string. Distinct long indices stay
 * distinct: the visible prefix is followed by "," and eight hex digits of the sha1 of the
 * full index. The separator is one of the characters LibreNMS keeps in an RRD file name
 * (`Rrd::safeName()`, `[A-Za-z0-9,._-]`), so the stored index and the file name agree (F6 3).
 */
final class Identity
{
    public const SENSOR_WIDTH = 128;

    public const METRIC_WIDTH = 191;

    private const HASH_LENGTH = 8;

    /** Between the visible prefix and the hash; kept verbatim by Rrd::safeName(). */
    private const SEPARATOR = ',';

    /** The index as stored: unchanged up to $width characters, otherwise prefix , hash. */
    public static function fit(string $index, int $width): string
    {
        if (mb_strlen($index) <= $width) {
            return $index;
        }

        return mb_substr($index, 0, $width - self::HASH_LENGTH - 1) . self::SEPARATOR . substr(sha1($index), 0, self::HASH_LENGTH);
    }

    /** Whether fit() would change the index. */
    public static function exceeds(string $index, int $width): bool
    {
        return mb_strlen($index) > $width;
    }
}
