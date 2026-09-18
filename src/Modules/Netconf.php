<?php

namespace LibreNMS\Modules;

use App\Models\Device;
use App\Models\Port;
use App\Models\Sensor;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ConnectivityHelper;
use LibreNMS\Polling\ModuleStatus;
use SafferIt\LibrenmsNetconf\Collect\MetricWriter;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Collect\PortMetricWriter;
use SafferIt\LibrenmsNetconf\Collect\SensorWriter;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;

/**
 * LibreNMS poller/discovery module shipped by the saffer-it/librenms-netconf plugin.
 *
 * Resolved by LibreNMS\Util\Module::fromName('netconf') through this namespace; scheduled
 * because the plugin provider sets poller_modules.netconf / discovery_modules.netconf.
 * Enable per device with `lnms netconf:device <device> --enable` (or globally with the
 * enable_by_default setting); the usual device "Modules" tab toggles apply on top.
 *
 * No SNMP requirement: NETCONF-only devices (snmp_disable) work as long as they are up.
 */
class Netconf implements Module
{
    public function dependencies(): array
    {
        return [];
    }

    public function shouldDiscover(OS $os, ModuleStatus $status, ConnectivityHelper $connectivity): bool
    {
        return $this->should($os, $status, $connectivity, false);
    }

    public function shouldPoll(OS $os, ModuleStatus $status, ConnectivityHelper $connectivity): bool
    {
        return $this->should($os, $status, $connectivity, true);
    }

    public function discover(OS $os): void
    {
        NetconfService::make()->run($os->getDevice(), null, true);
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        NetconfService::make()->run($os->getDevice(), $datastore, false);
    }

    public function dataExists(Device $device): bool
    {
        return (new SensorWriter($device))->count() > 0
            || NetconfMetric::query()->where('device_id', $device->device_id)->exists()
            || NetconfPortMetric::query()->where('device_id', $device->device_id)->exists()
            || NetconfDeviceStatus::query()->where('device_id', $device->device_id)->exists();
    }

    public function cleanup(Device $device): int
    {
        return (new SensorWriter($device))->deleteAll()
            + (new MetricWriter($device))->deleteAll()
            + (new PortMetricWriter($device))->deleteAll()
            + NetconfDeviceStatus::query()->where('device_id', $device->device_id)->delete();
    }

    public function dump(Device $device, string $type): ?array
    {
        $sensors = Sensor::query()->where('device_id', $device->device_id)->where('poller_type', SensorWriter::POLLER_TYPE)->get()
            ->sortBy(fn (Sensor $sensor) => $sensor->sensor_type . '|' . $sensor->sensor_index)->values()
            ->map(fn (Sensor $sensor) => $sensor->makeHidden(['sensor_id', 'device_id', 'lastupdate', 'sensor_prev']));

        $metrics = NetconfMetric::query()->where('device_id', $device->device_id)->get()
            ->sortBy(fn (NetconfMetric $metric) => $metric->definition . '|' . $metric->mapping . '|' . $metric->metric_index)->values()
            ->map(fn (NetconfMetric $metric) => $metric->makeHidden(['id', 'device_id', 'last_seen', 'created_at', 'updated_at']));

        // port rows keyed by ifIndex instead of port_id so the dump is stable across installs
        $ifIndex = Port::query()->where('device_id', $device->device_id)->pluck('ifIndex', 'port_id');
        $ports = NetconfPortMetric::query()->where('device_id', $device->device_id)->get()
            ->each(fn (NetconfPortMetric $port) => $port->setAttribute('ifIndex', $ifIndex->get($port->port_id)))
            ->sortBy(fn (NetconfPortMetric $port) => sprintf('%010d|%s|%s', (int) $port->getAttribute('ifIndex'), $port->definition, $port->mapping))->values()
            ->map(fn (NetconfPortMetric $port) => $port->makeHidden(['id', 'device_id', 'port_id', 'last_seen', 'created_at', 'updated_at']));

        return [
            'sensors' => $sensors,
            'netconf_metrics' => $metrics,
            'netconf_port_metrics' => $ports,
        ];
    }

    private function should(OS $os, ModuleStatus $status, ConnectivityHelper $connectivity, bool $poll): bool
    {
        if (! $status->isEnabled() || ! $connectivity->isAvailable()) {
            return false;
        }

        $reason = NetconfService::make()->skipReason($os->getDevice(), $poll);
        if ($reason !== null) {
            Log::debug('netconf skipped: ' . $reason);

            return false;
        }

        return true;
    }
}
