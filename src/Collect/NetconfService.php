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
     * @return list<Definition>
     */
    public function matchingDefinitions(Device $device): array
    {
        return (new DefinitionMatcher)->matching(DeviceFacts::fromDevice($device), $this->loader->all());
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
            // not a failure: nothing to connect for, so no back-off and no eventlog entry
            $this->saveStatus($status, $status->transport ?? '', $names, null, 'no definitions match this device', microtime(true) - $start, $discovery, failure: false);

            return new RunReport($discovery, $status->transport ?? '', [], [], [], [], microtime(true) - $start);
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

        $summary = $result->summary() + $this->store($device, $definitions, $result, $datastore, $discovery);
        $duration = microtime(true) - $start;
        $error = $result->errors !== [] ? implode('; ', $result->errors) : null;

        $this->saveStatus($status, $transport->name(), $names, $result, $error, $duration, $discovery);
        if ($error !== null) {
            $this->logFailure($device, $status, $error);
        } elseif ($status->consecutive_failures === 0 && $status->wasChanged('consecutive_failures')) {
            Eventlog::log('NETCONF polling recovered', $device, 'netconf', Severity::Ok);
        }

        foreach ($result->warnings() as $warning) {
            Log::debug('netconf warning: ' . $warning);
        }
        Log::info(sprintf(
            'netconf: %d sensors, %d port rows, %d metric rows, %d warnings in %.2fs',
            $summary['sensors'] ?? 0,
            $summary['port_rows'] ?? 0,
            $summary['metric_rows'] ?? 0,
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
        $metrics = new MetricWriter($device);
        $ports = new PortMetricWriter($device);

        // mappings whose command did not deliver data this run keep their existing rows
        /** @var array<string, SensorMapping> $skipped */
        $skipped = [];
        $classes = [];
        $mappingsWithData = [];
        $portMappingsWithData = [];
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
                if ($def !== null && ! in_array($mapping->id, $def->skippedMappings, true)) {
                    $mappingsWithData[] = $definition->name . '/' . $mapping->id;
                }
            }
            foreach ($definition->ports as $mapping) {
                if ($def !== null && ! in_array($mapping->id, $def->skippedMappings, true)) {
                    $portMappingsWithData[] = $definition->name . '/' . $mapping->id;
                }
            }
        }

        $counts = [];
        $values = $result->sensors();

        if ($discovery) {
            $sync = $sensors->sync($values, $skipped, array_keys($classes));
            $counts['sensors_synced'] = $sync['synced'];
            $counts['sensors_kept'] = $sync['kept'];
        } else {
            $recorded = $sensors->record($values, $datastore ?? app('Datastore'));
            $counts['sensors_recorded'] = $recorded['recorded'];
            $counts['sensor_events'] = $recorded['events'];
            if ($recorded['unknown'] !== []) {
                // new rows appeared since discovery: discover them now and record on the next poll
                Log::info(sprintf('  %d new sensor(s), running sensor discovery', count($recorded['unknown'])));
                $counts['sensors_synced'] = $sensors->sync($values, $skipped, array_keys($classes))['synced'];
            }
        }

        $m = $metrics->write($result->metrics(), $discovery ? null : ($datastore ?? app('Datastore')));
        $counts['metrics_rows'] = $m['rows'];
        $counts['metrics_pruned'] = $metrics->prune($result->metrics(), $mappingsWithData);

        $p = $ports->write($result->ports(), $discovery ? null : ($datastore ?? app('Datastore')));
        $counts['ports_matched'] = $p['matched'];
        $counts['ports_unmatched'] = count($p['unmatched']);
        $counts['ports_pruned'] = $ports->prune($portMappingsWithData);
        if ($p['unmatched'] !== []) {
            Log::debug('netconf: unmatched ports: ' . implode(', ', array_slice($p['unmatched'], 0, 20)));
        }

        return $counts;
    }

    /**
     * @param  list<string>  $definitions
     * @param  bool  $failure  whether a non-null $error counts towards the back-off
     */
    private function saveStatus(NetconfDeviceStatus $status, string $transport, array $definitions, ?CollectionResult $result, ?string $error, float $duration, bool $discovery, bool $failure = true): void
    {
        $settings = NetconfSettings::effective();
        $status->transport = $transport;
        $status->definitions = $definitions;
        $status->last_attempt = now();
        $status->last_duration = $duration;
        $status->last_summary = $result?->summary();
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
            $delay = Backoff::delaySeconds(
                $status->consecutive_failures,
                (int) ($settings['backoff_max'] ?? 32),
                (int) LibrenmsConfig::get('rrd.step', 300)
            );
            $status->next_attempt = now()->addSeconds($delay);
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
