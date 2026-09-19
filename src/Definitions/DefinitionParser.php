<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

use SafferIt\LibrenmsNetconf\Extract\Template;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

/**
 * Turns the decoded YAML array into Definition objects and validates the schema:
 * required keys, known enums, command references, XPath syntax, unique ids.
 */
class DefinitionParser
{
    public const SENSOR_CLASSES = [
        'airflow', 'ber', 'bitrate', 'charge', 'chromatic_dispersion', 'cooling', 'count', 'current', 'dbm', 'delay',
        'eer', 'fanspeed', 'frequency', 'humidity', 'load', 'loss', 'percent', 'power', 'power_consumed',
        'power_factor', 'pressure', 'quality_factor', 'runtime', 'signal', 'snr', 'state', 'temperature',
        'tv_signal', 'voltage', 'waterflow',
    ];

    private string $source = '';

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws DefinitionException
     */
    public function parse(array $data, string $source): Definition
    {
        $this->source = $source;

        $name = $this->string($data, 'name', required: true);
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name)) {
            throw $this->error('name', 'must be lowercase letters, digits, "-", "_" or "." (used in sensor_type and RRD names)');
        }
        $this->knownKeys($data, ['name', 'description', 'enabled', 'match', 'commands', 'sensors', 'ports', 'metrics', 'tables'], '');

        $commands = $this->commands($data['commands'] ?? null);
        $definition = new Definition(
            name: $name,
            description: $this->string($data, 'description') ?? '',
            match: $this->match($data['match'] ?? []),
            commands: $commands,
            sensors: $this->sensors($data['sensors'] ?? [], $commands),
            ports: $this->ports($data['ports'] ?? [], $commands),
            metrics: $this->metrics($data['metrics'] ?? [], $commands),
            tables: $this->tables($data['tables'] ?? [], $commands),
            source: $source,
            enabled: $this->bool($data, 'enabled', true),
        );

        if ($definition->sensors === [] && $definition->ports === [] && $definition->metrics === [] && $definition->tables === []) {
            throw $this->error('', 'definition has no sensors, ports, metrics or tables');
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function match(mixed $data): MatchSpec
    {
        if (! is_array($data)) {
            throw $this->error('match', 'must be a mapping');
        }
        $this->knownKeys($data, ['os', 'hardware', 'version', 'hostname', 'attrib'], 'match');

        $pattern = function (string $key) use ($data): ?Pattern {
            if (! isset($data[$key])) {
                return null;
            }
            $value = $data[$key];
            if (! is_string($value) && ! is_array($value)) {
                throw $this->error("match.$key", 'must be a string or list of strings');
            }
            foreach ((array) $value as $alt) {
                if (is_string($alt) && Pattern::isRegex($alt) && @preg_match($alt, '') === false) {
                    throw $this->error("match.$key", "invalid regex $alt");
                }
            }

            return new Pattern($value);
        };

        return new MatchSpec(
            os: $pattern('os'),
            hardware: $pattern('hardware'),
            version: $pattern('version'),
            hostname: $pattern('hostname'),
            attrib: isset($data['attrib']) ? (string) $data['attrib'] : null,
        );
    }

    /**
     * @return array<string, CommandSpec>
     */
    private function commands(mixed $data): array
    {
        if (! is_array($data) || $data === []) {
            throw $this->error('commands', 'at least one command is required');
        }

        $commands = [];
        foreach ($data as $key => $spec) {
            $path = "commands.$key";
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]+$/i', $key)) {
                throw $this->error($path, 'command keys must be identifiers');
            }
            if (is_string($spec)) {
                $spec = ['cli' => $spec];
            }
            if (! is_array($spec)) {
                throw $this->error($path, 'must be a cli string or a mapping');
            }
            $this->knownKeys($spec, ['cli', 'rpc', 'optional', 'every', 'description'], $path);

            $cli = $this->string($spec, 'cli', $path);
            $rpc = $this->string($spec, 'rpc', $path);
            if (($cli === null) === ($rpc === null)) {
                throw $this->error($path, 'exactly one of cli or rpc is required');
            }
            if ($cli !== null && ! preg_match('/^show\s/i', $cli)) {
                throw $this->error("$path.cli", 'only "show ..." commands are allowed');
            }
            $every = (int) ($spec['every'] ?? 1);
            if ($every < 1) {
                throw $this->error("$path.every", 'must be >= 1');
            }

            $commands[$key] = new CommandSpec(
                key: $key,
                cli: $cli,
                rpc: $rpc,
                optional: $this->bool($spec, 'optional', false, $path),
                every: $every,
                description: $this->string($spec, 'description', $path) ?? '',
            );
        }

        return $commands;
    }

    /**
     * @param  array<string, CommandSpec>  $commands
     * @return list<SensorMapping>
     */
    private function sensors(mixed $list, array $commands): array
    {
        $result = [];
        $ids = [];
        foreach ($this->list($list, 'sensors') as $i => $item) {
            $path = "sensors[$i]";
            $this->knownKeys($item, [
                'id', 'class', 'command', 'rows', 'when', 'repeat', 'index', 'descr', 'value', 'value_any', 'group',
                'limit', 'limit_low', 'warn_limit', 'warn_limit_low', 'divisor', 'multiplier', 'states',
            ], $path);

            $class = $this->string($item, 'class', $path, true);
            if (! in_array($class, self::SENSOR_CLASSES, true)) {
                throw $this->error("$path.class", "unknown sensor class \"$class\"");
            }

            $valueAny = $item['value_any'] ?? [];
            if (! is_array($valueAny)) {
                throw $this->error("$path.value_any", 'must be a list of XPath expressions');
            }
            $value = $this->string($item, 'value', $path);
            if ($value === null && $valueAny === []) {
                throw $this->error($path, 'value or value_any is required');
            }
            foreach ($valueAny as $j => $candidate) {
                $this->xpath((string) $candidate, "$path.value_any[$j]");
            }

            $states = [];
            if ($class === 'state') {
                if (! isset($item['states']) || ! is_array($item['states']) || $item['states'] === []) {
                    throw $this->error("$path.states", 'state sensors need a states list');
                }
                $states = $this->states($item['states'], $path);
            }

            $id = $this->id($item, $path, 'sensor' . ($i + 1), $ids);
            $result[] = new SensorMapping(
                id: $id,
                class: $class,
                command: $this->commandRef($item, $commands, $path),
                index: $this->indexExpr($item, $path),
                descr: $this->string($item, 'descr', $path, true),
                rows: $this->xpath($this->string($item, 'rows', $path), "$path.rows"),
                when: $this->xpath($this->string($item, 'when', $path), "$path.when"),
                repeat: $this->xpath($this->string($item, 'repeat', $path), "$path.repeat"),
                value: $this->xpath($value, "$path.value"),
                valueAny: array_values(array_map('strval', $valueAny)),
                group: $this->string($item, 'group', $path),
                limit: $this->float($item, 'limit', $path),
                limitLow: $this->float($item, 'limit_low', $path),
                warnLimit: $this->float($item, 'warn_limit', $path),
                warnLimitLow: $this->float($item, 'warn_limit_low', $path),
                divisor: $this->float($item, 'divisor', $path) ?? 1,
                multiplier: $this->float($item, 'multiplier', $path) ?? 1,
                states: $states,
            );
        }

        return $result;
    }

    /**
     * @return list<StateSpec>
     */
    private function states(mixed $data, string $path): array
    {
        if (! is_array($data)) {
            throw $this->error("$path.states", 'must be a list or mapping');
        }

        $states = [];
        $values = [];
        $isList = array_is_list($data);
        foreach ($data as $key => $spec) {
            $label = $isList ? ($spec['label'] ?? null) : (string) $key;
            if (! is_array($spec) || ! is_string($label) || $label === '') {
                throw $this->error("$path.states", 'each state needs a label (mapping key or "label") with value and generic');
            }
            $this->knownKeys($spec, ['label', 'match', 'value', 'generic', 'default'], "$path.states.$label");
            if (! isset($spec['value']) || ! isset($spec['generic'])) {
                throw $this->error("$path.states.$label", 'value and generic are required');
            }
            $generic = (int) $spec['generic'];
            if ($generic < 0 || $generic > 3) {
                throw $this->error("$path.states.$label", 'generic must be 0 (ok), 1 (warning), 2 (critical) or 3 (unknown)');
            }
            $value = (int) $spec['value'];
            if (isset($values[$value])) {
                throw $this->error("$path.states.$label", "value $value is already used by state \"{$values[$value]}\"");
            }
            $values[$value] = $label;

            $match = $spec['match'] ?? $label;
            if (is_string($match) && Pattern::isRegex($match) && @preg_match($match, '') === false) {
                throw $this->error("$path.states.$label", "invalid regex $match");
            }

            $states[] = new StateSpec($label, new Pattern($match), $value, $generic, (bool) ($spec['default'] ?? false));
        }

        return $states;
    }

    /**
     * @param  array<string, CommandSpec>  $commands
     * @return list<PortMapping>
     */
    private function ports(mixed $list, array $commands): array
    {
        $result = [];
        $ids = [];
        foreach ($this->list($list, 'ports') as $i => $item) {
            $path = "ports[$i]";
            $this->knownKeys($item, ['id', 'command', 'rows', 'when', 'repeat', 'match', 'metrics'], $path);

            $match = $item['match'] ?? null;
            if (! is_array($match) || ! isset($match['port_field'], $match['xpath'])) {
                throw $this->error("$path.match", 'needs port_field and xpath');
            }
            if (! in_array($match['port_field'], PortMapping::MATCH_FIELDS, true)) {
                throw $this->error("$path.match.port_field", 'must be one of ' . implode(', ', PortMapping::MATCH_FIELDS));
            }

            $result[] = new PortMapping(
                id: $this->id($item, $path, 'port' . ($i + 1), $ids),
                command: $this->commandRef($item, $commands, $path),
                rows: $this->xpath($this->string($item, 'rows', $path, true), "$path.rows"),
                matchField: (string) $match['port_field'],
                matchXpath: $this->xpath((string) $match['xpath'], "$path.match.xpath"),
                metrics: $this->fields($item['metrics'] ?? null, "$path.metrics", true),
                when: $this->xpath($this->string($item, 'when', $path), "$path.when"),
                repeat: $this->xpath($this->string($item, 'repeat', $path), "$path.repeat"),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, CommandSpec>  $commands
     * @return list<MetricMapping>
     */
    private function metrics(mixed $list, array $commands): array
    {
        $result = [];
        $ids = [];
        foreach ($this->list($list, 'metrics') as $i => $item) {
            $path = "metrics[$i]";
            $this->knownKeys($item, ['id', 'command', 'rows', 'when', 'repeat', 'index', 'descr', 'fields', 'group'], $path);

            $result[] = new MetricMapping(
                id: $this->id($item, $path, 'metric' . ($i + 1), $ids),
                command: $this->commandRef($item, $commands, $path),
                index: $this->indexExpr($item, $path),
                fields: $this->fields($item['fields'] ?? null, "$path.fields", false),
                rows: $this->xpath($this->string($item, 'rows', $path), "$path.rows"),
                when: $this->xpath($this->string($item, 'when', $path), "$path.when"),
                repeat: $this->xpath($this->string($item, 'repeat', $path), "$path.repeat"),
                descr: $this->string($item, 'descr', $path) ?? '{index}',
                group: $this->string($item, 'group', $path),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, CommandSpec>  $commands
     * @return list<TableMapping>
     */
    private function tables(mixed $list, array $commands): array
    {
        $result = [];
        $ids = [];
        foreach ($this->list($list, 'tables') as $i => $item) {
            $path = "tables[$i]";
            $this->knownKeys($item, ['id', 'table', 'command', 'rows', 'when', 'repeat', 'columns'], $path);

            $table = $this->string($item, 'table', $path, true);
            if (! TableSchema::isWritable($table)) {
                throw $this->error("$path.table", "unknown table \"$table\" (allowed: " . implode(', ', TableSchema::writable()) . ')');
            }

            $columns = $this->columns($item['columns'] ?? null, $table, "$path.columns");
            $names = array_map(fn (TableColumn $c) => $c->name, $columns);
            foreach (TableSchema::key($table) as $key) {
                if (! in_array($key, $names, true)) {
                    throw $this->error("$path.columns", "key column \"$key\" of table \"$table\" is required");
                }
            }

            $result[] = new TableMapping(
                id: $this->id($item, $path, 'table' . ($i + 1), $ids),
                table: $table,
                command: $this->commandRef($item, $commands, $path),
                columns: $columns,
                rows: $this->xpath($this->string($item, 'rows', $path), "$path.rows"),
                when: $this->xpath($this->string($item, 'when', $path), "$path.when"),
                repeat: $this->xpath($this->string($item, 'repeat', $path), "$path.repeat"),
            );
        }

        return $result;
    }

    /**
     * Columns of a table mapping: `{column: xpath}` or `{column: {xpath, transform}}`; the
     * type comes from TableSchema.
     *
     * @return list<TableColumn>
     */
    private function columns(mixed $data, string $table, string $path): array
    {
        if (! is_array($data) || $data === [] || array_is_list($data)) {
            throw $this->error($path, 'must be a mapping of column => xpath with at least one column');
        }

        $columns = [];
        foreach ($data as $name => $spec) {
            $name = (string) $name;
            $type = TableSchema::type($table, $name);
            if ($type === null) {
                throw $this->error("$path.$name", "unknown column of table \"$table\" (allowed: " . implode(', ', array_keys(TableSchema::columns($table))) . ')');
            }
            if (is_string($spec)) {
                $spec = ['xpath' => $spec];
            }
            if (! is_array($spec) || ! isset($spec['xpath'])) {
                throw $this->error("$path.$name", 'needs an xpath');
            }
            $this->knownKeys($spec, ['xpath', 'transform'], "$path.$name");
            $transform = isset($spec['transform']) ? (string) $spec['transform'] : null;
            if ($transform !== null && ! in_array($transform, TableSchema::TRANSFORMS, true)) {
                throw $this->error("$path.$name.transform", 'must be one of ' . implode(', ', TableSchema::TRANSFORMS));
            }

            $columns[] = new TableColumn($name, $this->xpath((string) $spec['xpath'], "$path.$name") ?? '', $type, $transform);
        }

        return $columns;
    }

    /**
     * Accepts `{name: xpath}` maps, `{name: {xpath, type}}` maps and `[{name, xpath, type}]` lists.
     *
     * @param  bool  $rrdOnly  ports: every field is an RRD data source, `type: string` is not allowed
     * @return list<MetricField>
     */
    private function fields(mixed $data, string $path, bool $rrdOnly): array
    {
        if (! is_array($data) || $data === []) {
            throw $this->error($path, 'at least one field is required');
        }

        $fields = [];
        $names = [];
        foreach ($data as $key => $spec) {
            if (is_string($spec)) {
                $spec = ['name' => $key, 'xpath' => $spec];
            } elseif (is_array($spec) && ! isset($spec['name']) && is_string($key)) {
                $spec['name'] = $key;
            }
            if (! is_array($spec) || ! isset($spec['name'], $spec['xpath'])) {
                throw $this->error("$path.$key", 'needs name and xpath');
            }
            $this->knownKeys($spec, ['name', 'xpath', 'type', 'transform'], "$path.$key");

            $name = (string) $spec['name'];
            if (! preg_match('/^[A-Za-z0-9_]{1,19}$/', $name)) {
                throw $this->error("$path.$key", "field name \"$name\" must be 1-19 characters [A-Za-z0-9_] (RRD data source)");
            }
            if (isset($names[$name])) {
                throw $this->error("$path.$key", "duplicate field name \"$name\"");
            }
            $names[$name] = true;

            $type = strtoupper((string) ($spec['type'] ?? 'GAUGE'));
            if ($rrdOnly && ! in_array($type, MetricField::RRD_TYPES, true)) {
                throw $this->error("$path.$key.type", 'must be GAUGE, COUNTER or DERIVE');
            }
            if (! in_array($type, MetricField::TYPES, true)) {
                throw $this->error("$path.$key.type", 'must be GAUGE, COUNTER, DERIVE or string');
            }

            $fields[] = new MetricField($name, $this->xpath((string) $spec['xpath'], "$path.$key.xpath") ?? '', $type, isset($spec['transform']) ? (string) $spec['transform'] : null);
        }

        return $fields;
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $list, string $path): array
    {
        if ($list === null) {
            return [];
        }
        if (! is_array($list) || ! array_is_list($list)) {
            throw $this->error($path, 'must be a list');
        }
        foreach ($list as $i => $item) {
            if (! is_array($item)) {
                throw $this->error("{$path}[$i]", 'must be a mapping');
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, CommandSpec>  $commands
     */
    private function commandRef(array $item, array $commands, string $path): string
    {
        $ref = $this->string($item, 'command', $path, true);
        if (! isset($commands[$ref])) {
            throw $this->error("$path.command", "references unknown command \"$ref\" (known: " . implode(', ', array_keys($commands)) . ')');
        }

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function indexExpr(array $item, string $path): string
    {
        $index = $this->string($item, 'index', $path, true);
        if (! Template::isTemplate($index)) {
            $this->xpath($index, "$path.index");
            if (preg_match('/^[A-Za-z_][\w.-]*$/', $index) && ! str_contains($index, '(')) {
                // bare element name: fine, but a literal needs quotes — most such mistakes are literals
                $this->hint("$path.index", "\"$index\" is evaluated as an XPath element; for a literal write \"'$index'\"");
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, true>  $ids
     */
    private function id(array $item, string $path, string $default, array &$ids): string
    {
        $id = $this->string($item, 'id', $path) ?? $default;
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $id)) {
            throw $this->error("$path.id", 'must be an identifier');
        }
        if (isset($ids[$id])) {
            throw $this->error("$path.id", "duplicate id \"$id\"");
        }
        $ids[$id] = true;

        return $id;
    }

    private function xpath(?string $expression, string $path): ?string
    {
        if ($expression === null) {
            return null;
        }
        $error = XmlDocument::validateExpression(Template::substituteN($expression, '1'));
        if ($error !== null) {
            throw $this->error($path, $error);
        }

        return $expression;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key, string $path = '', bool $required = false): ?string
    {
        $full = $path === '' ? $key : "$path.$key";
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            if ($required) {
                throw $this->error($full, 'is required');
            }

            return null;
        }
        if (! is_scalar($data[$key])) {
            throw $this->error($full, 'must be a string');
        }

        return trim((string) $data[$key]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function bool(array $data, string $key, bool $default, string $path = ''): bool
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }
        $value = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw $this->error($path === '' ? $key : "$path.$key", 'must be true or false');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function float(array $data, string $key, string $path): ?float
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (! is_numeric($data[$key])) {
            throw $this->error("$path.$key", 'must be a number');
        }

        return (float) $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function knownKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw $this->error($path === '' ? (string) $key : "$path.$key", 'unknown key (allowed: ' . implode(', ', $allowed) . ')');
            }
        }
    }

    /** @var list<string> */
    private array $hints = [];

    private function hint(string $path, string $message): void
    {
        $this->hints[] = sprintf('%s [%s]: %s', basename($this->source), $path, $message);
    }

    /**
     * Non-fatal remarks collected while parsing the last definition.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }

    private function error(string $path, string $problem): DefinitionException
    {
        return DefinitionException::at(basename($this->source), $path, $problem);
    }
}
