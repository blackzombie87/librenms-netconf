<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use SafferIt\LibrenmsNetconf\Definitions\PortMapping;

/**
 * Extracted per-port metrics for one interface row; the port itself is resolved later
 * (ports table lookup by matchField = matchValue).
 */
final class PortMetricRow
{
    /**
     * @param  array<string, float>  $values  field name => value (fields without a value are omitted)
     * @param  array<string, string>  $types  field name => GAUGE|COUNTER|DERIVE
     */
    public function __construct(
        public readonly string $definition,
        public readonly PortMapping $mapping,
        public readonly string $matchField,
        public readonly string $matchValue,
        public readonly array $values,
        public readonly array $types,
        public readonly ?string $re = null,
    ) {
    }
}
