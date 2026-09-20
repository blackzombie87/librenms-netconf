<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use App\Models\Device;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Fabric\EsiPeers;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;

/**
 * Panel on the device overview: NETCONF status, matched definitions, sensor summary and
 * the first rows of every custom metric mapping, plus an "EVPN multihoming" panel with the
 * device's ESI-LAGs and their peers when the fabric view has data for it. Only shown for
 * devices that are enabled or have been polled before.
 */
class DeviceOverview implements DeviceOverviewHook
{
    public const ROWS_PER_MAPPING = 8;

    public const ESI_ROWS = 10;

    public function authorize(Device $device): bool
    {
        if (! Gate::allows('view', $device)) {
            return false;
        }

        $service = NetconfService::make();

        return $service->isEnabled($device) || $service->status($device)->exists;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(string $pluginName, array $settings, Device $device): \Illuminate\Contracts\View\View
    {
        $service = NetconfService::make();
        $status = $service->status($device);

        $sensors = $device->sensors()->where('poller_type', 'netconf')->get();
        $stateSensors = $sensors->where('sensor_class', 'state');
        $critical = $sensors->filter(function ($sensor) {
            if ($sensor->sensor_class === 'state') {
                $translation = $sensor->currentTranslation();

                return $translation !== null && $translation->state_generic_value >= 2;
            }

            return $sensor->sensor_limit !== null && $sensor->sensor_current > $sensor->sensor_limit;
        });

        $metrics = NetconfMetric::query()->where('device_id', $device->device_id)->get()
            ->sortBy(fn (NetconfMetric $m) => $m->definition . '|' . $m->mapping . '|' . $m->metric_index)
            ->groupBy(fn (NetconfMetric $m) => $m->definition . ' / ' . $m->mapping);

        return view("$pluginName::device-overview", [
            'device' => $device,
            'status' => $status->exists ? $status : null,
            'enabled' => $service->isEnabled($device),
            'enabled_attrib' => DeviceSettings::enabledAttrib($device),
            'skip_reason' => $service->skipReason($device, true),
            'sensor_total' => $sensors->count(),
            'state_total' => $stateSensors->count(),
            'critical' => $critical->values(),
            'metrics' => $metrics,
            'rows_per_mapping' => self::ROWS_PER_MAPPING,
            'esi_rows' => NetconfService::fabricEnabled() ? EsiPeers::forDevice($device->device_id)['rows'] : [],
            'esi_limit' => self::ESI_ROWS,
        ]);
    }
}
