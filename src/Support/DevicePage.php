<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Models\Device;
use Illuminate\Support\Facades\Gate;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\TabRegistration;

/**
 * Where the per-device NETCONF pages live (plan §8): the NETCONF device tab when the core
 * seam let the plugin register it, the standalone plugin pages otherwise. One place for
 * every link into them.
 */
final class DevicePage
{
    public const SECTIONS = ['status', 'metrics', 'esi', 'edit'];

    public static function url(int $deviceId, string $section = 'status'): string
    {
        if (TabRegistration::active()) {
            return $section === 'status'
                ? route('device', [$deviceId, 'netconf'])
                : route('device', [$deviceId, 'netconf', $section]);
        }

        return $section === 'status'
            ? route('netconf.device', $deviceId)
            : route('netconf.device.section', [$deviceId, $section]);
    }

    /**
     * Whether the device has NETCONF data worth showing: enabled, or polled before. Drives the
     * overview panel — a device the plugin knows nothing about has nothing to put there.
     */
    public static function offered(Device $device): bool
    {
        if (! PluginRoutes::availableOrWarn()) {
            return false;   // the pages this would link to are not registered (plan §9.1)
        }

        $service = NetconfService::make();

        return $service->isEnabled($device) || $service->status($device)->exists;
    }

    /**
     * Whether the device tab is offered: a device with data, plus the one an admin still has to
     * enable — a device the plugin could collect from, i.e. one a shipped definition matches
     * (plan §9.2 W1). Without this an admin has no way to enable a single device from the web UI:
     * the tab and the overview panel are both hidden until the device is enabled, and the status
     * page's bulk form only selects by device group or os.
     */
    public static function offerable(Device $device): bool
    {
        if (self::offered($device)) {
            return true;
        }

        return PluginRoutes::available()
            && Gate::allows('admin')
            && NetconfService::make()->matchingDefinitions($device) !== [];
    }
}
