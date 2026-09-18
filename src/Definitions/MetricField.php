<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * One value inside a port or custom metric row.
 */
final class MetricField
{
    public const TYPES = ['GAUGE', 'COUNTER', 'DERIVE'];

    public function __construct(
        public readonly string $name,
        public readonly string $xpath,
        public readonly string $type = 'GAUGE',
        public readonly ?string $transform = null,
    ) {
    }
}
