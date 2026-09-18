<?php

namespace SafferIt\LibrenmsNetconf\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $device_id
 * @property string|null $transport
 * @property list<string>|null $definitions
 * @property int $poll_count
 * @property int $consecutive_failures
 * @property \Carbon\CarbonInterface|null $last_ok
 * @property \Carbon\CarbonInterface|null $last_attempt
 * @property \Carbon\CarbonInterface|null $next_attempt
 * @property string|null $last_error
 * @property float|null $last_duration
 * @property array<string, int>|null $last_summary
 */
class NetconfDeviceStatus extends Model
{
    protected $table = 'netconf_device_status';

    protected $primaryKey = 'device_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'definitions' => 'array',
            'last_summary' => 'array',
            'last_ok' => 'datetime',
            'last_attempt' => 'datetime',
            'next_attempt' => 'datetime',
            'poll_count' => 'int',
            'consecutive_failures' => 'int',
            'last_duration' => 'float',
        ];
    }

    public function inBackoff(): bool
    {
        return $this->next_attempt !== null && $this->next_attempt->isFuture();
    }
}
