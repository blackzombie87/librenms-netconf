<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Facades\Rrd;
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
    private RrdLayout $layout;

    public function __construct(private readonly Device $device, ?RrdLayout $layout = null)
    {
        $this->layout = $layout ?? RrdLayout::make();
    }

    /**
     * @param  list<MetricRow>  $rows
     * @return array{rows: int, written: int}
     */
    public function write(array $rows, ?DataStorageInterface $datastore): array
    {
        // types = data source order of the RRD as last verified (null: never written by this version)
        /** @var array<string, array<string, string>|null> $stored */
        $stored = NetconfMetric::query()->where('device_id', $this->device->device_id)
            ->get(['definition', 'mapping', 'metric_index', 'types'])
            ->mapWithKeys(fn (NetconfMetric $m) => ["$m->definition/$m->mapping/$m->metric_index" => $m->types])->all();

        $now = now();
        $records = [];
        $orders = [];
        foreach ($rows as $i => $row) {
            $index = mb_substr($row->index, 0, 191);
            $key = "$row->definition/{$row->mapping->id}/$index";
            $order = $stored[$key] ?? null;
            if ($datastore !== null && $row->values !== []) {
                $file = Rrd::name($this->device->hostname, NetconfMetric::rrdName($row->definition, $row->mapping->id, $row->index));
                $order = $orders[$i] = $this->layout->reconcile($file, $row->types, $order);
            }
            $records[] = [
                'device_id' => $this->device->device_id,
                'definition' => $row->definition,
                'mapping' => $row->mapping->id,
                'metric_index' => $index,
                'descr' => mb_substr($row->descr, 0, 255),
                'group' => $row->mapping->group,
                'values' => json_encode($row->values),
                'types' => $order === null ? null : json_encode($order),
                'labels' => json_encode($row->strings),
                'last_seen' => $now,
            ];
        }
        // one upsert per chunk instead of two statements per row (an EVPN leaf has hundreds)
        foreach (array_chunk($records, 200) as $chunk) {
            NetconfMetric::query()->upsert($chunk, ['device_id', 'definition', 'mapping', 'metric_index'], ['descr', 'group', 'values', 'types', 'labels', 'last_seen']);
        }

        $written = 0;
        foreach ($rows as $i => $row) {
            if (isset($orders[$i])) {
                // every data source of the file, in file order, whether this reply had a value or not
                $def = RrdDefinition::make();
                foreach ($orders[$i] as $field => $type) {
                    $def->addDataset($field, $type, $type === 'GAUGE' ? null : 0);
                }
                $datastore?->put($this->device, 'netconf', [
                    'definition' => $row->definition,
                    'mapping' => $row->mapping->id,
                    'index' => $row->index,
                    'rrd_name' => NetconfMetric::rrdName($row->definition, $row->mapping->id, $row->index),
                    'rrd_def' => $def,
                ], $row->rrdValues($orders[$i]));
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

    /**
     * Remove rows of mappings no matched definition has any more (definition edited, disabled
     * or gone, or nothing matches the device): the counterpart of the table orphan prune.
     *
     * @param  list<string>  $knownMappings  "definition/mapping" pairs of the matched definitions
     */
    public function deleteOrphans(array $knownMappings): int
    {
        $deleted = 0;
        $pairs = NetconfMetric::query()->where('device_id', $this->device->device_id)->distinct()->get(['definition', 'mapping']);
        foreach ($pairs as $pair) {
            if (! in_array($pair->definition . '/' . $pair->mapping, $knownMappings, true)) {
                $deleted += NetconfMetric::query()->where('device_id', $this->device->device_id)
                    ->where('definition', $pair->definition)->where('mapping', $pair->mapping)->delete();
                Log::info(sprintf('  metric rows of %s/%s deleted, no matching definition fills them any more', $pair->definition, $pair->mapping));
            }
        }

        return $deleted;
    }

    public function deleteAll(): int
    {
        return NetconfMetric::query()->where('device_id', $this->device->device_id)->delete();
    }
}
