<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Models\Device;
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
     * Whether the device has a NETCONF page worth offering: enabled, or polled before.
     */
    public static function offered(Device $device): bool
    {
        $service = NetconfService::make();

        return $service->isEnabled($device) || $service->status($device)->exists;
    }
}
