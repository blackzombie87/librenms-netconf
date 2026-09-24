<?php

namespace SafferIt\LibrenmsNetconf\Http\DeviceTab;

use App\Models\Device;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\UI\DeviceTab;
use SafferIt\LibrenmsNetconf\Support\DevicePage;

/**
 * The NETCONF tab of a device (plan §8 U2): core authorises the device, renders the header
 * and the tab bar, and hands data() to resources/lnms-views/device/tabs/netconf.blade.php.
 * The section (status, metrics, esi, edit) is the fourth path segment, as core's own tabs
 * read their sub-page.
 */
final class NetconfTab implements DeviceTab
{
    public function visible(Device $device): bool
    {
        return DevicePage::offerable($device);
    }

    public function slug(): string
    {
        return 'netconf';
    }

    public function icon(): string
    {
        return 'fa-terminal';
    }

    public function name(): string
    {
        return 'NETCONF';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Device $device, Request $request): array
    {
        $section = (string) $request->segment(4, 'status');
        if ($section === '' || str_contains($section, '=')) {
            $section = 'status';   // legacy /device/device=1/tab=netconf/ paths
        }

        return DevicePageData::section($device, $section);
    }
}
