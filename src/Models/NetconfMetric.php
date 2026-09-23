<?php

namespace SafferIt\LibrenmsNetconf\Models;

/**
 * @property string $metric_index
 * @property string $descr
 * @property string|null $group
 * @property array<string, string>|null $labels
 */
class NetconfMetric extends NetconfMetricRow
{
    protected $table = 'netconf_metrics';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return parent::casts() + ['labels' => 'array'];
    }

    /**
     * RRD file name parts for this metric row.
     *
     * @return list<string>
     */
    public static function rrdName(string $definition, string $mapping, string $index): array
    {
        return ['netconf', $definition, $mapping, $index];
    }
}
