<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * A parsed and validated YAML definition.
 */
final class Definition
{
    /**
     * @param  array<string, CommandSpec>  $commands
     * @param  list<SensorMapping>  $sensors
     * @param  list<PortMapping>  $ports
     * @param  list<MetricMapping>  $metrics
     * @param  list<TableMapping>  $tables
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly MatchSpec $match,
        public readonly array $commands,
        public readonly array $sensors = [],
        public readonly array $ports = [],
        public readonly array $metrics = [],
        public readonly array $tables = [],
        public readonly string $source = '',
        public readonly bool $enabled = true,
    ) {
    }

    public function command(string $key): CommandSpec
    {
        if (! isset($this->commands[$key])) {
            throw new DefinitionException(sprintf('%s: unknown command "%s"', $this->name, $key));
        }

        return $this->commands[$key];
    }

    public function matches(DeviceFacts $facts): bool
    {
        return $this->enabled && $this->match->matches($facts);
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'commands' => count($this->commands),
            'sensors' => count($this->sensors),
            'ports' => count($this->ports),
            'metrics' => count($this->metrics),
            'tables' => count($this->tables),
        ];
    }
}
