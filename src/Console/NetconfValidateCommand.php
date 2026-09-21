<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use SafferIt\LibrenmsNetconf\Collect\Collector;
use SafferIt\LibrenmsNetconf\Console\Concerns\PrintsCollection;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionMatcher;
use SafferIt\LibrenmsNetconf\Definitions\DeviceFacts;
use SafferIt\LibrenmsNetconf\Support\FixtureReplay;

/**
 * lnms netconf:validate — lint YAML definitions; with --replay run them against recorded
 * replies and show what would be extracted.
 */
class NetconfValidateCommand extends Command
{
    use PrintsCollection;

    protected $signature = 'netconf:validate
        {paths?* : Extra definition files or directories (shipped + configured user directory are always included)}
        {--only= : Only this definition name}
        {--replay= : Directory with recorded replies (<command-slug>.xml) to extract from}
        {--os= : Match definitions against this os (with --replay)}
        {--hardware= : ... and this hardware string}
        {--os-version= : ... and this software version}
        {--attrib=* : ... and these device attributes, name=value}';

    protected $description = 'Validate NETCONF YAML definitions and optionally replay them against recorded XML';

    public function handle(DefinitionLoader $configured): int
    {
        $dirs = $configured->directories();
        $files = [];
        foreach ((array) $this->argument('paths') as $path) {
            if (is_dir($path)) {
                $dirs[] = $path;
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                $this->error("$path: not found");

                return self::FAILURE;
            }
        }

        $loader = new DefinitionLoader($dirs);
        $definitions = $loader->all();
        $errors = $loader->errors();
        $hints = $loader->hints();
        foreach ($files as $file) {
            try {
                $definition = $loader->load($file, $hints);
                $definitions[$definition->name] = $definition;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($only = $this->option('only')) {
            $definitions = array_filter($definitions, fn ($d) => $d->name === $only);
            if ($definitions === []) {
                $this->error("No definition named $only");

                return self::FAILURE;
            }
        }

        $this->line('<info>Definition directories:</info> ' . implode(', ', $dirs));
        $this->table(['Name', 'Description', 'Match', 'Cmds', 'Sensors', 'Ports', 'Metrics', 'Tables', 'Source'], array_map(fn ($d) => [
            $d->name,
            mb_strimwidth($d->description, 0, 50, '…'),
            implode(' ', array_map(fn ($k, $v) => "$k=$v", array_keys($d->match->describe()), $d->match->describe())),
            ...array_values($d->counts()),
            basename(dirname($d->source)) . '/' . basename($d->source),
        ], array_values($definitions)));

        foreach ($errors as $error) {
            $this->error($error);
        }
        foreach (array_merge($hints, self::sharedCommandHints(array_values($definitions))) as $hint) {
            $this->comment('hint: ' . $hint);
        }

        $replay = $this->option('replay');
        if (is_string($replay) && $replay !== '') {
            return $this->replay($replay, array_values($definitions)) && $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line($errors === [] ? '<info>All definitions valid.</info>' : '<error>' . count($errors) . ' invalid definition(s).</error>');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Definitions that use the same command with a different `every:` or `optional:`: the
     * collector merges them (most frequent, required if any), which is easy to miss when a
     * user definition slows down a command a shipped one needs every poll.
     *
     * @param  list<\SafferIt\LibrenmsNetconf\Definitions\Definition>  $definitions
     * @return list<string>
     */
    public static function sharedCommandHints(array $definitions): array
    {
        /** @var array<string, list<array{string, \SafferIt\LibrenmsNetconf\Definitions\CommandSpec}>> $uses */
        $uses = [];
        foreach ($definitions as $definition) {
            foreach ($definition->commands as $spec) {
                $uses[$spec->identity()][] = [$definition->name, $spec];
            }
        }

        $hints = [];
        foreach ($uses as $list) {
            if (count($list) < 2) {
                continue;
            }
            $everies = array_unique(array_map(fn ($u) => $u[1]->every, $list));
            $optionals = array_unique(array_map(fn ($u) => $u[1]->optional, $list));
            if (count($everies) < 2 && count($optionals) < 2) {
                continue;
            }
            $hints[] = sprintf(
                '"%s" is shared with different settings (%s): it runs every %d poll(s) and is %s for all of them',
                $list[0][1]->label(),
                implode(', ', array_map(fn ($u) => sprintf('%s every=%d%s', $u[0], $u[1]->every, $u[1]->optional ? ' optional' : ''), $list)),
                min($everies),
                in_array(false, $optionals, true) ? 'required' : 'optional'
            );
        }

        return $hints;
    }

    /**
     * @param  list<\SafferIt\LibrenmsNetconf\Definitions\Definition>  $definitions
     */
    private function replay(string $directory, array $definitions): bool
    {
        if (! is_dir($directory)) {
            $this->error("$directory is not a directory");

            return false;
        }

        if ($this->option('os') || $this->option('hardware') || $this->option('os-version')) {
            $attribs = [];
            foreach ((array) $this->option('attrib') as $pair) {
                [$k, $v] = array_pad(explode('=', (string) $pair, 2), 2, '1');
                $attribs[$k] = $v;
            }
            $facts = new DeviceFacts(os: $this->option('os'), hardware: $this->option('hardware'), version: $this->option('os-version'), attribs: $attribs);
            $definitions = (new DefinitionMatcher)->matching($facts, $definitions);
            $this->line('<info>Matching definitions:</info> ' . implode(', ', array_map(fn ($d) => $d->name, $definitions)));
        }

        $commands = [];
        foreach ($definitions as $definition) {
            foreach ($definition->commands as $spec) {
                if ($spec->cli !== null) {
                    $commands[] = $spec->command();
                }
            }
        }
        [$transport, $missing] = FixtureReplay::transport($directory, $commands);
        if ($missing !== []) {
            $this->warn('No fixture for: ' . implode('; ', $missing) . ' (expected ' . implode(', ', array_map(fn ($c) => FixtureReplay::slug($c) . '.xml', $missing)) . ')');
        }

        $result = (new Collector($transport))->collect($definitions);
        $this->printCollection($result, $this->getOutput()->isVerbose());

        return $result->ok();
    }
}
