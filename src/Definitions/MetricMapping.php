<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * Free-form metrics (stored in the plugin's own table + RRD), one row per index.
 */
final class MetricMapping
{
    /**
     * @param  list<MetricField>  $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $command,
        public readonly string $index,
        public readonly array $fields,
        public readonly ?string $rows = null,
        public readonly ?string $when = null,
        public readonly ?string $repeat = null,
        public readonly string $descr = '{index}',
        public readonly ?string $group = null,
    ) {
    }
}
