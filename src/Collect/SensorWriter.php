<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\Eventlog;
use App\Models\Sensor;
use App\Models\StateTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\Enum\Severity;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\RRD\RrdDefinition;
use SafferIt\LibrenmsNetconf\Definitions\SensorMapping;
use SafferIt\LibrenmsNetconf\Extract\SensorValue;

/**
 * Stores extracted sensor readings as native LibreNMS sensors with poller_type "netconf".
 *
 * Discovery uses the core sensor discovery service (sync per class + poller_type group, so
 * SNMP sensors are never touched). Polling re-implements record_sensor_data(): Datastore
 * write (RRD and any other configured store), threshold / state-change eventlog and the
 * sensor_current / sensor_prev update.
 *
 * sensor_oid holds sysUpTime: core poll_sensor() feeds every non-agent sensor OID to snmpget
 * before ignoring non-snmp poller types, so the OID must be cheap and valid (plan §1.4).
 */
class SensorWriter
{
    public const POLLER_TYPE = 'netconf';

    public const DUMMY_OID = '.1.3.6.1.2.1.1.3.0';

    public function __construct(private readonly Device $device)
    {
    }

    /**
     * Discover/sync sensors. Sensors of mappings whose command did not run ($skipped) are
     * preserved by re-adding their current database rows to the discovery set.
     *
     * @param  list<SensorValue>  $values
     * @param  array<string, SensorMapping>  $skipped  sensor_type => mapping whose data is missing this run
     * @param  list<string>  $classes  every sensor class the matched definitions can produce
     * @return array{synced: int, kept: int}
     */
    public function sync(array $values, array $skipped, array $classes): array
    {
        $discovery = new \App\Discovery\Sensor($this->device);
        $existing = $this->existing();
        // classes of the sensors the device has are synced too, so sensors of a class no
        // matched definition produces any more (or no definition at all) are deleted
        $classes = array_fill_keys(array_merge($classes, $existing->pluck('sensor_class')->all()), true);
        $kept = 0;

        foreach ($values as $value) {
            $model = $this->model($value, $existing);
            $discovery->discover($model);
            $classes[$value->mapping->class] = true;
            if ($value->mapping->isState()) {
                $discovery->withStateTranslations($value->type(), $this->translations($value->mapping));
            }
        }

        foreach ($skipped as $type => $mapping) {
            foreach ($existing->where('sensor_type', $type) as $sensor) {
                $discovery->discover($sensor);
                $classes[$sensor->sensor_class] = true;
                $kept++;
                if ($mapping->isState()) {
                    $discovery->withStateTranslations($type, $this->translations($mapping));
                }
            }
        }

        $synced = 0;
        foreach (array_keys($classes) as $class) {
            $synced += $discovery->sync(sensor_class: $class, poller_type: self::POLLER_TYPE)->count();
        }

        return ['synced' => $synced, 'kept' => $kept];
    }

