<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use SafferIt\LibrenmsNetconf\Hooks\DeviceOverview;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * The per-device UI after the re-home (plan §8): the overview panel is one summary without
 * value tables (U1).
 */
final class DeviceUiTest extends LibrenmsTestCase
{
    public function testTheOverviewPanelIsOneSummaryWithoutTables(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = $this->polledDevice();

        $hook = app(DeviceOverview::class);
        $this->assertTrue($hook->authorize($device));
        $html = $hook->handle('netconf', [], $device)->render();

        $this->assertStringContainsString('<strong>NETCONF</strong>', $html);
        $this->assertStringContainsString('Open NETCONF page', $html);
        $this->assertStringContainsString('2 metric rows', $html);
        $this->assertStringContainsString('2 definitions', $html);
        $this->assertStringContainsString('netconf', $html);
        // the status rows are the only table: no metric mapping tables, no ESI-LAG table
        $this->assertSame(1, substr_count($html, '<table'), $html);
        $this->assertStringNotContainsString('<thead>', $html);
        $this->assertStringNotContainsString('junos-system / mapping', $html);
        $this->assertStringNotContainsString('label-info', $html);   // definitions are a count, not pills
    }

    public function testTheOverviewPanelIsOnlyOfferedForEnabledOrPolledDevices(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $hook = app(DeviceOverview::class);

        $untouched = Device::factory()->create(['os' => 'junos']);
        $this->assertFalse($hook->authorize($untouched));

        $enabled = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($enabled, ['enabled' => '1']);
        $this->assertTrue($hook->authorize($enabled));

        // a viewer without access to the device sees nothing, whatever the device's state
        $this->actingAs(User::factory()->create(['enabled' => 1]));
        $this->assertFalse($hook->authorize($enabled));
    }

    private function polledDevice(): Device
    {
        $device = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($device, ['enabled' => '1']);
        NetconfDeviceStatus::query()->create([
            'device_id' => $device->device_id,
            'transport' => 'netconf',
            'definitions' => ['junos-system', 'junos-interfaces'],
            'poll_count' => 3,
            'consecutive_failures' => 0,
            'last_ok' => now(),
            'last_attempt' => now(),
            'last_duration' => 9.3,
            'last_summary' => ['definitions' => 2, 'commands_ok' => 4, 'commands_skipped' => 1, 'commands_failed' => 0, 'sensors' => 5, 'metric_rows' => 2, 'port_rows' => 0],
        ]);
        foreach (['re0', 're1'] as $index) {
            NetconfMetric::query()->create([
                'device_id' => $device->device_id,
                'definition' => 'junos-system',
                'mapping' => 'mapping',
                'metric_index' => $index,
                'descr' => "Routing engine $index",
                'values' => ['cpu' => 12.0, 'memory' => 40.0],
                'types' => ['cpu' => 'GAUGE', 'memory' => 'GAUGE'],
                'last_seen' => now(),
            ]);
        }

        return $device;
    }
}
