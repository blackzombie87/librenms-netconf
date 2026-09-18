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
     * @param  array<string, string>  $types  every RRD field of the mapping in YAML order => GAUGE|COUNTER|DERIVE
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
