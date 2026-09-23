<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use DOMElement;
use SafferIt\LibrenmsNetconf\Definitions\Definition;
use SafferIt\LibrenmsNetconf\Definitions\MetricField;
use SafferIt\LibrenmsNetconf\Definitions\MetricMapping;
use SafferIt\LibrenmsNetconf\Definitions\PortMapping;
use SafferIt\LibrenmsNetconf\Definitions\SensorMapping;
use SafferIt\LibrenmsNetconf\Definitions\TableColumn;
use SafferIt\LibrenmsNetconf\Definitions\TableMapping;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Support\Mac;

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
        $label = "$definition->name/{$mapping->id}";

        foreach ($this->indexedRows($doc, $mapping, $label, Identity::SENSOR_WIDTH) as [$row, $n, $index, $vars]) {
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
        $label = "$definition->name/{$mapping->id}";

        foreach ($this->indexedRows($doc, $mapping, $label, Identity::METRIC_WIDTH) as [$row, $n, $index, $vars]) {
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
     * Rows for a plugin table: every column of the mapping is coerced to its TableSchema
     * type (null when the reply has no usable value); rows whose key column is empty are
     * skipped, duplicate keys keep the first row.
     *
     * @return list<TableRow>
     */
    public function tables(Definition $definition, TableMapping $mapping, XmlDocument $doc): array
    {
        $result = [];
        $seen = [];
        $label = "$definition->name/{$mapping->id}";
        $keyColumns = $mapping->keyColumns();

        foreach ($this->iterate($doc, $mapping->rows, $mapping->when, $mapping->repeat, $label) as [$row, $n]) {
            $values = [];
            foreach ($mapping->columns as $column) {
                $values[$column->name] = $this->column($column, $doc, $row, $n, $label);
            }

            $incomplete = false;
            foreach ($keyColumns as $key) {
                if ($values[$key] === null || $values[$key] === '' || $values[$key] === []) {
                    $this->warn("$label: row without $key, skipped");
                    $incomplete = true;
                    break;
                }
            }
            if ($incomplete) {
                continue;
            }

            $key = TableRow::keyOf($keyColumns, $values);
            if (isset($seen[$key])) {
                $this->warn("$label: duplicate key \"$key\", keeping the first row");
                continue;
            }
            $seen[$key] = true;

            $result[] = new TableRow($definition->name, $mapping, $key, $values);
        }

        return $result;
    }

    /**
     * Evaluate one table column: node-sets collapse to the first node (json columns keep
     * every node), the transform runs on the text, then the type coercion.
     *
     * @return int|string|bool|list<string>|null
     */
    private function column(TableColumn $column, XmlDocument $doc, DOMElement $row, ?string $n, string $label): int|string|bool|array|null
    {
        $raw = $doc->evaluateRaw(Template::substituteN($column->xpath, $n), $row);

        if ($column->type === TableSchema::TYPE_JSON) {
            return $this->list($raw);
        }

        if ($raw instanceof \DOMNodeList) {
            $first = $raw->item(0);
            $raw = $first === null ? '' : trim($first->textContent);
        } elseif (is_float($raw) && is_nan($raw)) {
            $raw = null;
        }
        if ($raw === null || $raw === '') {
            return null;
        }

        $text = Template::stringify($raw);
        if ($column->transform !== null) {
            $text = self::transform($column->transform, $text);
            if ($text === null) {
                $this->warn("$label: {$column->name} \"" . Template::stringify($raw) . "\" has no {$column->transform} value, left empty");

                return null;
            }
        }

        $value = self::coerce($text, $column->type, is_bool($raw) ? $raw : null);
        if ($value === null && $text !== '') {
            $this->warn("$label: {$column->name} \"$text\" is not a valid {$column->type}, left empty");
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function list(mixed $raw): array
    {
        $items = [];
        if ($raw instanceof \DOMNodeList) {
            foreach ($raw as $node) {
                $items[] = trim($node->textContent);
            }
        } elseif (is_string($raw)) {
            $items = preg_split('/[\s,]+/', trim($raw)) ?: [];
        } elseif ($raw !== null && ! (is_float($raw) && is_nan($raw))) {
            $items[] = Template::stringify($raw);
        }

        return array_values(array_unique(array_filter($items, fn (string $item) => $item !== '')));
    }

    /**
     * Text transforms for table columns: "duration" (Junos "1w2d 03:04:05" -> seconds),
     * "evpn_source" (an EVPN active source -> esi | remote | local), "timestamp" (Junos
     * "Sep 18 14:15:11" without a year -> "Y-m-d H:i:s", never in the future).
     */
    public static function transform(string $transform, string $text): ?string
    {
        switch ($transform) {
            case 'duration':
                $seconds = self::duration($text);

                return $seconds === null ? null : (string) (int) $seconds;
            case 'evpn_source':
                return self::evpnSourceType($text);
            case 'timestamp':
                return self::junosTimestamp($text);
        }

        return $text;
    }

    /** ESI (10 octets) -> esi, IP address -> remote, anything else (a local IFL) -> local. */
    public static function evpnSourceType(string $text): string
    {
        if (preg_match('/^([0-9a-f]{2}:){9}[0-9a-f]{2}$/i', $text)) {
            return 'esi';
        }
        if (filter_var($text, FILTER_VALIDATE_IP) !== false) {
            return 'remote';
        }

        return 'local';
    }

    /** "Sep 18 14:15:11" (no year) -> "Y-m-d H:i:s" using the current year, or last year when that lies ahead of now. */
    public static function junosTimestamp(string $text, ?int $now = null): ?string
    {
        $now ??= time();
        if (preg_match('/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($text), $m)) {
            $year = (int) date('Y', $now);
            $ts = strtotime(sprintf('%s %d %d %02d:%02d:%02d', $m[1], (int) $m[2], $year, (int) $m[3], (int) $m[4], (int) ($m[5] ?? 0)));
            if ($ts === false) {
                return null;
            }
            if ($ts > $now + 86400) {
                $ts = strtotime(sprintf('%s %d %d %02d:%02d:%02d', $m[1], (int) $m[2], $year - 1, (int) $m[3], (int) $m[4], (int) ($m[5] ?? 0))) ?: $ts;
            }

            return date('Y-m-d H:i:s', $ts);
        }

        $ts = strtotime($text);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /**
     * Coerce a text to a TableSchema type; null when it does not fit.
     */
    public static function coerce(string $text, string $type, ?bool $bool = null): int|string|bool|null
    {
        $text = trim($text);
        switch ($type) {
            case TableSchema::TYPE_INT:
                $number = (new self)->number($text);

                return $number === null ? null : (int) round($number);
            case TableSchema::TYPE_BOOL:
                if ($bool !== null) {
                    return $bool;
                }
                if (is_numeric($text)) {
                    return (float) $text != 0;
                }
                $lower = strtolower($text);
                if (in_array($lower, ['true', 'yes', 'on', 'up', 'enabled', 'aliasing', 'active', 'y'], true) || str_starts_with($lower, 'up/')) {
                    return true;
                }
                if (in_array($lower, ['false', 'no', 'off', 'down', 'disabled', 'inactive', 'n', 'none', 'no aliasing'], true) || str_starts_with($lower, 'down/')) {
                    return false;
                }

                return null;
            case TableSchema::TYPE_IP:
                return filter_var($text, FILTER_VALIDATE_IP) === false ? null : $text;
            case TableSchema::TYPE_MAC:
                return Mac::hex($text);
            case TableSchema::TYPE_DATETIME:
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text)) {
                    return $text;
                }
                $ts = strtotime($text);

                return $ts === false ? null : date('Y-m-d H:i:s', $ts);
        }

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    /**
     * The rows sensors() and metrics() work on: iterate(), plus the index every such row is
     * identified by. Rows without an index and rows repeating an index already used are
     * dropped with a warning; what is done with the row afterwards (a state or a number, one
     * value or a field set) is the caller's business.
     *
     * @param  int  $width  the column the index will be stored in (Identity::fit())
     * @return \Generator<int, array{DOMElement, string|null, string, array<string, string>}>
     */
    private function indexedRows(XmlDocument $doc, SensorMapping|MetricMapping $mapping, string $label, int $width): \Generator
    {
        $seen = [];

        foreach ($this->iterate($doc, $mapping->rows, $mapping->when, $mapping->repeat, $label) as [$row, $n]) {
            $vars = $this->vars($doc, $row, $n);
            $index = $this->index($doc, $row, $mapping->index, $vars, $label, $width);
            if ($index === null) {
                continue;
            }
            if (isset($seen[$index])) {
                $this->warn("$label: duplicate index \"$index\", keeping the first row");
                continue;
            }
            $seen[$index] = true;
            $vars['index'] = $index;

            yield [$row, $n, $index, $vars];
        }
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
     * The row's index, fitted to the column it will be stored in (Identity::fit()), so
     * discovery, the record lookup and the RRD name all use the same string.
     *
     * @param  array<string, string>  $vars
     */
    private function index(XmlDocument $doc, DOMElement $row, string $expression, array $vars, string $label, int $width): ?string
    {
        $index = Template::isTemplate($expression)
            ? Template::render($expression, $doc, $row, $vars)
            : Template::stringify($doc->scalar(Template::substituteN($expression, $vars['n'] ?: null), $row));

        $index = trim($index);
        if ($index === '') {
            $this->warn("$label: row with empty index, skipped");

            return null;
        }
        if (Identity::exceeds($index, $width)) {
            $fitted = Identity::fit($index, $width);
            $this->warn(sprintf('%s: index "%s" has %d characters, the column holds %d; stored as "%s" (shorten index:)', $label, mb_strimwidth($index, 0, 40, '…'), mb_strlen($index), $width, $fitted));

            return $fitted;
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
