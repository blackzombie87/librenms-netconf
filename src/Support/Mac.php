<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * One place for the two MAC notations the plugin deals with: Junos and the search box write
 * them with separators, LibreNMS stores and compares 12 lower-case hex digits (`ports_fdb`),
 * and the pages show them grouped again.
 */
final class Mac
{
    /** Every hex digit of the text, lower case: "aa:bb-CC" → "aabbcc". */
    public static function digits(string $text): string
    {
        return strtolower(preg_replace('/[^0-9a-f]/i', '', $text) ?? '');
    }

    /** Storage form of a full MAC, null when the text does not hold exactly 12 hex digits. */
    public static function hex(string $text): ?string
    {
        $digits = self::digits($text);

        return strlen($digits) === 12 ? $digits : null;
    }

    /** aa:bb:cc:dd:ee:ff; text that is not a full MAC comes back as it was. */
    public static function readable(string $mac): string
    {
        $hex = self::hex($mac);

        return $hex === null ? $mac : implode(':', str_split($hex, 2));
    }
}
