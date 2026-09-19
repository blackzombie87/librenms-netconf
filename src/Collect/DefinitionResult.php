<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use SafferIt\LibrenmsNetconf\Definitions\Definition;
use SafferIt\LibrenmsNetconf\Extract\MetricRow;
use SafferIt\LibrenmsNetconf\Extract\PortMetricRow;
use SafferIt\LibrenmsNetconf\Extract\SensorValue;
use SafferIt\LibrenmsNetconf\Extract\TableRow;

/**
 * Everything one definition produced for a device in one collection run.
 */
final class DefinitionResult
{
    /**
     * @param  list<SensorValue>  $sensors
     * @param  list<PortMetricRow>  $ports
     * @param  list<MetricRow>  $metrics
     * @param  list<TableRow>  $tables
     * @param  list<string>  $skippedMappings  mapping ids whose command was skipped/failed
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly Definition $definition,
        public array $sensors = [],
        public array $ports = [],
        public array $metrics = [],
        public array $tables = [],
        public array $skippedMappings = [],
        public array $warnings = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->sensors === [] && $this->ports === [] && $this->metrics === [] && $this->tables === [];
    }
}
