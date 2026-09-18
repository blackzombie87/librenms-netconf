<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * Per-port metrics: rows are matched to LibreNMS ports by one field (ifIndex via snmp-index,
 * or ifName/ifDescr/ifAlias) and carry several counters/gauges each.
 */
final class PortMapping
{
    public const MATCH_FIELDS = ['ifIndex', 'ifName', 'ifDescr', 'ifAlias'];

    /**
     * @param  list<MetricField>  $metrics
     */
    public function __construct(
        public readonly string $id,
        public readonly string $command,
        public readonly string $rows,
        public readonly string $matchField,
        public readonly string $matchXpath,
        public readonly array $metrics,
        public readonly ?string $when = null,
        public readonly ?string $repeat = null,
    ) {
    }
}
