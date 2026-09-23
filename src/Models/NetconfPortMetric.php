<?php

namespace SafferIt\LibrenmsNetconf\Models;

/**
 * @property int $port_id
 */
class NetconfPortMetric extends NetconfMetricRow
{
    protected $table = 'netconf_port_metrics';

    /**
     * @return list<string|int>
     */
    public static function rrdName(int $portId, string $definition, string $mapping): array
    {
        return ['netconf-port', $portId, $definition, $mapping];
    }
}
