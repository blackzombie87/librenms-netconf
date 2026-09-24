<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\NetconfTab;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * Enabling one device from the web UI (plan §9.2). Before this the NETCONF tab and the overview
 * panel were both hidden until the device was enabled, and the status page's bulk form only
 * selects by device group or os — so a single device could only be enabled from the CLI.
 */
final class DeviceOnboardingTest extends LibrenmsTestCase
{
    public function testTheTabIsOfferedToAnAdminOnADeviceADefinitionMatches(): void
    {
        $candidate = Device::factory()->create(['os' => 'junos']);
        $other = Device::factory()->create(['os' => 'linux']);
        $tab = new NetconfTab;

        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $this->assertNotSame([], NetconfService::make()->matchingDefinitions($candidate));
        $this->assertTrue($tab->visible($candidate), 'a junos device an admin could enable');
        $this->assertFalse($tab->visible($other), 'no definition matches, nothing to enable');

        // a viewer is not offered a tab it could do nothing with
        $viewer = User::factory()->create(['enabled' => 1]);
        $viewer->assignRole('user');
        DB::table('devices_perms')->insert(['user_id' => $viewer->user_id, 'device_id' => $candidate->device_id]);
        $this->actingAs($viewer);
        $this->assertFalse($tab->visible($candidate));
    }

    public function testTheOverviewPanelStaysHiddenUntilThereIsSomethingToShow(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $candidate = Device::factory()->create(['os' => 'junos']);

        // the panel summarises collected data; the tab is where an admin enables the device
        $this->assertFalse(app(\SafferIt\LibrenmsNetconf\Hooks\DeviceOverview::class)->authorize($candidate));
        $this->assertTrue((new NetconfTab)->visible($candidate));
    }

    public function testTheStatusSectionOffersEnableAndTheButtonEnablesTheDevice(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = Device::factory()->create(['os' => 'junos']);
        $id = $device->device_id;

        $html = $this->get("/device/$id/netconf")->assertOk()->getContent();
        $this->assertStringContainsString('NETCONF is not enabled for this device', $html);
        $this->assertStringContainsString('Enable NETCONF for this device', $html);
        $this->assertStringContainsString('<code>junos-system</code>', $html);
        $this->assertStringContainsString('password missing', $html);   // credential source, never a secret
        $this->assertStringNotContainsString('name="password"', $html);

        $this->post("/plugin/netconf/device/$id", ['enabled' => '1'])
            ->assertRedirect("/device/$id/netconf");   // status section, not edit: Test/Discover are next

        $this->assertTrue(NetconfService::make()->isEnabled($device->fresh()));
        $this->assertSame('1', DeviceSettings::enabledAttrib($device->fresh()));

        // one process serves both requests here; in production the next request re-reads the
        // device, so the cached copy from the first render must not answer for it
        \App\Facades\DeviceCache::flush();

        $after = $this->get("/device/$id/netconf")->assertOk()->getContent();
        $this->assertStringNotContainsString('NETCONF is not enabled for this device', $after);
        $this->assertStringContainsString('Test connection', $after);
        $this->assertStringContainsString('Discover now', $after);
    }

    public function testTheCredentialFormStillReturnsToTheEditSection(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $device = Device::factory()->create(['os' => 'junos']);
        $id = $device->device_id;

        $this->post("/plugin/netconf/device/$id", ['enabled' => '1', 'username' => 'netconf-ro'])
            ->assertRedirect("/device/$id/netconf/edit");
        $this->assertSame('netconf-ro', DeviceSettings::current($device->fresh())['username'] ?? null);
    }

    public function testAViewerIsOfferedNoWayToEnableADevice(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        DeviceSettings::apply($device, ['enabled' => '1']);
        $device->save();
        $id = $device->device_id;

        $viewer = User::factory()->create(['enabled' => 1]);
        $viewer->assignRole('user');
        DB::table('devices_perms')->insert(['user_id' => $viewer->user_id, 'device_id' => $id]);
        $this->actingAs($viewer);

        $html = $this->get("/device/$id/netconf")->assertOk()->getContent();
        $this->assertStringNotContainsString('Enable NETCONF for this device', $html);
        $this->post("/plugin/netconf/device/$id", ['enabled' => '0'])->assertForbidden();
        $this->assertTrue(NetconfService::make()->isEnabled($device->fresh()));
    }

    public function testTheStatusPageNamesTheSingleDevicePath(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));

        $html = $this->get('/plugin/netconf/status')->assertOk()->getContent();
        $this->assertStringContainsString('One device at a time', $html);
        $this->assertStringContainsString('netconf:device', $html);
    }
}
