<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use App\Models\Device;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Fabric\View\DeviceBadge;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Support\DevicePage;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;

/**
 * One panel on the device overview (plan §8 U1): polling status, the device's EVPN fabric
 * membership and what the plugin stores for it — the sensor count with how many of them are
 * critical — with a link to the per-device page. The metric tables and the ESI-LAG table moved
 * to that page, which counts the sensors per class; neither lists the sensors themselves any
 * more, Health does that, and Neighbours lists the ESI links. Only shown for devices that are
 * enabled or have been polled before.
 */
class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(Device $device): bool
    {
        if (! Gate::allows('view', $device)) {
            return false;
        }

        return DevicePage::offered($device);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(string $pluginName, array $settings, Device $device): \Illuminate\Contracts\View\View
    {
        $service = NetconfService::make();
        $status = $service->status($device);

        $sensors = $device->sensors()->where('poller_type', 'netconf')->get();
        $critical = $sensors->filter(function ($sensor) {
            if ($sensor->sensor_class === 'state') {
                $translation = $sensor->currentTranslation();

                return $translation !== null && $translation->state_generic_value >= 2;
            }

            return $sensor->sensor_limit !== null && $sensor->sensor_current > $sensor->sensor_limit;
        });

        return view("$pluginName::device-overview", [
            'device' => $device,
            'href' => DevicePage::url($device->device_id),
            'status' => $status->exists ? $status : null,
            'enabled' => $service->isEnabled($device),
            'enabled_attrib' => DeviceSettings::enabledAttrib($device),
            'skip_reason' => $service->skipReason($device, true),
            'sensor_total' => $sensors->count(),
            'state_total' => $sensors->where('sensor_class', 'state')->count(),
            'critical_total' => $critical->count(),
            'metric_rows' => NetconfMetric::query()->where('device_id', $device->device_id)->count(),
            'fabric_badge' => NetconfService::fabricEnabled() ? DeviceBadge::forDevice($device->device_id) : null,
        ]);
    }
}
