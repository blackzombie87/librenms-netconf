<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * Rows of a reply written to one of the plugin's EVPN fabric tables (plan §7.3): no RRD,
 * one row per key, vanished rows pruned per device. Several mappings may fill the same
 * table with different columns; rows are merged on the key.
 */
final class TableMapping
{
    /**
     * @param  list<TableColumn>  $columns
     */
    public function __construct(
        public readonly string $id,
        public readonly string $table,
        public readonly string $command,
        public readonly array $columns,
        public readonly ?string $rows = null,
        public readonly ?string $when = null,
        public readonly ?string $repeat = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return TableSchema::key($this->table);
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(fn (TableColumn $c) => $c->name, $this->columns);
    }
}
