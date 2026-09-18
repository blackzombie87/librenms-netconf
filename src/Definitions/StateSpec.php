<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * One entry of a state sensor's `states:` list: label shown in LibreNMS, the text (or
 * "/regex/") it is matched against, the numeric value stored and the generic severity
 * (0 ok, 1 warning, 2 critical, 3 unknown).
 */
final class StateSpec
{
    public function __construct(
        public readonly string $label,
        public readonly Pattern $match,
        public readonly int $value,
        public readonly int $generic,
        public readonly bool $default = false,
    ) {
    }
}
