<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use SafferIt\LibrenmsNetconf\Definitions\MetricMapping;
use SafferIt\LibrenmsNetconf\Extract\Concerns\HasRrdValues;

/**
 * Extracted custom metrics for one index.
 */
final class MetricRow
{
    use HasRrdValues;

    /**
     * @param  array<string, float>  $values  numeric fields
     * @param  array<string, string>  $strings  fields whose value is not numeric (kept for display/labels)
     * @param  array<string, string>  $types  every RRD field of the mapping in YAML order => GAUGE|COUNTER|DERIVE
     */
    public function __construct(
        public readonly string $definition,
        public readonly MetricMapping $mapping,
        public readonly string $index,
        public readonly string $descr,
        public readonly array $values,
        public readonly array $strings,
        public readonly array $types,
        public readonly ?string $re = null,
    ) {
    }
}
