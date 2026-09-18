<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use SafferIt\LibrenmsNetconf\Definitions\MetricMapping;

/**
 * Extracted custom metrics for one index.
 */
final class MetricRow
{
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

    /**
     * Values for the RRD update: one entry per data source in $order (default: the mapping's
     * fields in definition order), `U` (unknown) where this row has no value, so the update
     * never drifts from the data sources of the file.
     *
     * @param  array<string, string>|null  $order  data sources of the file (name => type)
     * @return array<string, float|string>
     */
    public function rrdValues(?array $order = null): array
    {
        $out = [];
        foreach ($order ?? $this->types as $field => $type) {
            $out[$field] = $this->values[$field] ?? 'U';
        }

        return $out;
    }
}
