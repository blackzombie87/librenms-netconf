<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * One value inside a port or custom metric row.
 *
 * RRD types (GAUGE, COUNTER, DERIVE) become a data source of the row's RRD; every such
 * field is created in YAML order when the file is created, so the data source set and
 * order never depend on what a particular reply happened to contain. `string` fields are
 * labels only (netconf_metrics.labels) and are never written to RRD.
 */
final class MetricField
{
    public const TYPE_STRING = 'STRING';

    public const RRD_TYPES = ['GAUGE', 'COUNTER', 'DERIVE'];

    public const TYPES = ['GAUGE', 'COUNTER', 'DERIVE', self::TYPE_STRING];

    public function __construct(
        public readonly string $name,
        public readonly string $xpath,
        public readonly string $type = 'GAUGE',
        public readonly ?string $transform = null,
    ) {
    }

    /** Label-only field (`type: string`), not an RRD data source. */
    public function isText(): bool
    {
        return $this->type === self::TYPE_STRING;
    }
}
