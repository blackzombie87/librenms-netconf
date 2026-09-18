<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * Maps rows of a command reply onto LibreNMS sensors of one class.
 */
final class SensorMapping
{
    /**
     * @param  list<string>  $valueAny  candidates tried in order, first non-empty wins
     * @param  list<StateSpec>  $states
     */
    public function __construct(
        public readonly string $id,
        public readonly string $class,
        public readonly string $command,
        public readonly string $index,
        public readonly string $descr,
        public readonly ?string $rows = null,
        public readonly ?string $when = null,
        public readonly ?string $repeat = null,
        public readonly ?string $value = null,
        public readonly array $valueAny = [],
        public readonly ?string $group = null,
        public readonly ?float $limit = null,
        public readonly ?float $limitLow = null,
        public readonly ?float $warnLimit = null,
        public readonly ?float $warnLimitLow = null,
        public readonly float $divisor = 1,
        public readonly float $multiplier = 1,
        public readonly array $states = [],
    ) {
    }

    public function isState(): bool
    {
        return $this->class === 'state';
    }

    /**
     * @return list<string>
     */
    public function valueCandidates(): array
    {
        return $this->value !== null ? [$this->value] : $this->valueAny;
    }

    public function resolveState(string $text): ?StateSpec
    {
        $default = null;
        foreach ($this->states as $state) {
            if ($state->match->matches($text)) {
                return $state;
            }
            if ($state->default) {
                $default = $state;
            }
        }

        return $default;
    }
}
