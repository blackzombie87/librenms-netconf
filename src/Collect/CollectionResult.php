<?php

namespace SafferIt\LibrenmsNetconf\Collect;

/**
 * Aggregated outcome of Collector::collect().
 */
final class CollectionResult
{
    /**
     * @param  array<string, DefinitionResult>  $definitions  keyed by definition name
     * @param  array<string, CommandRun>  $commands  keyed by command identity
     * @param  list<string>  $errors  failures of the run: connection lost, budget exceeded, a required command not answered
     */
    public function __construct(
        public array $definitions = [],
        public array $commands = [],
        public array $errors = [],
        public float $duration = 0.0,
        public bool $aborted = false,
    ) {
    }

    /**
     * @return list<\SafferIt\LibrenmsNetconf\Extract\SensorValue>
     */
    public function sensors(): array
    {
        return array_merge(...array_values(array_map(fn ($d) => $d->sensors, $this->definitions)) ?: [[]]);
    }

    /**
     * @return list<\SafferIt\LibrenmsNetconf\Extract\PortMetricRow>
     */
    public function ports(): array
    {
        return array_merge(...array_values(array_map(fn ($d) => $d->ports, $this->definitions)) ?: [[]]);
    }

    /**
     * @return list<\SafferIt\LibrenmsNetconf\Extract\MetricRow>
     */
    public function metrics(): array
    {
        return array_merge(...array_values(array_map(fn ($d) => $d->metrics, $this->definitions)) ?: [[]]);
    }

    /**
     * @return list<\SafferIt\LibrenmsNetconf\Extract\TableRow>
     */
    public function tables(): array
    {
        return array_merge(...array_values(array_map(fn ($d) => $d->tables, $this->definitions)) ?: [[]]);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_merge(...array_values(array_map(fn ($d) => $d->warnings, $this->definitions)) ?: [[]]);
    }

    public function ok(): bool
    {
        return $this->errors === [] && ! $this->aborted;
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        $status = ['ok' => 0, 'skipped' => 0, 'error' => 0];
        foreach ($this->commands as $run) {
            $status[$run->status] = ($status[$run->status] ?? 0) + 1;
        }

        return [
            'definitions' => count($this->definitions),
            'commands_ok' => $status['ok'],
            'commands_skipped' => $status['skipped'],
            'commands_failed' => $status['error'],
            'sensors' => count($this->sensors()),
            'port_rows' => count($this->ports()),
            'metric_rows' => count($this->metrics()),
            'table_rows' => count($this->tables()),
            'warnings' => count($this->warnings()),
        ];
    }
}
