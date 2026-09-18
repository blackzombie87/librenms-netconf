<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\RRD\RrdDefinition;
use SafferIt\LibrenmsNetconf\Extract\PortMetricRow;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;

/**
 * Per-port metrics: rows are matched to the ports table (ifIndex from snmp-index, or
 * ifName/ifDescr/ifAlias), stored in netconf_port_metrics and written to one RRD per
 * port and mapping.
 */
class PortMetricWriter
{
    /** @var array<string, int|null> */
    private array $portCache = [];

    /** @var array<string, list<int>> "definition/mapping" => port ids written by write() */
    private array $written = [];

    public function __construct(private readonly Device $device)
    {
    }

    /**
     * @param  list<PortMetricRow>  $rows
     * @return array{rows: int, matched: int, unmatched: list<string>}
     */
    public function write(array $rows, ?DataStorageInterface $datastore): array
    {
        $matched = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            $portId = $this->portId($row->matchField, $row->matchValue);
            if ($portId === null) {
                $unmatched[] = "{$row->matchField}={$row->matchValue}";
                continue;
            }
            $matched++;
            $this->written[$row->definition . '/' . $row->mapping->id][] = $portId;

            NetconfPortMetric::query()->updateOrCreate([
                'port_id' => $portId,
                'definition' => $row->definition,
                'mapping' => $row->mapping->id,
            ], [
                'device_id' => $this->device->device_id,
                'values' => $row->values,
                'types' => $row->types,
                'last_seen' => now(),
            ]);

            if ($datastore !== null && $row->values !== []) {
                // every RRD field of the mapping, in definition order, whether present or not:
                // the data source set must not depend on what this reply contained
                $def = RrdDefinition::make();
                foreach ($row->types as $field => $type) {
                    $def->addDataset($field, $type, $type === 'GAUGE' ? null : 0);
                }
                $datastore->put($this->device, 'netconf-port', [
                    'definition' => $row->definition,
                    'mapping' => $row->mapping->id,
                    'port_id' => $portId,
                    'rrd_name' => NetconfPortMetric::rrdName($portId, $row->definition, $row->mapping->id),
                    'rrd_def' => $def,
                ], $row->rrdValues());
            }

            Log::debug(sprintf('  port %s=%s (port_id %d) %s', $row->matchField, $row->matchValue, $portId, json_encode($row->values)));
        }

        return ['rows' => count($rows), 'matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Remove rows of mappings that produced data this run but no longer contain the port,
     * and rows of ports that were deleted in LibreNMS. Call after write().
     *
     * @param  list<string>  $mappingsWithData  "definition/mapping" pairs whose command ran
     */
    public function prune(array $mappingsWithData): int
    {
        $deleted = 0;
        foreach ($mappingsWithData as $pair) {
            [$definition, $mapping] = explode('/', $pair, 2);
            $deleted += NetconfPortMetric::query()->where('device_id', $this->device->device_id)
                ->where('definition', $definition)
                ->where('mapping', $mapping)
                ->whereNotIn('port_id', $this->written[$pair] ?? [])
                ->delete();
        }

        $deleted += NetconfPortMetric::query()->where('device_id', $this->device->device_id)
            ->whereNotIn('port_id', Port::query()->where('device_id', $this->device->device_id)->where('deleted', 0)->select('port_id'))
            ->delete();

        return $deleted;
    }

    public function count(): int
    {
        return NetconfPortMetric::query()->where('device_id', $this->device->device_id)->count();
    }

    public function deleteAll(): int
    {
        return NetconfPortMetric::query()->where('device_id', $this->device->device_id)->delete();
    }

    private function portId(string $field, string $value): ?int
    {
        $key = "$field=$value";
        if (! array_key_exists($key, $this->portCache)) {
            $query = Port::query()->where('device_id', $this->device->device_id)->where('deleted', 0);
            $query = $field === 'ifIndex' ? $query->where('ifIndex', (int) $value) : $query->where($field, $value);
            $this->portCache[$key] = $query->value('port_id');
        }

        return $this->portCache[$key] === null ? null : (int) $this->portCache[$key];
    }
}
