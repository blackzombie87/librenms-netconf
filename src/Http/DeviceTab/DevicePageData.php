<?php

namespace SafferIt\LibrenmsNetconf\Http\DeviceTab;

use App\Models\Device;
use Illuminate\Support\Facades\Gate;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Fabric\EsiPeers;
use SafferIt\LibrenmsNetconf\Fabric\View\DeviceBadge;
use SafferIt\LibrenmsNetconf\Http\Controllers\GraphController;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;
use SafferIt\LibrenmsNetconf\Support\DevicePage;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;

/**
 * View data of the per-device page, one section at a time. Shared by the device tab and the
 * standalone fallback page, so both render the same partials with the same variables.
 */
final class DevicePageData
{
    /**
     * @return array<string, mixed>
     */
    public static function section(Device $device, string $section): array
    {
        $fabric = NetconfService::fabricEnabled();
        $admin = Gate::allows('admin');
        if (! in_array($section, DevicePage::SECTIONS, true) || ($section === 'esi' && ! $fabric)) {
            abort(404);
        }
        if ($section === 'edit' && ! $admin) {
            abort(403);
        }

        $sections = ['status' => ['text' => 'Status', 'icon' => 'fa-info-circle'], 'metrics' => ['text' => 'Metrics', 'icon' => 'fa-table']];
        if ($fabric) {
            $sections['esi'] = ['text' => 'ESI-LAGs', 'icon' => 'fa-link'];
        }
        if ($admin) {
            $sections['edit'] = ['text' => 'Edit', 'icon' => 'fa-gear'];
        }
        foreach ($sections as $name => &$option) {
            $option['link'] = DevicePage::url($device->device_id, $name);
        }
        unset($option);

        $common = [
            'device' => $device,
            'section' => $section,
            'sections' => $sections,
            'can_admin' => $admin,
            'result' => session('netconf_result'),
        ];

        return $common + match ($section) {
            'metrics' => self::metrics($device),
            'esi' => ['esi_rows' => EsiPeers::forDevice($device->device_id)['rows']],
            'edit' => self::edit($device),
            default => self::status($device),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function status(Device $device): array
    {
        $service = NetconfService::make();
        $status = $service->status($device);
        $fabric = NetconfService::fabricEnabled();

        return [
            'enabled' => $service->isEnabled($device),
            'enabled_attrib' => DeviceSettings::enabledAttrib($device),
            'skip_reason' => $service->skipReason($device, true),
            'status' => $status->exists ? $status : null,
            'definitions' => array_map(fn ($d) => ['name' => $d->name, 'description' => $d->description], $service->matchingDefinitions($device)),
            'sensor_summary' => $device->sensors()->where('poller_type', 'netconf')
                ->selectRaw('sensor_class, count(*) as total')->groupBy('sensor_class')->pluck('total', 'sensor_class')->all(),
            'metric_rows' => NetconfMetric::query()->where('device_id', $device->device_id)->count(),
            'port_rows' => NetconfPortMetric::query()->where('device_id', $device->device_id)->count(),
            'fabric_badge' => $fabric ? DeviceBadge::forDevice($device->device_id) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function metrics(Device $device): array
    {
        $metrics = NetconfMetric::query()->where('device_id', $device->device_id)->get()
            ->sortBy(fn (NetconfMetric $m) => $m->definition . '|' . $m->mapping . '|' . $m->metric_index)
            ->groupBy(fn (NetconfMetric $m) => $m->definition . ' / ' . $m->mapping);

        $ports = NetconfPortMetric::query()->where('netconf_port_metrics.device_id', $device->device_id)
            ->join('ports', 'ports.port_id', '=', 'netconf_port_metrics.port_id')
            ->orderBy('ports.ifIndex')
            ->get(['netconf_port_metrics.*', 'ports.ifName', 'ports.ifIndex', 'ports.ifAlias']);

        return [
            'metrics' => $metrics,
            'ports' => $ports,
            'period' => self::period(),
            'max_series' => GraphController::MAX_SERIES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function edit(Device $device): array
    {
        return [
            'enabled_attrib' => DeviceSettings::enabledAttrib($device),
            'overrides' => DeviceSettings::current($device),
            'effective' => app(DeviceCredentials::class)->forDevice($device)->describe(),
        ];
    }

    /**
     * The graph period from the query string, an rrdtool-style relative start.
     */
    public static function period(): string
    {
        $period = (string) request()->query('period', '-1d');

        return preg_match('/^-\d+[hdwmy]{1,2}$/', $period) ? $period : '-1d';
    }
}
