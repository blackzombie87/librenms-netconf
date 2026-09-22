<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\Eventlog;
use Illuminate\Support\Facades\Log;
use LibreNMS\Enum\Severity;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use SafferIt\LibrenmsNetconf\Definitions\Definition;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionMatcher;
use SafferIt\LibrenmsNetconf\Definitions\DeviceFacts;
use SafferIt\LibrenmsNetconf\Definitions\SensorMapping;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueSensor;
use SafferIt\LibrenmsNetconf\Fabric\FabricResolver;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\NetconfSettings;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

/**
 * Runs the plugin for one LibreNMS device: enable/credential checks, definition matching,
 * one transport session, the writers, and the per-device status row with back-off.
 * Shared by the poller module and the console commands.
 */
class NetconfService
{
    public const ATTRIB_ENABLED = 'netconf_enabled';

    public function __construct(
        private readonly DefinitionLoader $loader,
        private readonly DeviceCredentials $credentials,
        private readonly TransportFactory $transports,
    ) {
    }

    /** Device attribute netconf_enabled wins; otherwise the global enable_by_default setting. */
    public function isEnabled(Device $device): bool
    {
        $attrib = $device->getAttrib(self::ATTRIB_ENABLED);
        if ($attrib !== null && $attrib !== '') {
            return filter_var($attrib, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) (NetconfSettings::effective()['enable_by_default'] ?? false);
    }

    public function status(Device $device): NetconfDeviceStatus
    {
        return NetconfDeviceStatus::query()->firstOrNew(['device_id' => $device->device_id]);
    }

    /**
     * Definitions matching the device. Definitions with `tables:` mappings belong to the EVPN
     * fabric view and only run while that setting is enabled (they add commands per leaf).
     *
     * @return list<Definition>
     */
    public function matchingDefinitions(Device $device): array
    {
        $matching = (new DefinitionMatcher)->matching(DeviceFacts::fromDevice($device), $this->loader->all());
        if (! self::fabricEnabled()) {
            $matching = array_values(array_filter($matching, fn (Definition $d) => $d->tables === []));
        }

        return $matching;
    }

    public static function fabricEnabled(): bool
    {
        return (bool) (NetconfSettings::effective()['evpn_fabric'] ?? false);
    }

    /** ESI peers as core `links` rows (Neighbours tab, map); only acted on while the fabric view is enabled. */
    public static function esiLinksEnabled(): bool
    {
        return (bool) (NetconfSettings::effective()['evpn_links'] ?? false);
    }

    /**
     * Null when the device should be processed, otherwise the reason to skip it (cheap, no SSH).
     */
    public function skipReason(Device $device, bool $poll): ?string
    {
        if (! $this->isEnabled($device)) {
            return 'netconf not enabled for this device (attribute netconf_enabled or setting enable_by_default)';
        }

        $credentials = $this->credentials->forDevice($device);
        if ($credentials->username === '' || $credentials->usableAuthMethods() === []) {
            return 'no usable credentials (username, password or key file)';
        }

        if ($poll) {
            $status = $this->status($device);
            if ($status->inBackoff()) {
                return sprintf('in back-off after %d failures until %s', $status->consecutive_failures, $status->next_attempt?->toDateTimeString());
            }
            // definitions are matched again on every run (cheap, no SSH): a device that matched
            // nothing at discovery picks up new YAML or a changed os/hardware without a rediscover
        }

        return null;
    }

    /**
     * One full run. Discovery runs every command and syncs sensors; polling honours `every:`
     * and records values.
     */
    public function run(Device $device, ?DataStorageInterface $datastore, bool $discovery): RunReport
    {
        $start = microtime(true);
        $status = $this->status($device);
        $definitions = $this->matchingDefinitions($device);
        $names = array_map(fn ($d) => $d->name, $definitions);
        foreach ($this->loader->errors() as $error) {
            Log::warning('netconf definition error: ' . $error);
        }

        Log::info(sprintf('netconf: %d matching definition(s): %s', count($definitions), implode(', ', $names) ?: '-'));
        if ($definitions === []) {
            // not a failure: nothing to connect for, so no back-off and no eventlog entry. The
            // writers still run with an empty result so what earlier definitions stored (an OS
            // change, every definition disabled) is pruned instead of lingering as live data
            $result = new CollectionResult;
            $transportName = $status->transport ?? '';
            $this->saveStatus($status, $transportName, $names, $result->summary(), 'no definitions match this device', microtime(true) - $start, $discovery, failure: false);
            try {
                $summary = array_merge($result->summary(), $this->store($device, [], $result, $datastore, $discovery));
            } catch (\Throwable $e) {
                return $this->storageFailed($device, $status, $e, $transportName, $names, $result, $discovery, $start);
            }
            $duration = microtime(true) - $start;
            $this->finishStatus($status, $summary, $duration);
            Log::info(sprintf('netconf: nothing to collect; %d sensor(s) synced, %d metric, %d port and %d table row(s) pruned in %.2fs', $summary['sensors_synced'] ?? 0, $summary['metrics_pruned'] ?? 0, $summary['ports_pruned'] ?? 0, $summary['tables_pruned'] ?? 0, $duration));

            return new RunReport($discovery, $transportName, [], $summary, [], [], $duration, $result);
        }

        $credentials = $this->credentials->forDevice($device);
        $transport = $this->transports->make($credentials);
        $settings = NetconfSettings::effective();
        $pollNumber = $discovery ? 0 : $status->poll_count + 1;
        $budget = (float) ($settings['poll_budget'] ?? 20);

        try {
            $transport->connect();
            $result = (new Collector($transport, new Extractor(['hostname' => $device->hostname, 'sysname' => $device->sysName ?? ''])))
                ->collect($definitions, $pollNumber, $budget);
        } catch (TransportException $e) {
            $transport->close();
            $duration = microtime(true) - $start;
            $this->saveStatus($status, $transport->name(), $names, null, $e->getMessage(), $duration, $discovery);
            $this->logFailure($device, $status, $e->getMessage());
            Log::info('netconf: ' . $e->getMessage());

            return new RunReport($discovery, $transport->name(), $names, [], [$e->getMessage()], [], $duration);
        }
        $transport->close();

        foreach ($result->commands as $run) {
            Log::info(sprintf('  %-55s %s%s', $run->label, $run->status, $run->message ? ' (' . $run->message . ')' : ''));
        }

        // the outcome first: the fabric checks read the status row during the resolve
        // (member-not-polling), so a poll that succeeded must already say so (F5 3). The
        // summary counts follow once the writers are through.
        $error = $result->errors !== [] ? implode('; ', $result->errors) : null;
        $this->saveStatus($status, $transport->name(), $names, $result->summary(), $error, microtime(true) - $start, $discovery);
        $recovered = $error === null && $status->consecutive_failures === 0 && $status->wasChanged('consecutive_failures');

        try {
            $summary = array_merge($result->summary(), $this->store($device, $definitions, $result, $datastore, $discovery));
        } catch (\Throwable $e) {
            return $this->storageFailed($device, $status, $e, $transport->name(), $names, $result, $discovery, $start);
        }
        $duration = microtime(true) - $start;
        $this->finishStatus($status, $summary, $duration);

        if ($error !== null) {
            $this->logFailure($device, $status, $error);
        } elseif ($recovered) {
            Eventlog::log('NETCONF polling recovered', $device, 'netconf', Severity::Ok);
        }

        foreach ($result->warnings() as $warning) {
            Log::debug('netconf warning: ' . $warning);
        }
        Log::info(sprintf(
            'netconf: %d sensors, %d port rows, %d metric rows, %d table rows, %d warnings in %.2fs',
            $summary['sensors'] ?? 0,
            $summary['port_rows'] ?? 0,
            $summary['metric_rows'] ?? 0,
            $summary['table_rows'] ?? 0,
            $summary['warnings'] ?? 0,
            $duration
        ));

        return new RunReport($discovery, $transport->name(), $names, $summary, $result->errors, $result->warnings(), $duration, $result);
    }

    /**
     * @param  list<Definition>  $definitions
     * @return array<string, int>
     */
    private function store(Device $device, array $definitions, CollectionResult $result, ?DataStorageInterface $datastore, bool $discovery): array
    {
        $sensors = new SensorWriter($device);
        $layout = RrdLayout::make();
        $metrics = new MetricWriter($device, $layout);
        $ports = new PortMetricWriter($device, $layout);
        $tables = new TableWriter($device);

        // mappings whose command did not deliver data this run keep their existing rows;
        // mappings no matched definition has any more lose theirs
        /** @var array<string, SensorMapping> $skipped */
        $skipped = [];
        $classes = [];
        $mappingsWithData = [];
        $portMappingsWithData = [];
        $knownMappings = [];
        $knownPortMappings = [];
        /** @var array<string, bool> $tableComplete  logical table => every mapping delivered data */
        $tableComplete = [];
        foreach ($definitions as $definition) {
            $def = $result->definitions[$definition->name] ?? null;
            foreach ($definition->sensors as $mapping) {
                $type = 'netconf-' . $definition->name . '-' . $mapping->id;
                if ($def === null || in_array($mapping->id, $def->skippedMappings, true)) {
                    $skipped[$type] = $mapping;
                } else {
                    $classes[$mapping->class] = true;
                }
            }
            foreach ($definition->metrics as $mapping) {
                $knownMappings[] = $definition->name . '/' . $mapping->id;
                if ($def !== null && ! in_array($mapping->id, $def->skippedMappings, true)) {
                    $mappingsWithData[] = $definition->name . '/' . $mapping->id;
                }
            }
            foreach ($definition->ports as $mapping) {
                $knownPortMappings[] = $definition->name . '/' . $mapping->id;
                if ($def !== null && ! in_array($mapping->id, $def->skippedMappings, true)) {
                    $portMappingsWithData[] = $definition->name . '/' . $mapping->id;
                }
            }
            foreach ($definition->tables as $mapping) {
                $hasData = $def !== null && ! in_array($mapping->id, $def->skippedMappings, true);
                $tableComplete[$mapping->table] = ($tableComplete[$mapping->table] ?? true) && $hasData;
            }
        }

        $counts = [];
        $live = $discovery ? null : ($datastore ?? app('Datastore'));
        $m = $metrics->write($result->metrics(), $live);
        $counts['metrics_rows'] = $m['rows'];
        $counts['metrics_pruned'] = $metrics->prune($result->metrics(), $mappingsWithData) + $metrics->deleteOrphans($knownMappings);

        $p = $ports->write($result->ports(), $live);
        if (($summary = $layout->summary()) !== null) {
            Log::info('  ' . $summary);
        }
        $counts['ports_matched'] = $p['matched'];
        $counts['ports_unmatched'] = count($p['unmatched']);
        $counts['ports_pruned'] = $ports->prune($portMappingsWithData) + $ports->deleteOrphans($knownPortMappings);
        if ($p['unmatched'] !== []) {
            Log::debug('netconf: unmatched ports: ' . implode(', ', array_slice($p['unmatched'], 0, 20)));
        }

        // YAML sensors before the fabric resolve: the checks read the dup-mac counts and the
        // l3-contexts sensor from sensors.sensor_current, so the rows must hold this run's
        // values when the resolve runs (F5 3). The fabric checks' per-leaf issues sensor
        // rides along with its reading as of the previous resolve, so its row is kept (or
        // created / dropped with the membership as it was); it is brought up to date below.
        $fabricOn = self::fabricEnabled();
        $values = $result->sensors();
        $before = IssueSensor::reading($device, $fabricOn, $discovery);
        $syncing = $discovery || $definitions === [];
        $unknown = 0;
        if ($syncing) {
            $sync = $sensors->sync(self::withIssues($values, $before), $skipped, array_keys($classes));
            $counts['sensors_synced'] = $sync['synced'];
            $counts['sensors_kept'] = $sync['kept'];
        } else {
            $recorded = $sensors->record($values, $live ?? app('Datastore'));
            $counts['sensors_recorded'] = $recorded['recorded'];
            $counts['sensor_events'] = $recorded['events'];
            $unknown = count($recorded['unknown']);
        }

        if ($fabricOn) {
            $rows = $result->tables();
            $written = 0;
            $pruned = 0;
            if ($tableComplete !== []) {
                $written = $tables->write($rows)['rows'];
                $pruned = $tables->prune($rows, array_keys(array_filter($tableComplete)));
            }
            // tables the device used to fill but no matched definition covers any more (opt-in
            // attribute cleared, definition edited or gone): their rows go too. While the fabric
            // setting is off nothing is collected and nothing is deleted.
            $orphans = array_values(array_diff($tables->owned(), array_keys($tableComplete)));
            if ($orphans !== []) {
                $pruned += $tables->deleteTables($orphans);
                Log::info('  table rows deleted, no matching definition fills them any more: ' . implode(', ', $orphans));
                if ($tableComplete === []) {
                    // the device left the fabric tables altogether: its memberships and VTEP
                    // links go the way they do when the device is deleted
                    $pruned += FabricResolver::make()->forget($device->device_id);
                }
            }
            $counts['table_rows'] = $written;
            $counts['tables_pruned'] = $pruned;

            // cross-device aggregation: cheap (hundreds of nodes), refreshed by every leaf poll
            // that changed a row; a device whose fabric commands were all skipped changes nothing
            if ($written === 0 && $pruned === 0) {
                Log::debug('  fabric resolver skipped: no table rows written or pruned for this device');
            } else {
                $fabric = FabricResolver::make()->run();
                if ($fabric === null) {
                    Log::debug('  fabric resolver skipped: another poller holds the lock');
                } else {
                    $counts['fabric_nodes'] = $fabric['nodes'];
                    $counts['fabrics'] = $fabric['fabrics'];
                    Log::info(sprintf('  fabric: %d nodes on %d devices (%d unknown), %d fabric(s), %d underlay links, %d ESI peer links', $fabric['nodes'], $fabric['devices'], $fabric['unknown'], $fabric['fabrics'], $fabric['links'], $fabric['esi_links']));
                }
            }
        }

        // the issues sensor after the resolve: the count as of this run. On poll it reads 0
        // rather than vanishing while the fabric view is off or the device has left the
        // fabric (IssueSensor::reading()); on discovery a device without membership gets none
        $after = IssueSensor::reading($device, $fabricOn, $discovery);
        // the status page counts what was stored, not what the YAML produced
        $counts['sensors'] = count($values) + ($after === null ? 0 : 1);

        if ($syncing) {
            if (($before === null) !== ($after === null)) {
                // the membership changed in this very resolve (first discovery of a leaf, or
                // it left the fabric): the row follows, one more sync
                $sync = $sensors->sync(self::withIssues($values, $after), $skipped, array_keys($classes));
                $counts['sensors_synced'] = $sync['synced'];
            } elseif ($after !== null && $before !== null && $after->value !== $before->value) {
                $sensors->setCurrent($after);
            }
        } else {
            if ($unknown === 0 && $after !== null) {
                $recorded = $sensors->record([$after], $live ?? app('Datastore'));
                $counts['sensors_recorded'] += $recorded['recorded'];
                $counts['sensor_events'] += $recorded['events'];
                $unknown = count($recorded['unknown']);
            }
            if ($unknown > 0) {
                // new rows appeared since discovery: discover them now and record on the next poll
                Log::info(sprintf('  %d new sensor(s), running sensor discovery', $unknown));
                $counts['sensors_synced'] = $sensors->sync(self::withIssues($values, $after), $skipped, array_keys($classes))['synced'];
            }
        }

        return $counts;
    }

    /**
     * @param  list<\SafferIt\LibrenmsNetconf\Extract\SensorValue>  $values
     * @return list<\SafferIt\LibrenmsNetconf\Extract\SensorValue>
     */
    private static function withIssues(array $values, ?\SafferIt\LibrenmsNetconf\Extract\SensorValue $issues): array
    {
        if ($issues !== null) {
            $values[] = $issues;
        }

        return $values;
    }

    /**
     * The writers threw (a schema limit, a lost database connection): the run is recorded as
     * a failure with back-off and an eventlog entry instead of leaving the status row with
     * the pre-storage outcome and the data half written (F5 4).
     *
     * @param  list<string>  $names
     */
    private function storageFailed(Device $device, NetconfDeviceStatus $status, \Throwable $e, string $transport, array $names, CollectionResult $result, bool $discovery, float $start): RunReport
    {
        $error = 'storing the results failed: ' . $e->getMessage();
        $duration = microtime(true) - $start;
        if ($status->next_attempt === null) {
            // the collection itself succeeded, so saveStatus() reset the counter; this run fails after all
            $status->consecutive_failures = $status->consecutive_failures + 1;
            $status->next_attempt = now()->addSeconds($this->backoff($status->consecutive_failures));
        }
        $status->last_error = mb_substr($error, 0, 2000);
        $status->last_duration = $duration;
        $status->save();
        Eventlog::log('NETCONF ' . $error, $device, 'netconf', Severity::Error);
        Log::error(sprintf('netconf %s: %s (%s)', $device->hostname, $error, $e::class));

        return new RunReport($discovery, $transport, $names, $result->summary(), array_merge($result->errors, [$error]), $result->warnings(), $duration, $result);
    }

    /**
     * Second half of the status row, after the writers: what the run stored.
     *
     * @param  array<string, int>  $summary
     */
    private function finishStatus(NetconfDeviceStatus $status, array $summary, float $duration): void
    {
        $status->last_summary = $summary;
        $status->last_duration = $duration;
        $status->save();
    }

    private function backoff(int $failures): int
    {
        return Backoff::delaySeconds(
            $failures,
            (int) (NetconfSettings::effective()['backoff_max'] ?? 32),
            (int) LibrenmsConfig::get('rrd.step', 300)
        );
    }

    /**
     * The run's outcome: transport, definitions, attempt, failure counter and back-off; the
     * summary holds the collection counts until finishStatus() adds what was stored.
     *
     * @param  list<string>  $definitions
     * @param  array<string, int>|null  $summary  what the run collected, null on a failed connect
     * @param  bool  $failure  whether a non-null $error counts towards the back-off
     */
    private function saveStatus(NetconfDeviceStatus $status, string $transport, array $definitions, ?array $summary, ?string $error, float $duration, bool $discovery, bool $failure = true): void
    {
        $status->transport = $transport;
        $status->definitions = $definitions;
        $status->last_attempt = now();
        $status->last_duration = $duration;
        $status->last_summary = $summary;
        if (! $discovery) {
            $status->poll_count = $status->poll_count + 1;
        }

        if ($error === null || ! $failure) {
            $status->consecutive_failures = 0;
            $status->next_attempt = null;
            $status->last_error = $error === null ? null : mb_substr($error, 0, 2000);
            $status->last_ok = now();
        } else {
            $status->consecutive_failures = $status->consecutive_failures + 1;
            $status->last_error = mb_substr($error, 0, 2000);
            $status->next_attempt = now()->addSeconds($this->backoff($status->consecutive_failures));
        }

        $status->save();
    }

    private function logFailure(Device $device, NetconfDeviceStatus $status, string $error): void
    {
        if ($status->consecutive_failures === 1) {
            Eventlog::log('NETCONF polling failed: ' . $error, $device, 'netconf', Severity::Error);
        } else {
            Log::warning(sprintf('netconf %s: failure %d, next attempt %s: %s', $device->hostname, $status->consecutive_failures, $status->next_attempt?->toDateTimeString(), $error));
        }
    }

    public static function make(): self
    {
        return app(self::class);
    }
}
