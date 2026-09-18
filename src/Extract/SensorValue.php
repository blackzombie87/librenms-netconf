<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use SafferIt\LibrenmsNetconf\Definitions\SensorMapping;
use SafferIt\LibrenmsNetconf\Definitions\StateSpec;

/**
 * One extracted sensor reading, ready to be discovered/recorded by the SensorWriter.
 */
final class SensorValue
{
    public function __construct(
        public readonly string $definition,
        public readonly SensorMapping $mapping,
        public readonly string $index,
        public readonly string $descr,
        public readonly float $value,
        public readonly ?StateSpec $state = null,
        public readonly ?string $rawText = null,
        public readonly ?string $re = null,
    ) {
    }

    /** LibreNMS sensor_type: netconf-<definition>-<mapping id> (also the state_name). */
    public function type(): string
    {
        return 'netconf-' . $this->definition . '-' . $this->mapping->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->mapping->class,
            'type' => $this->type(),
            'index' => $this->index,
            'descr' => $this->descr,
            'value' => $this->value,
            'state' => $this->state?->label,
            'generic' => $this->state?->generic,
            'group' => $this->mapping->group,
            'limit' => $this->mapping->limit,
            'limit_low' => $this->mapping->limitLow,
            'warn_limit' => $this->mapping->warnLimit,
            'warn_limit_low' => $this->mapping->warnLimitLow,
            're' => $this->re,
        ];
    }
}
