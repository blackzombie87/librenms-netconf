<?php

namespace SafferIt\LibrenmsNetconf\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $device_id
 * @property string $definition
 * @property string $mapping
 * @property string $metric_index
 * @property string $descr
 * @property string|null $group
 * @property array<string, float>|null $values
 * @property array<string, string>|null $labels
 * @property \Illuminate\Support\Carbon|null $last_seen
 */
class NetconfMetric extends Model
{
    protected $table = 'netconf_metrics';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'labels' => 'array',
            'last_seen' => 'datetime',
        ];
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
