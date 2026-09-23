<?php

namespace SafferIt\LibrenmsNetconf\Collect\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\RRD\RrdDefinition;
use SafferIt\LibrenmsNetconf\Models\NetconfMetricRow;

/**
 * The parts MetricWriter and PortMetricWriter share: one datastore update per metric row
 * (every data source of the file, in file order, missing values written as unknown) and the
 * orphan prune of mappings no matched definition fills any more.
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

    /**
     * Delete the device's rows of mappings that no matched definition has any more (a
     * definition was edited, disabled or removed, or nothing matches the device at all):
     * the counterpart of the table orphan prune.
     *
     * @template TRow of NetconfMetricRow
     *
     * @param  callable(): Builder<TRow>  $query  a fresh query for this device's rows
     * @param  list<string>  $knownMappings  "definition/mapping" pairs of the matched definitions
     */
    private function deleteOrphanRows(callable $query, array $knownMappings, string $label): int
    {
        $deleted = 0;
        foreach ($query()->distinct()->get(['definition', 'mapping']) as $pair) {
            if (! in_array($pair->definition . '/' . $pair->mapping, $knownMappings, true)) {
                $deleted += $query()->where('definition', $pair->definition)->where('mapping', $pair->mapping)->delete();
                Log::info(sprintf('  %s rows of %s/%s deleted, no matching definition fills them any more', $label, $pair->definition, $pair->mapping));
            }
        }

        return $deleted;
    }
}
