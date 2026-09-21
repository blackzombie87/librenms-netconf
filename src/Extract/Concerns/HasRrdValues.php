<?php

namespace SafferIt\LibrenmsNetconf\Extract\Concerns;

/**
 * Values in data-source order for a row with `values` (field => number) and `types`
 * (field => RRD type); shared by MetricRow and PortMetricRow.
 */
trait HasRrdValues
{
    /**
     * @param  array<string, string>|null  $order  field => type of the RRD file; the row's own types when null
     * @return array<string, int|float|string> field => value or 'U' for fields without a value
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