    /**
     * Record readings for already discovered sensors.
     *
     * @param  list<SensorValue>  $values
     * @return array{recorded: int, unknown: list<SensorValue>, events: int}
     */
    public function record(array $values, DataStorageInterface $datastore): array
    {
        $existing = $this->existing()->keyBy(fn (Sensor $s) => $s->sensor_type . '|' . $s->sensor_index);
        $useDescr = (bool) LibrenmsConfig::getOsSetting($this->device->os, 'sensor_descr');
        $recorded = 0;
        $events = 0;
        $unknown = [];

        foreach ($values as $value) {
            /** @var Sensor|null $sensor */
            $sensor = $existing->get($value->type() . '|' . $value->index);
            if ($sensor === null) {
                $unknown[] = $value;
                continue;
            }

            $current = $value->value;
            $previous = $sensor->sensor_current === null ? null : (float) $sensor->sensor_current;
            $class = $value->mapping->class;
            $unit = \LibreNMS\Enum\Sensor::tryFrom($class)?->unit() ?? '';

            $datastore->put($this->device, 'sensor', [
                'sensor_class' => $class,
                'sensor_type' => $sensor->sensor_type,
                'sensor_descr' => $sensor->sensor_descr,
                'sensor_index' => $sensor->sensor_index,
                'rrd_name' => ['sensor', $class, $sensor->sensor_type, $useDescr ? $sensor->sensor_descr : $sensor->sensor_index],
                'rrd_def' => RrdDefinition::make()->addDataset('sensor', $sensor->rrd_type ?: 'GAUGE'),
            ], ['sensor' => $current]);

            Log::info(sprintf('  %s %s: %s%s', $class, $sensor->sensor_descr, SensorEvents::num($current), $unit !== '' ? " $unit" : ''));

            $classLabel = function_exists('trans') ? (string) trans("sensors.$class.short") : ucfirst($class);
            foreach (SensorEvents::evaluate(
                $classLabel,
                (string) $sensor->sensor_descr,
                $unit,
                $previous,
                $current,
                $sensor->sensor_limit === null ? null : (float) $sensor->sensor_limit,
                $sensor->sensor_limit_low === null ? null : (float) $sensor->sensor_limit_low,
                (bool) ($sensor->sensor_alert ?? true),
                $value->mapping->isState() && $previous !== null ? $this->stateLabel($value->mapping, (int) $previous) : null,
                $value->state?->label,
            ) as $event) {
                Eventlog::log($event['message'], $this->device, $class, $event['severity'] === SensorEvents::WARNING ? Severity::Warning : Severity::Notice, $sensor->sensor_id);
                $events++;
            }

            if ($previous === null || $previous != $current) {
                $sensor->sensor_prev = $previous;
                $sensor->sensor_current = $current;
                $sensor->lastupdate = now();
                $sensor->save();
            }
            $recorded++;
        }

        return ['recorded' => $recorded, 'unknown' => $unknown, 'events' => $events];
    }

    public function count(): int
    {
        return $this->device->sensors()->where('poller_type', self::POLLER_TYPE)->count();
    }

    public function deleteAll(): int
    {
        return $this->device->sensors()->where('poller_type', self::POLLER_TYPE)->delete();
    }

    /**
     * @return Collection<int, Sensor>
     */
    private function existing(): Collection
    {
        return $this->device->sensors()->where('poller_type', self::POLLER_TYPE)->get();
    }

    /**
     * @param  Collection<int, Sensor>  $existing
     */
    private function model(SensorValue $value, Collection $existing): Sensor
    {
        $mapping = $value->mapping;
        $sensor = new Sensor([
            'poller_type' => self::POLLER_TYPE,
            'sensor_class' => $mapping->class,
            'device_id' => $this->device->device_id,
            'sensor_oid' => self::DUMMY_OID,
            'sensor_index' => mb_substr($value->index, 0, 255),
            'sensor_type' => $value->type(),
            'sensor_descr' => mb_substr($value->descr, 0, 255),
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_limit' => $mapping->limit,
            'sensor_limit_warn' => $mapping->warnLimit,
            'sensor_limit_low' => $mapping->limitLow,
            'sensor_limit_low_warn' => $mapping->warnLimitLow,
            'sensor_current' => $value->value,
            'group' => $mapping->group,
            'rrd_type' => 'GAUGE',
        ]);

        // keep limits the user customised in the UI
        $custom = $existing->first(fn (Sensor $s) => $s->sensor_type === $value->type() && $s->sensor_index === $value->index && $s->sensor_custom === 'Yes');
        if ($custom) {
            $sensor->sensor_limit = $custom->sensor_limit;
            $sensor->sensor_limit_warn = $custom->sensor_limit_warn;
            $sensor->sensor_limit_low = $custom->sensor_limit_low;
            $sensor->sensor_limit_low_warn = $custom->sensor_limit_low_warn;
        }

        return $sensor;
    }

    /**
     * @return list<StateTranslation>
     */
    private function translations(SensorMapping $mapping): array
    {
        $severity = [0 => Severity::Ok, 1 => Severity::Warning, 2 => Severity::Error, 3 => Severity::Unknown];
        $out = [];
        foreach ($mapping->states as $state) {
            $out[] = StateTranslation::define($state->label, $state->value, $severity[$state->generic] ?? Severity::Unknown);
        }

        return $out;
    }

    private function stateLabel(SensorMapping $mapping, int $value): ?string
    {
        foreach ($mapping->states as $state) {
            if ($state->value === $value) {
                return $state->label;
            }
        }

        return null;
    }
}
