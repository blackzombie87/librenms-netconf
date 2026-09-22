<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Support\RunSummary;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * The plugin's list page after plan §8 U4: one canonical URL, a readable last-result line,
 * definitions as a count with a fold-out.
 */
final class StatusPageTest extends LibrenmsTestCase
{
    public function testThePluginRootRedirectsToTheStatusList(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $this->get('/plugin/netconf')->assertRedirect('/plugin/netconf/status');
        $this->get('/plugin/netconf/status')->assertOk();
    }

    public function testTheListShowsTheRunAsOneLineAndDefinitionsAsACount(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($device, ['enabled' => '1']);
        NetconfDeviceStatus::query()->create([
            'device_id' => $device->device_id,
            'transport' => 'cli',
            'definitions' => ['junos-system', 'junos-alarms', 'junos-ntp'],
            'poll_count' => 1,
            'consecutive_failures' => 0,
            'last_ok' => now(),
            'last_attempt' => now(),
            'last_duration' => 4.26,
            'last_summary' => ['definitions' => 3, 'commands_ok' => 5, 'commands_skipped' => 2, 'commands_failed' => 1, 'sensors' => 9, 'warnings' => 0],
        ]);

        $html = $this->get('/plugin/netconf/status')->assertOk()->getContent();
        $row = substr($html, strpos($html, (string) $device->hostname));
        $row = substr($row, 0, strpos($row, '</tr>'));

        $this->assertStringContainsString('5 ok / 2 skipped / 1 failed · 9 sensors · 4.3 s', $row);
        $this->assertStringContainsString('commands failed in the last run', $row);
        $this->assertStringContainsString('<summary>3</summary>', $row);
        $this->assertStringContainsString('<code>junos-ntp</code>', $row);
        $this->assertStringNotContainsString('label-info', $row);
        $this->assertStringContainsString("/device/$device->device_id/netconf\">$device->hostname</a>", $html);   // the name links to the tab
    }

    public function testTheRunSummaryLine(): void
    {
        $this->assertSame('20 ok / 7 skipped / 0 failed · 141 sensors · 8.0 s', RunSummary::line(['commands_ok' => 20, 'commands_skipped' => 7, 'commands_failed' => 0, 'sensors' => 141, 'warnings' => 0], 8.0037));
        $this->assertSame('1 ok / 0 skipped / 2 failed · 3 warnings', RunSummary::line(['commands_ok' => 1, 'commands_failed' => 2, 'warnings' => 3]));
        $this->assertSame('', RunSummary::line([]));
    }
}
