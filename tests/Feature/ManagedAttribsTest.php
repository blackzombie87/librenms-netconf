<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Fabric\View\MacSearch;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\Support\DevicePage;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * The EVPN MAC database as an opt-out setting (plan §13, M5): the global switch decides, the
 * device attribute overrides it, and nothing collects while the EVPN fabric view is off.
 */
final class ManagedAttribsTest extends LibrenmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['netconf.evpn_fabric' => true, 'netconf.evpn_mac' => true]);
    }

    public function testTheGlobalSettingDecidesAndTheDeviceAttributeOverridesIt(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $service = app(NetconfService::class);

        // no attribute at all: the device collects because the setting says so
        $this->assertTrue($this->collectsMac($service, $device));

        // the global switch off is the whole fleet off ...
        config(['netconf.evpn_mac' => false]);
        $this->assertFalse($this->collectsMac($service, $device->fresh()));

        // ... except where a device says otherwise
        $device->setAttrib('netconf_evpn_mac', '1');
        $this->assertTrue($this->collectsMac($service, $device->fresh()));

        // and a device that says no is never collected, however the switch stands
        config(['netconf.evpn_mac' => true]);
        $device->setAttrib('netconf_evpn_mac', '0');
        $this->assertFalse($this->collectsMac($service, $device->fresh()));
    }

    public function testNothingCollectsWhileTheFabricViewIsOff(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $device->setAttrib('netconf_evpn_mac', '1');
        config(['netconf.evpn_fabric' => false]);

        // the definition carries tables: mappings, so the fabric switch drops it first
        $this->assertFalse($this->collectsMac(app(NetconfService::class), $device->fresh()));
    }

    public function testTheMacsPageCountsTheEffectiveSetRatherThanTheAttributeRows(): void
    {
        $collecting = Device::factory()->create(['os' => 'junos']);
        $off = Device::factory()->create(['os' => 'junos']);
        $off->setAttrib('netconf_evpn_mac', '0');
        foreach ([$collecting, $off] as $device) {
            NetconfDeviceStatus::query()->create(['device_id' => $device->device_id, 'poll_count' => 1]);
        }
        $ids = [$collecting->device_id, $off->device_id];

        // neither device carries netconf_evpn_mac = 1, and one of the two still collects
        $this->assertSame(
            ['devices' => 1, 'candidates' => 2, 'rows' => 0, 'global' => true, 'fabric' => true],
            MacSearch::optedIn($ids),
        );

        config(['netconf.evpn_mac' => false]);
        $this->assertSame(0, MacSearch::optedIn($ids)['devices']);

        $collecting->setAttrib('netconf_evpn_mac', '1');
        $this->assertSame(1, MacSearch::optedIn($ids)['devices']);

        // null scope = every device the plugin has run on
        $this->assertSame(2, MacSearch::optedIn(null)['candidates']);
    }

    public function testTheDeviceTabWritesTheTriStateAndTheFormOffersIt(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));

        $this->get(DevicePage::url($device->device_id, 'edit'))->assertOk()
            ->assertSee('EVPN MAC database')
            ->assertSee('Per-queue counters')
            ->assertSee('global default (on)');

        $this->post('/plugin/netconf/device/' . $device->device_id, ['evpn_mac' => '0', 'queues' => '1'])->assertRedirect();
        $this->assertSame('0', $device->fresh()->getAttrib('netconf_evpn_mac'));
        $this->assertSame('1', $device->fresh()->getAttrib('netconf_queues'));

        $this->post('/plugin/netconf/device/' . $device->device_id, ['evpn_mac' => 'inherit'])->assertRedirect();
        $this->assertNull($device->fresh()->getAttrib('netconf_evpn_mac'));

        $this->post('/plugin/netconf/device/' . $device->device_id, ['evpn_mac' => 'maybe'])->assertSessionHasErrors('evpn_mac');
    }

    private function collectsMac(NetconfService $service, Device $device): bool
    {
        $names = array_map(fn ($d) => $d->name, $service->matchingDefinitions($device));

        return in_array('junos-evpn-fabric-mac', $names, true);
    }
}
