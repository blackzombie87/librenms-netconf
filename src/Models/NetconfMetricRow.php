<?php

namespace SafferIt\LibrenmsNetconf\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the two metric row models share: the columns every mapping writes (which definition
 * and mapping produced the row, the last values and the RRD data source order), and the
 * orphan prune in WritesRrd, which works on any of them.
 *
 * @property int $id
 * @property int $device_id
 * @property string $definition
 * @property string $mapping
 * @property array<string, float>|null $values
 * @property array<string, string>|null $types every RRD data source in file order => GAUGE|COUNTER|DERIVE
 * @property \Illuminate\Support\Carbon|null $last_seen
 */
abstract class NetconfMetricRow extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'values' => 'array',
            'types' => 'array',
            'last_seen' => 'datetime',
        ];
    }

    /**
     * Data sources of this row's RRD (field => type), from the stored types or, for rows
     * written before types were stored, the last values.
     *
     * @return array<string, string>
     */
    public function dataSources(): array
    {
        if (is_array($this->types) && $this->types !== []) {
            return $this->types;
        }

        return array_fill_keys(array_keys($this->values ?? []), 'GAUGE');
    }
}
