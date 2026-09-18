<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Models\Device;
use Illuminate\Support\Facades\Gate;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\NetconfSettings;

/**
 * Data for the status page (own route and the /plugin/netconf page hook).
 */
class StatusOverview
{
    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        $user = auth()->guard()->user();
        $statuses = NetconfDeviceStatus::query()->get()->keyBy('device_id');
        $settings = NetconfSettings::effective();
        $defaultOn = (bool) ($settings['enable_by_default'] ?? false);

        $devices = ($user instanceof \App\Models\User ? Device::hasAccess($user) : Device::query())
            ->with('attribs')->get()->sortBy('hostname');

        $rows = [];
        foreach ($devices as $device) {
            $attrib = DeviceSettings::enabledAttrib($device);
            $enabled = $attrib === null ? $defaultOn : $attrib === '1';
            $status = $statuses->get($device->device_id);
            if (! $enabled && $status === null) {
                continue;
            }
            $rows[] = [
                'device' => $device,
                'enabled' => $enabled,
                'inherited' => $attrib === null,
                'status' => $status,
                'overrides' => DeviceSettings::current($device),
            ];
        }

        $devicesForRun = Gate::allows('admin')
            ? array_map(fn ($row) => $row['device'], array_filter($rows, fn ($row) => $row['enabled']))
            : [];

        return [
            'rows' => $rows,
            'default_on' => $defaultOn,
            'settings' => $settings,
            'run_devices' => array_values($devicesForRun),
            'can_admin' => Gate::allows('admin'),
            'module_ready' => class_exists(\LibreNMS\Modules\Netconf::class),
        ];
    }
}
