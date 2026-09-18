<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use DOMElement;
use SafferIt\LibrenmsNetconf\Definitions\Definition;
use SafferIt\LibrenmsNetconf\Definitions\MetricField;
use SafferIt\LibrenmsNetconf\Definitions\MetricMapping;
use SafferIt\LibrenmsNetconf\Definitions\PortMapping;
use SafferIt\LibrenmsNetconf\Definitions\SensorMapping;

/**
 * Applies the mappings of a definition to parsed replies. Pure PHP, no LibreNMS.
 *
 * Rules (plan §3.7): NaN/empty values are skipped (no 0 is invented), rows are filtered
 * with `when:`, `repeat:` expands flattened tables via {n}, duplicate indexes keep the
 * first row and produce a warning.
 */
class Extractor
{
    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, string> */
    private array $deviceVars = [];

    /**
     * @param  array<string, string>  $deviceVars  e.g. ['hostname' => ..., 'sysname' => ...] for {device:...}
     */
    public function __construct(array $deviceVars = [])
    {
        foreach ($deviceVars as $k => $v) {
            $this->deviceVars['device.' . strtolower($k)] = (string) $v;
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function resetWarnings(): void
    {
        $this->warnings = [];
    }

    /**
     * @return list<SensorValue>
     */
    public function sensors(Definition $definition, SensorMapping $mapping, XmlDocument $doc): array
    {
        $result = [];
        $seen = [];
        $label = "$definition->name/{$mapping->id}";

        foreach ($this->iterate($doc, $mapping->rows, $mapping->when, $mapping->repeat, $label) as [$row, $n]) {
            $vars = $this->vars($doc, $row, $n);
            $index = $this->index($doc, $row, $mapping->index, $vars, $label);
            if ($index === null) {
                continue;
            }
            if (isset($seen[$index])) {
                $this->warn("$label: duplicate index \"$index\", keeping the first row");
                continue;
            }
            $seen[$index] = true;
            $vars['index'] = $index;

            $raw = null;
            foreach ($mapping->valueCandidates() as $candidate) {
                $raw = $doc->scalar(Template::substituteN($candidate, $n), $row);
                if ($raw !== null && $raw !== '') {
                    break;
                }
            }
            if ($raw === null || $raw === '') {
                $this->warn("$label [$index]: no value, skipped");
                continue;
            }

            $state = null;
            if ($mapping->isState()) {
                $text = Template::stringify($raw);
                $state = $mapping->resolveState($text);
                if ($state === null) {
                    $this->warn("$label [$index]: state text \"$text\" matches no state, skipped");
                    continue;
                }
                $value = (float) $state->value;
            } else {
                $value = $this->number($raw);
                if ($value === null) {
                    $this->warn("$label [$index]: value \"" . Template::stringify($raw) . '" is not numeric, skipped');
                    continue;
                }
                $value = $value * $mapping->multiplier / ($mapping->divisor ?: 1);
            }

            $result[] = new SensorValue(
                definition: $definition->name,
                mapping: $mapping,
                index: $index,
                descr: Template::render($mapping->descr, $doc, $row, $vars) ?: $index,
                value: $value,
                state: $state,
                rawText: $mapping->isState() ? Template::stringify($raw) : null,
                re: $vars['re'] !== '' ? $vars['re'] : null,
            );
        }

        return $result;
    }

    /**
     * @return list<PortMetricRow>
     */
    public function ports(Definition $definition, PortMapping $mapping, XmlDocument $doc): array
    {
        $result = [];
        $seen = [];
        $label = "$definition->name/{$mapping->id}";

        foreach ($this->iterate($doc, $mapping->rows, $mapping->when, $mapping->repeat, $label) as [$row, $n]) {
            $match = Template::stringify($doc->scalar(Template::substituteN($mapping->matchXpath, $n), $row));
            if ($match === '') {
                $this->warn("$label: row without {$mapping->matchField} value, skipped");
                continue;
            }
            if (isset($seen[$match])) {
                $this->warn("$label: duplicate {$mapping->matchField} \"$match\", keeping the first row");
                continue;
            }
            $seen[$match] = true;

            [$values, , $types] = $this->fields($mapping->metrics, $doc, $row, $n, "$label [$match]", false);
            if ($values === []) {
                continue;
            }

            $result[] = new PortMetricRow($definition->name, $mapping, $mapping->matchField, $match, $values, $types, $doc->reName($row));
        }

        return $result;
    }

    /**
     * @return list<MetricRow>
     */
    public function metrics(Definition $definition, MetricMapping $mapping, XmlDocument $doc): array
    {
        $result = [];
        $seen = [];
        $label = "$definition->name/{$mapping->id}";

        foreach ($this->iterate($doc, $mapping->rows, $mapping->when, $mapping->repeat, $label) as [$row, $n]) {
            $vars = $this->vars($doc, $row, $n);
            $index = $this->index($doc, $row, $mapping->index, $vars, $label);
            if ($index === null) {
                continue;
            }
            if (isset($seen[$index])) {
                $this->warn("$label: duplicate index \"$index\", keeping the first row");
                continue;
            }
            $seen[$index] = true;
            $vars['index'] = $index;

            [$values, $strings, $types] = $this->fields($mapping->fields, $doc, $row, $n, "$label [$index]", true);
            if ($values === [] && $strings === []) {
                continue;
            }

            $result[] = new MetricRow(
                $definition->name,
                $mapping,
                $index,
                Template::render($mapping->descr, $doc, $row, $vars) ?: $index,
                $values,
                $strings,
                $types,
                $vars['re'] !== '' ? $vars['re'] : null,
            );
        }

        return $result;
    }

    /**
     * Rows to process: (element, n) pairs. Without `rows:` the payload root is the single
     * row; `repeat:` yields the same element once per n in 1..N.
     *
     * @return list<array{DOMElement, string|null}>
     */
    private function iterate(XmlDocument $doc, ?string $rows, ?string $when, ?string $repeat, string $label): array
    {
        $elements = $rows === null ? [$doc->root()] : $doc->rows($rows);
        $result = [];

        foreach ($elements as $element) {
            if ($repeat === null) {
                if ($when === null || $doc->bool($when, $element)) {
                    $result[] = [$element, null];
                }
                continue;
            }

            $count = $this->number($doc->scalar($repeat, $element));
            if ($count === null || $count < 0) {
                $this->warn("$label: repeat expression did not yield a count");
                continue;
            }
            for ($n = 1; $n <= (int) $count; $n++) {
                if ($when === null || $doc->bool(Template::substituteN($when, (string) $n), $element)) {
                    $result[] = [$element, (string) $n];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function vars(XmlDocument $doc, DOMElement $row, ?string $n): array
    {
        return $this->deviceVars + [
            're' => $doc->reName($row) ?? '',
            'n' => $n ?? '',
            'index' => '',
        ];
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function index(XmlDocument $doc, DOMElement $row, string $expression, array $vars, string $label): ?string
    {
        $index = Template::isTemplate($expression)
            ? Template::render($expression, $doc, $row, $vars)
            : Template::stringify($doc->scalar(Template::substituteN($expression, $vars['n'] ?: null), $row));

        $index = trim($index);
        if ($index === '') {
            $this->warn("$label: row with empty index, skipped");

            return null;
        }

        return $index;
    }

    /**
     * Values of one row. $types lists every RRD field of the mapping in YAML order (present
     * or not) so the writers create a stable data source set; `type: string` fields go to
     * $strings, numeric fields whose text is not a number are kept as strings for display
     * (custom metrics) or reported (ports).
     *
     * @param  list<MetricField>  $fields
     * @return array{array<string, float>, array<string, string>, array<string, string>}
     */
    private function fields(array $fields, XmlDocument $doc, DOMElement $row, ?string $n, string $label, bool $keepStrings): array
    {
        $values = [];
        $strings = [];
        $types = [];
        foreach ($fields as $field) {
            if (! $field->isText()) {
                $types[$field->name] = $field->type;
            }
            $raw = $doc->scalar(Template::substituteN($field->xpath, $n), $row);
            if ($raw === null || $raw === '') {
                continue;
            }
            if ($field->isText()) {
                $strings[$field->name] = Template::stringify($raw);
                continue;
            }
            $number = $this->number($raw, $field->transform);
            if ($number !== null) {
                $values[$field->name] = $number;
            } elseif ($keepStrings) {
                $strings[$field->name] = Template::stringify($raw);
            } else {
                $this->warn("$label: {$field->name} \"" . Template::stringify($raw) . '" is not numeric, skipped');
            }
        }

        return [$values, $strings, $types];
    }

    /**
     * Junos prints counters as plain integers, but some fields carry units ("0 bps",
     * "12.5 %", "1,234"). Transforms: none | "duration" (Junos "1w2d 03:04:05" → seconds).
     */
    private function number(string|float|bool|null $raw, ?string $transform = null): ?float
    {
        if ($raw === null) {
            return null;
        }
        if (is_bool($raw)) {
            return $raw ? 1.0 : 0.0;
        }
        if (is_float($raw)) {
            return is_finite($raw) ? $raw : null;
        }

        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        if ($transform === 'duration') {
            return self::duration($text);
        }

        // whole string must be a number, optionally with thousands separators and a unit
        // ("1,234 bps", "12.5 %"); "00:11:22:..." and "192.0.2.11" stay strings
        $text = str_replace(',', '', $text);
        if (preg_match('/^([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)\s*(?:%|[A-Za-z\/]{1,8})?$/', $text, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /** "75w0d 12:49" / "1d 02:03:04" / "00:05:12" → seconds. */
    public static function duration(string $text): ?float
    {
        $seconds = 0.0;
        $matched = false;
        if (preg_match('/(\d+)w/', $text, $m)) {
            $seconds += (int) $m[1] * 604800;
            $matched = true;
        }
        if (preg_match('/(\d+)d/', $text, $m)) {
            $seconds += (int) $m[1] * 86400;
            $matched = true;
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $text, $m)) {
            $seconds += (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
            $matched = true;
        }

        return $matched ? $seconds : null;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
