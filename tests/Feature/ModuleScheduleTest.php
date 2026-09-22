<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Plugins\PluginManager;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Modules\Netconf;
use LibreNMS\OS;
use LibreNMS\Polling\ConnectivityHelper;
use LibreNMS\Polling\ModuleStatus;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * When the module runs (F5 7, the shouldPoll()/shouldDiscover() matrix): an enabled,
 * reachable device with credentials is polled and discovered; a device that is down, a
 * disabled module, a disabled plugin or missing credentials stop both; the back-off stops
 * polling only; SNMP is not required.
 */
final class ModuleScheduleTest extends LibrenmsTestCase
{
    public function testTheMatrix(): void
    {
        $module = new Netconf;
        $should = fn (Device $device, ?ModuleStatus $status = null) => [
            $module->shouldPoll($this->os($device), $status ?? new ModuleStatus(true), new ConnectivityHelper($device)),
            $module->shouldDiscover($this->os($device), $status ?? new ModuleStatus(true), new ConnectivityHelper($device)),
        ];

        // enabled, up, with credentials: both
        $this->assertSame([true, true], $should($this->device()));

        // SNMP disabled on the device changes nothing: the module talks NETCONF only
        $this->assertSame([true, true], $should($this->device(['snmp_disable' => 1])));

        // the device is down
        $this->assertSame([false, false], $should($this->device(['status' => 0])));

        // the module is disabled (globally, per os, or on the device's Modules tab)
        $this->assertSame([false, false], $should($this->device(), new ModuleStatus(false)));
        $this->assertSame([false, false], $should($this->device(), new ModuleStatus(true, null, false)));

        // not enabled for the device: the attribute is absent and enable_by_default is off;
        // the global default switches it on, the attribute '0' wins over the default
        $this->assertSame([false, false], $should($this->device(enabled: null)));
        config(['netconf.enable_by_default' => true]);
        $this->assertSame([true, true], $should($this->device(enabled: null)));
        $this->assertSame([false, false], $should($this->device(enabled: '0')));
        config(['netconf.enable_by_default' => false]);

        // no usable credentials: no username anywhere, or a username without password / key
        $this->assertSame([false, false], $should($this->device(username: null)));
        $this->assertSame([false, false], $should($this->device(password: null)));

        // in back-off after failures: no poll until next_attempt, discovery still runs
        $backedOff = $this->device();
        NetconfDeviceStatus::query()->create(['device_id' => $backedOff->device_id, 'consecutive_failures' => 3, 'next_attempt' => now()->addMinutes(10)]);
        $this->assertSame([false, true], $should($backedOff));
        NetconfDeviceStatus::query()->where('device_id', $backedOff->device_id)->update(['next_attempt' => now()->subMinute()]);
        $this->assertSame([true, true], $should($backedOff));

        // the plugin is disabled: the module keys stay in the config table, the module must not run
        $this->app->instance(PluginManagerInterface::class, new class extends PluginManager
        {
            public function pluginEnabled(string $pluginName): bool
            {
                return false;
            }
        });
        $this->assertSame([false, false], $should($this->device()));
    }

    private function os(Device $device): OS
    {
        $array = ['device_id' => $device->device_id, 'os' => $device->os, 'hostname' => $device->hostname];

        return OS::make($array);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function device(array $attributes = [], ?string $enabled = '1', ?string $username = 'librenms', ?string $password = 'secret'): Device
    {
        $device = Device::factory()->create($attributes + ['os' => 'junos', 'hostname' => 'leaf-schedule-' . uniqid() . '.example.net', 'status' => 1]);
        if ($enabled !== null) {
            $device->setAttrib(NetconfService::ATTRIB_ENABLED, $enabled);
        }
        if ($username !== null) {
            $device->setAttrib('netconf_username', $username);
        }
        if ($password !== null) {
            $device->setAttrib('netconf_password', $password);
        }

        return $device;
    }
}
