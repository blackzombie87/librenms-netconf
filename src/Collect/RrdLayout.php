<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Keeps the RRD update positional-safe.
 *
 * LibreNMS' Rrd store updates with "N:v1:v2:…" in the order of the fields array and has no
 * --template, so the plugin must know the data source order of the file on disk. That order
 * is stored on the row (netconf_metrics.types / netconf_port_metrics.types) after every
 * verified write; the steady state costs nothing. Only when the stored order is missing
 * (first poll after upgrade, new row) or differs from the mapping (YAML changed) does this
 * class ask rrdtool for the file's data sources and add the missing ones with
 * "rrdtool tune --data-source-add", so history is kept and no value lands in the wrong slot.
 */
class RrdLayout
{
    /** @var array<string, true> files already warned about in this run */
    private array $warned = [];

    /** @var array<string, int> */
    private array $stats = ['verified' => 0, 'added' => 0, 'failed' => 0];

    public function __construct(
        private readonly string $rrdtool = 'rrdtool',
        private readonly string $rrdcached = '',
        private readonly string $rrdDir = '',
        private readonly int $heartbeat = 600,
    ) {
    }

    public static function make(): self
    {
        return new self(
            (string) LibrenmsConfig::get('rrdtool', 'rrdtool'),
            (string) LibrenmsConfig::get('rrdcached', ''),
            (string) LibrenmsConfig::get('rrd_dir', LibrenmsConfig::get('install_dir') . '/rrd'),
            (int) LibrenmsConfig::get('rrd.heartbeat', 600),
        );
    }

    /**
     * The data sources to write, in file order (name => type).
     *
     * @param  array<string, string>  $desired  the mapping's RRD fields in YAML order
     * @param  array<string, string>|null  $stored  order of the last verified write, null when unknown
     * @return array<string, string>
     */
    public function reconcile(string $file, array $desired, ?array $stored): array
    {
        if ($stored !== null && array_diff_key($desired, $stored) === []) {
            return $stored;   // every wanted field already has its slot; extra slots keep receiving U
        }

        if (! Rrd::checkRrdExists($file)) {
            return $desired;   // the store creates the file in this order
        }

        $current = $this->dataSources($file);
        if ($current === null) {
            $this->warn($file, 'could not read the data sources of ' . $file . ', writing in the last known order');

            return $stored ?? $desired;
        }
        $this->stats['verified']++;

        ['order' => $order, 'add' => $add] = self::plan($current, $desired);
        if ($add !== [] && ! $this->addDataSources($file, $add)) {
            $this->warn($file, sprintf('could not add data source(s) %s to %s; those fields are not recorded', implode(', ', array_keys($add)), $file));

            return $current;
        }
        $this->stats['added'] += count($add);

        return $order;
    }

    /**
     * Pure part: file order first, then the wanted fields the file lacks (rrdtool tune appends).
     *
     * @param  array<string, string>  $current  data sources in the file
     * @param  array<string, string>  $desired
     * @return array{order: array<string, string>, add: array<string, string>}
     */
    public static function plan(array $current, array $desired): array
    {
        $add = array_diff_key($desired, $current);

        return ['order' => $current + $add, 'add' => $add];
    }

    /**
     * Parse "rrdtool info" output into name => type in file order.
     *
     * @return array<string, string>
     */
    public static function parseInfo(string $output): array
    {
        $types = [];
        if (preg_match_all('/^ds\[([^\]]+)\]\.type = "([A-Z]+)"/m', $output, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $types[$match[1]] = $match[2];
            }
        }

        return $types;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @return array<string, string>|null
     */
    private function dataSources(string $file): ?array
    {
        $output = $this->run(['info', $file]);
        if ($output === null) {
            return null;
        }
        $types = self::parseInfo($output);

        return $types === [] ? null : $types;
    }

    /**
     * @param  array<string, string>  $add
     */
    private function addDataSources(string $file, array $add): bool
    {
        // "rrdtool tune <file> DS:name:type:heartbeat:min:max" appends a data source (rrdtool >= 1.5)
        $options = [];
        foreach ($add as $name => $type) {
            $options[] = sprintf('DS:%s:%s:%d:%s:U', $name, $type, $this->heartbeat, $type === 'GAUGE' ? 'U' : '0');
        }
        if ($this->rrdcached !== '') {
            $this->run(['flushcached', $file]);   // pending updates carry the old value count
        }

        $ok = $this->run(['tune', $file, ...$options]) !== null;
        if ($ok) {
            Log::info(sprintf('  rrd: added data source(s) %s to %s', implode(', ', array_keys($add)), basename($file)));
        }

        return $ok;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): ?string
    {
        $env = [];
        if ($this->rrdcached !== '') {
            // like core: address via the environment, file paths relative to the rrd directory
            $env['RRDCACHED_ADDRESS'] = $this->rrdcached;
            $arguments = array_map(fn ($a) => $this->rrdDir !== '' ? str_replace($this->rrdDir, '', $a) : $a, $arguments);
        }

        $process = new Process([$this->rrdtool, ...$arguments], null, $env, null, 30);
        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::debug('rrd: ' . $e->getMessage());
            $this->stats['failed']++;

            return null;
        }
        if (! $process->isSuccessful()) {
            Log::debug(sprintf('rrd: %s failed: %s', implode(' ', $arguments), trim($process->getErrorOutput() ?: $process->getOutput())));
            $this->stats['failed']++;

            return null;
        }

        return $process->getOutput();
    }

    private function warn(string $file, string $message): void
    {
        if (! isset($this->warned[$file])) {
            $this->warned[$file] = true;
            Log::warning('netconf rrd: ' . $message);
        }
    }
}
