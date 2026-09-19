<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use SafferIt\LibrenmsNetconf\Definitions\TableMapping;

/**
 * One extracted row for a plugin table: coerced column values (int, string, bool, list for
 * json columns, 'Y-m-d H:i:s' for datetime; null where the reply had no usable value) and the
 * key that identifies the row within the device.
 */
final class TableRow
{
    /**
     * @param  array<string, int|string|bool|list<string>|null>  $values  every column of the mapping
     */
    public function __construct(
        public readonly string $definition,
        public readonly TableMapping $mapping,
        public readonly string $key,
        public readonly array $values,
    ) {
    }

    /**
     * @return array<string, int|string|bool|list<string>|null>
     */
    public function keyValues(): array
    {
        return array_intersect_key($this->values, array_flip($this->mapping->keyColumns()));
    }

    /**
     * Key string for a set of key values: values joined by "/", in key column order.
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $values
     */
    public static function keyOf(array $columns, array $values): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $value = $values[$column] ?? null;
            $parts[] = is_array($value) ? implode(',', $value) : (string) $value;
        }

        return implode('/', $parts);
    }
}
