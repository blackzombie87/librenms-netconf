<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use SafferIt\LibrenmsNetconf\Collect\Collector;
use SafferIt\LibrenmsNetconf\Console\Concerns\PrintsCollection;
use SafferIt\LibrenmsNetconf\Console\Concerns\ResolvesTarget;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionMatcher;
use SafferIt\LibrenmsNetconf\Definitions\DeviceFacts;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;

/**
 * lnms netconf:preview <device> — dry run: connect, run the matching definitions and print
 * every sensor / port metric / metric that a poll would store. Nothing is written.
 */
class NetconfPreviewCommand extends Command
{
    use PrintsCollection;
    use ResolvesTarget;

    protected $signature = 'netconf:preview
        {device : Hostname, IP, sysName or device_id}
        {--only=* : Only these definition names}
        {--all : Ignore match rules and run every definition}
        {--os= : Pretend the device runs this os (for hosts not in LibreNMS)}
        {--hardware= : Pretend this hardware string}
        {--save= : Also save every reply to this directory as <command-slug>.xml}'
        . self::TARGET_OPTIONS;

    protected $description = 'Dry run of the NETCONF definitions against a device; prints what a poll would store';

    public function handle(DefinitionLoader $loader): int
    {
        try {
            $credentials = $this->resolveCredentials();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $facts = $this->device
            ? DeviceFacts::fromDevice($this->device)
            : new DeviceFacts(os: $this->option('os') ?? 'junos', hardware: $this->option('hardware'), hostname: $credentials->host);
        if ($this->option('os')) {
            $facts = new DeviceFacts($this->option('os'), $this->option('hardware') ?? $facts->hardware, $facts->version, $facts->hostname, $facts->attribs);
        }

        $definitions = array_values($loader->all());
        foreach ($loader->errors() as $error) {
            $this->warn($error);
        }
        if (! $this->option('all')) {
            $definitions = (new DefinitionMatcher)->matching($facts, $definitions);
        }
        if ($only = array_filter((array) $this->option('only'))) {
            $definitions = array_values(array_filter($definitions, fn ($d) => in_array($d->name, $only, true)));
        }
        if ($definitions === []) {
            $this->error(sprintf('No definitions match os=%s hardware=%s', $facts->os ?? '?', $facts->hardware ?? '?'));

            return self::FAILURE;
        }

        $this->line('<info>Target:</info> ' . $this->deviceLabel() . sprintf(' (os=%s hardware=%s version=%s)', $facts->os ?? '?', $facts->hardware ?? '?', $facts->version ?? '?'));
        $this->line('<info>Definitions:</info> ' . implode(', ', array_map(fn ($d) => $d->name, $definitions)));

        $transport = $this->makeTransport($credentials);
        $save = $this->option('save');
        if (is_string($save) && $save !== '') {
            if (! is_dir($save) && ! mkdir($save, 0775, true)) {
                $this->error("Cannot create $save");

                return self::FAILURE;
            }
            $transport = new SavingTransport($transport, $save);
        }

        try {
            $result = (new Collector($transport, new Extractor(['hostname' => $facts->hostname ?? ''])))->collect($definitions);
        } catch (TransportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $transport->close();
        }

        $this->printCollection($result, $this->getOutput()->isVerbose());

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }
}
