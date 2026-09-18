<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\RRD\RrdDefinition;
use SafferIt\LibrenmsNetconf\Extract\MetricRow;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;

/**
 * Custom metrics: one netconf_metrics row per index (last values + labels, for the UI and
 * Advanced-SQL alert rules) and one RRD per row with a data source per numeric field of
 * the mapping (missing values are written as unknown).
 */
class MetricWriter
{
    public function __construct(private readonly Device $device)
    {
    }

    /**
     * @param  list<MetricRow>  $rows
     * @return array{rows: int, written: int}
     */
    public function write(array $rows, ?DataStorageInterface $datastore): array
    {
        $written = 0;
        foreach ($rows as $row) {
            NetconfMetric::query()->updateOrCreate([
                'device_id' => $this->device->device_id,
                'definition' => $row->definition,
                'mapping' => $row->mapping->id,
                'metric_index' => mb_substr($row->index, 0, 191),
            ], [
                'descr' => mb_substr($row->descr, 0, 255),
                'group' => $row->mapping->group,
                'values' => $row->values,
                'types' => $row->types,
                'labels' => $row->strings,
                'last_seen' => now(),
            ]);

            if ($datastore !== null && $row->values !== []) {
                // every RRD field of the mapping, in definition order, whether present or not:
                // the data source set must not depend on what this reply contained
                $def = RrdDefinition::make();
                foreach ($row->types as $field => $type) {
                    $def->addDataset($field, $type, $type === 'GAUGE' ? null : 0);
                }
                $datastore->put($this->device, 'netconf', [
                    'definition' => $row->definition,
                    'mapping' => $row->mapping->id,
                    'index' => $row->index,
                    'rrd_name' => NetconfMetric::rrdName($row->definition, $row->mapping->id, $row->index),
                    'rrd_def' => $def,
                ], $row->rrdValues());
                $written++;
            }

            Log::debug(sprintf('  metric %s/%s [%s] %s', $row->definition, $row->mapping->id, $row->index, json_encode($row->values + $row->strings)));
        }

        return ['rows' => count($rows), 'written' => $written];
    }

    /**
     * Remove rows of mappings that produced data this run but no longer contain the index.
     *
     * @param  list<MetricRow>  $rows
     * @param  list<string>  $mappingsWithData  "definition/mapping" pairs whose command ran
     */
    public function prune(array $rows, array $mappingsWithData): int
    {
        $deleted = 0;
        $seen = [];
        foreach ($rows as $row) {
            $seen[$row->definition . '/' . $row->mapping->id][] = mb_substr($row->index, 0, 191);
        }
        foreach ($mappingsWithData as $pair) {
            [$definition, $mapping] = explode('/', $pair, 2);
            $deleted += NetconfMetric::query()->where('device_id', $this->device->device_id)
                ->where('definition', $definition)
                ->where('mapping', $mapping)
                ->whereNotIn('metric_index', $seen[$pair] ?? [])
                ->delete();
        }

        return $deleted;
    }

    public function count(): int
    {
        return NetconfMetric::query()->where('device_id', $this->device->device_id)->count();
    }

    public function deleteAll(): int
    {
        return NetconfMetric::query()->where('device_id', $this->device->device_id)->delete();
    }
}
