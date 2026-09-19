<?php

namespace SafferIt\LibrenmsNetconf\Console\Concerns;

use SafferIt\LibrenmsNetconf\Collect\CollectionResult;
use SafferIt\LibrenmsNetconf\Collect\CommandRun;
use SafferIt\LibrenmsNetconf\Extract\Template;

/**
 * Tabular output of a CollectionResult for netconf:validate --replay and netconf:preview.
 *
 * @mixin \Illuminate\Console\Command
 */
trait PrintsCollection
{
    protected function printCollection(CollectionResult $result, bool $verbose): void
    {
        $this->table(['Command', 'Status', 'Bytes', 'Time', 'Note'], array_map(fn (CommandRun $run) => [
            $run->label,
            $run->status === CommandRun::OK ? '<info>ok</info>' : ($run->status === CommandRun::SKIPPED ? '<comment>skipped</comment>' : '<error>error</error>'),
            $run->bytes ?: '',
            $run->duration ? sprintf('%.2fs', $run->duration) : '',
            mb_strimwidth((string) $run->message, 0, 70, '…'),
        ], array_values($result->commands)));

        foreach ($result->definitions as $name => $def) {
            $this->line(sprintf('<info>%s</info> — %s', $name, $def->definition->description));
            if ($def->skippedMappings !== []) {
                $this->line('  mappings without data: ' . implode(', ', $def->skippedMappings));
            }
            if ($def->isEmpty()) {
                $this->line('  (nothing extracted)');
                continue;
            }

            if ($def->sensors !== []) {
                $this->table(['Sensor', 'Class', 'Index', 'Description', 'Value', 'State', 'Limits'], array_map(fn ($s) => [
                    $s->mapping->id,
                    $s->mapping->class,
                    $s->index,
                    $s->descr,
                    Template::stringify($s->value),
                    $s->state ? sprintf('%s (%s)', $s->state->label, ['ok', 'warn', 'crit', 'unknown'][$s->state->generic] ?? $s->state->generic) : '',
                    $this->limits($s->mapping),
                ], $def->sensors));
            }
            if ($def->ports !== []) {
                $this->table(['Port mapping', 'Match', 'Metrics'], array_map(fn ($p) => [
                    $p->mapping->id,
                    "{$p->matchField}={$p->matchValue}",
                    $this->fields($p->values, $p->types, $verbose),
                ], $def->ports));
            }
            if ($def->metrics !== []) {
                $this->table(['Metric', 'Index', 'Description', 'Fields'], array_map(fn ($m) => [
                    $m->mapping->id,
                    $m->index,
                    $m->descr,
                    $this->fields($m->values + $m->strings, $m->types, $verbose),
                ], $def->metrics));
            }
            if ($def->tables !== []) {
                $this->table(['Table mapping', 'Table', 'Key', 'Columns'], array_map(fn ($t) => [
                    $t->mapping->id,
                    $t->mapping->table,
                    $t->key,
                    $this->columns($t->values, $verbose),
                ], $def->tables));
            }
            foreach ($def->warnings as $warning) {
                $this->warn('  ' . $warning);
            }
        }

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        $summary = $result->summary();
        $this->line(sprintf(
            '%d definitions, commands ok/skipped/failed %d/%d/%d, %d sensors, %d port rows, %d metric rows, %d table rows, %d warnings, %.2fs',
            $summary['definitions'],
            $summary['commands_ok'],
            $summary['commands_skipped'],
            $summary['commands_failed'],
            $summary['sensors'],
            $summary['port_rows'],
            $summary['metric_rows'],
            $summary['table_rows'],
            $summary['warnings'],
            $result->duration
        ));
    }

    /**
     * @param  array<string, float|string>  $values
     * @param  array<string, string>  $types
     */
    private function fields(array $values, array $types, bool $verbose): string
    {
        $parts = [];
        foreach ($values as $name => $value) {
            $type = ($types[$name] ?? 'GAUGE') === 'GAUGE' ? '' : '*';
            $parts[] = $name . $type . '=' . (is_float($value) ? Template::stringify($value) : $value);
        }
        $text = implode(' ', $parts);

        return $verbose ? wordwrap($text, 90, "\n", true) : mb_strimwidth($text, 0, 90, '…');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function columns(array $values, bool $verbose): string
    {
        $parts = [];
        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = $name . '=' . (is_array($value) ? implode(',', $value) : (is_float($value) ? Template::stringify($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value)));
        }
        $text = implode(' ', $parts);

        return $verbose ? wordwrap($text, 90, "\n", true) : mb_strimwidth($text, 0, 90, '…');
    }

    private function limits(\SafferIt\LibrenmsNetconf\Definitions\SensorMapping $m): string
    {
        $parts = [];
        foreach (['limit' => $m->limit, 'low' => $m->limitLow, 'warn' => $m->warnLimit, 'warn_low' => $m->warnLimitLow] as $k => $v) {
            if ($v !== null) {
                $parts[] = "$k=" . Template::stringify($v);
            }
        }

        return implode(' ', $parts);
    }
}
