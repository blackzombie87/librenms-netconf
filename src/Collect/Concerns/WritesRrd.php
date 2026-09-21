<?php

namespace SafferIt\LibrenmsNetconf\Collect\Concerns;

use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\RRD\RrdDefinition;

/**
 * One datastore update for a metric row: every data source of the file, in file order,
 * whether this reply had a value or not (missing values are written as unknown).
 * Shared by MetricWriter and PortMetricWriter.
 */
trait WritesRrd
{
    /**
     * @param  array<string, mixed>  $tags  datastore tags incl. rrd_name
     * @param  array<string, string>  $order  field => GAUGE|COUNTER|DERIVE, in file order
     * @param  array<string, int|float|string>  $values  field => value or 'U'
     */
    private function putRrd(DataStorageInterface $datastore, string $measurement, array $tags, array $order, array $values): void
    {
        $def = RrdDefinition::make();
        foreach ($order as $field => $type) {
            $def->addDataset($field, $type, $type === 'GAUGE' ? null : 0);
        }
        $datastore->put($this->device, $measurement, $tags + ['rrd_def' => $def], $values);
    }
}
