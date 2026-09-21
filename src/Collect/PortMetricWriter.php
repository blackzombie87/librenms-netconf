<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Facades\Rrd;
use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use SafferIt\LibrenmsNetconf\Collect\Concerns\WritesRrd;
use SafferIt\LibrenmsNetconf\Extract\PortMetricRow;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;

/**
 * Per-port metrics: rows are matched to the ports table (ifIndex from snmp-index, or
 * ifName/ifDescr/ifAlias), stored in netconf_port_metrics and written to one RRD per
 * port and mapping.
 */
class PortMetricWriter
{
    use WritesRrd;

    /** @var array<string, int|null> */
    private array $portCache = [];

    /** @var array<string, list<int>> "definition/mapping" => port ids written by write() */
    private array $written = [];

    private RrdLayout $layout;

    public function __construct(private readonly Device $device, ?RrdLayout $layout = null)
    {
        $this->layout = $layout ?? RrdLayout::make();
    }

    /**
     * @param  list<PortMetricRow>  $rows
     * @return array{rows: int, matched: int, unmatched: list<string>}
     */
    public function write(array $rows, ?DataStorageInterface $datastore): array
    {
        $matched = 0;
        $unmatched = [];
        $now = now();

        // types = data source order of the RRD as last verified (null: never written by this version)
        /** @var array<string, array<string, string>|null> $stored */
        $stored = NetconfPortMetric::query()->where('device_id', $this->device->device_id)
            ->get(['port_id', 'definition', 'mapping', 'types'])
            ->mapWithKeys(fn (NetconfPortMetric $m) => ["$m->port_id/$m->definition/$m->mapping" => $m->types])->all();

        // resolve ports first, then one upsert per chunk instead of two statements per row
        $resolved = [];
        $records = [];
        foreach ($rows as $row) {
            $portId = $this->portId($row->matchField, $row->matchValue);
            if ($portId === null) {
                $unmatched[] = "{$row->matchField}={$row->matchValue}";
                continue;
            }
            $matched++;
            $this->written[$row->definition . '/' . $row->mapping->id][] = $portId;

            $order = $stored["$portId/$row->definition/{$row->mapping->id}"] ?? null;
            if ($datastore !== null && $row->values !== []) {
                $file = Rrd::name($this->device->hostname, NetconfPortMetric::rrdName($portId, $row->definition, $row->mapping->id));
                $order = $this->layout->reconcile($file, $row->types, $order);
                $resolved[] = [$row, $portId, $order];
            }
            $records[] = [
                'port_id' => $portId,
                'definition' => $row->definition,
                'mapping' => $row->mapping->id,
                'device_id' => $this->device->device_id,
                'values' => json_encode($row->values),
                'types' => $order === null ? null : json_encode($order),
                'last_seen' => $now,
            ];
        }
        foreach (array_chunk($records, 200) as $chunk) {
            NetconfPortMetric::query()->upsert($chunk, ['port_id', 'definition', 'mapping'], ['device_id', 'values', 'types', 'last_seen']);
        }

        foreach ($resolved as [$row, $portId, $order]) {
            if ($datastore !== null) {
                $this->putRrd($datastore, 'netconf-port', [
                    'definition' => $row->definition,
                    'mapping' => $row->mapping->id,
                    'port_id' => $portId,
                    'rrd_name' => NetconfPortMetric::rrdName($portId, $row->definition, $row->mapping->id),
                ], $order, $row->rrdValues($order));
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

    /**
     * Remove rows of mappings no matched definition has any more (definition edited, disabled
     * or gone, or nothing matches the device): the counterpart of the table orphan prune.
     *
     * @param  list<string>  $knownMappings  "definition/mapping" pairs of the matched definitions
     */
    public function deleteOrphans(array $knownMappings): int
    {
        $deleted = 0;
        $pairs = NetconfPortMetric::query()->where('device_id', $this->device->device_id)->distinct()->get(['definition', 'mapping']);
        foreach ($pairs as $pair) {
            if (! in_array($pair->definition . '/' . $pair->mapping, $knownMappings, true)) {
                $deleted += NetconfPortMetric::query()->where('device_id', $this->device->device_id)
                    ->where('definition', $pair->definition)->where('mapping', $pair->mapping)->delete();
                Log::info(sprintf('  port metric rows of %s/%s deleted, no matching definition fills them any more', $pair->definition, $pair->mapping));
            }
        }

        return $deleted;
    }

    public function deleteAll(): int
    {
        return NetconfPortMetric::query()->where('device_id', $this->device->device_id)->delete();
    }

    private function portId(string $field, string $value): ?int
    {
        if ($this->portCache === []) {
            // one query for every port of the device; first row wins on duplicate names
            /** @var list<array<string, mixed>> $ports */
            $ports = Port::query()->where('device_id', $this->device->device_id)->where('deleted', 0)
                ->orderBy('port_id')->get(['port_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias'])->toArray();
            foreach ($ports as $port) {
                foreach (['ifIndex', 'ifName', 'ifDescr', 'ifAlias'] as $f) {
                    $v = (string) ($port[$f] ?? '');
                    if ($v !== '') {
                        $this->portCache["$f=$v"] ??= (int) $port['port_id'];
                    }
                }
            }
            $this->portCache[''] = null;   // marks the cache as loaded even for a device without ports
        }

        $key = $field === 'ifIndex' ? "$field=" . (int) $value : "$field=$value";

        return $this->portCache[$key] ?? null;
    }
}
