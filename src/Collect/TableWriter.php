<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Extract\TableRow;

/**
 * Writes `tables:` rows to the netconf_evpn_* tables: one upsert per mapping and chunk
 * (device_id + key columns identify a row, so several mappings merge their columns into
 * the same row), then prunes rows of the device that no mapping produced any more.
 */
class TableWriter
{
    public function __construct(private readonly Device $device)
    {
    }

    /**
     * @param  list<TableRow>  $rows
     * @return array{rows: int, tables: int}
     */
    public function write(array $rows): array
    {
        $now = now()->toDateTimeString();
        $tables = [];

        /** @var array<string, list<TableRow>> $byMapping */
        $byMapping = [];
        foreach ($rows as $row) {
            $byMapping[$row->definition . '/' . $row->mapping->id][] = $row;
        }

        foreach ($byMapping as $group) {
            $mapping = $group[0]->mapping;
            $table = TableSchema::tableName($mapping->table);
            $tables[$table] = true;
            $columns = $mapping->columnNames();
            $firstSeen = TableSchema::hasFirstSeen($mapping->table);

            $records = [];
            foreach ($group as $row) {
                $record = ['device_id' => $this->device->device_id];
                foreach ($columns as $column) {
                    $record[$column] = self::storable($row->values[$column] ?? null);
                }
                if ($firstSeen) {
                    $record['first_seen'] = $now;
                }
                $record['last_seen'] = $now;
                $records[] = $record;
            }

            $unique = array_merge(['device_id'], $mapping->keyColumns());
            foreach (array_chunk($records, 200) as $chunk) {
                DB::table($table)->upsert($chunk, $unique, array_merge($columns, ['last_seen']));
            }
            Log::debug(sprintf('  table %s: %d rows from %s/%s', $mapping->table, count($group), $group[0]->definition, $mapping->id));
        }

        return ['rows' => count($rows), 'tables' => count($tables)];
    }

    /**
     * Delete rows of the device that this run did not produce, for tables whose every
     * mapping delivered data (a skipped command keeps its rows).
     *
     * @param  list<TableRow>  $rows
     * @param  list<string>  $completeTables  logical table names
     */
    public function prune(array $rows, array $completeTables): int
    {
        $seen = [];
        foreach ($rows as $row) {
            $seen[$row->mapping->table][$row->key] = true;
        }

        $deleted = 0;
        foreach (array_unique($completeTables) as $table) {
            $keyColumns = TableSchema::key($table);
            $stale = [];
            $query = DB::table(TableSchema::tableName($table))->where('device_id', $this->device->device_id)
                ->select(array_merge(['id'], $keyColumns));
            foreach ($query->get() as $existing) {
                $key = TableRow::keyOf($keyColumns, (array) $existing);
                if (! isset($seen[$table][$key])) {
                    $stale[] = (int) $existing->id;
                }
            }
            foreach (array_chunk($stale, 500) as $ids) {
                $deleted += DB::table(TableSchema::tableName($table))->whereIn('id', $ids)->delete();
            }
        }

        return $deleted;
    }

    public function exists(): bool
    {
        foreach (TableSchema::writable() as $table) {
            if (DB::table(TableSchema::tableName($table))->where('device_id', $this->device->device_id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Logical tables that hold rows of this device.
     *
     * @return list<string>
     */
    public function owned(): array
    {
        return array_values(array_filter(TableSchema::writable(), fn (string $table) => DB::table(TableSchema::tableName($table))->where('device_id', $this->device->device_id)->exists()));
    }

    /**
     * Delete every row of the device in the given logical tables.
     *
     * @param  list<string>  $tables
     */
    public function deleteTables(array $tables): int
    {
        $deleted = 0;
        foreach (array_unique($tables) as $table) {
            if (TableSchema::isWritable($table)) {
                $deleted += DB::table(TableSchema::tableName($table))->where('device_id', $this->device->device_id)->delete();
            }
        }

        return $deleted;
    }

    public function deleteAll(): int
    {
        $deleted = 0;
        foreach (TableSchema::writable() as $table) {
            $deleted += DB::table(TableSchema::tableName($table))->where('device_id', $this->device->device_id)->delete();
        }

        return $deleted;
    }

    /**
     * @param  int|string|bool|list<string>|null  $value
     */
    private static function storable(int|string|bool|array|null $value): int|string|null
    {
        if (is_array($value)) {
            return json_encode(array_values($value)) ?: null;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }
}
