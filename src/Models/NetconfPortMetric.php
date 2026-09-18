<?php

namespace SafferIt\LibrenmsNetconf\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $device_id
 * @property int $port_id
 * @property string $definition
 * @property string $mapping
 * @property array<string, float>|null $values
 * @property array<string, string>|null $types
 * @property \Illuminate\Support\Carbon|null $last_seen
 */
class NetconfPortMetric extends Model
{
    protected $table = 'netconf_port_metrics';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'types' => 'array',
            'last_seen' => 'datetime',
        ];
    }

    /**
     * @return list<string|int>
     */
    public static function rrdName(int $portId, string $definition, string $mapping): array
    {
        return ['netconf-port', $portId, $definition, $mapping];
    }
}
