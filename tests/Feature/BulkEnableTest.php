<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Support\DeviceSelection;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * Bulk enable by device group / os (plan G7): the selection, the shared apply and the admin
 * form on the status page.
 */
final class BulkEnableTest extends LibrenmsTestCase
{
    public function testSelectionByGroupOsAndBoth(): void
    {
        [$a, $b, $other] = $this->devices();
        $group = $this->group([$a, $other]);

        // factory hostnames are random, so both sides are sorted in PHP instead of relying on
        // creation order (the database's collation order is not the point of this test)
        $this->assertSame($this->sorted($a, $b), DeviceSelection::resolve(null, 'netconf-test-os')->pluck('hostname')->sort()->values()->all());
        $this->assertSame($this->sorted($a, $other), DeviceSelection::resolve($group->name, null)->pluck('hostname')->sort()->values()->all());
        $this->assertSame([$a->hostname], DeviceSelection::resolve((string) $group->id, 'netconf-test-os')->pluck('hostname')->all());
        $this->assertSame('device group "x" and os junos', DeviceSelection::describe('x', 'junos'));

        $this->expectException(\InvalidArgumentException::class);
        DeviceSelection::resolve('no such group ' . uniqid(), null);
    }

    public function testApplyManyWritesTheAttributeOnceAndReportsOnlyChanges(): void
    {
        [$a, $b] = $this->devices();

        $log = DeviceSettings::applyMany(DeviceSelection::resolve(null, 'netconf-test-os'), ['enabled' => '1']);
        $this->assertSame(['netconf_enabled: enabled'], $log[$a->hostname]);
        $this->assertSame(['netconf_enabled: enabled'], $log[$b->hostname]);
        $this->assertSame('1', $a->fresh()->getAttrib(NetconfService::ATTRIB_ENABLED));

        $again = DeviceSettings::applyMany(DeviceSelection::resolve(null, 'netconf-test-os'), ['enabled' => '1']);
        $this->assertSame([], $again[$a->hostname]);

        $reset = DeviceSettings::applyMany(DeviceSelection::resolve(null, 'netconf-test-os'), ['enabled' => 'inherit']);
        $this->assertSame(['netconf_enabled: inherit global default'], $reset[$b->hostname]);
        $this->assertNull($b->fresh()->getAttrib(NetconfService::ATTRIB_ENABLED));
    }

    public function testAdminFormEnablesByOsAndViewerIsForbidden(): void
    {
        [$a, $b] = $this->devices();

        $this->actingAs(User::factory()->read()->create(['enabled' => 1]));
        $this->post('/plugin/netconf/bulk', ['os' => 'netconf-test-os', 'enabled' => '1'])->assertForbidden();
        $this->assertNull($a->fresh()->getAttrib(NetconfService::ATTRIB_ENABLED));

        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $this->post('/plugin/netconf/bulk', ['os' => 'netconf-test-os', 'enabled' => '1'])->assertRedirect('/plugin/netconf/status');
        $this->assertSame('1', $a->fresh()->getAttrib(NetconfService::ATTRIB_ENABLED));
        $this->assertSame('1', $b->fresh()->getAttrib(NetconfService::ATTRIB_ENABLED));
        $this->get('/plugin/netconf/status')->assertOk()->assertSee('2 device(s) match os netconf-test-os, 2 changed');

        $this->post('/plugin/netconf/bulk', ['group' => 'no such group', 'enabled' => '0'])->assertRedirect('/plugin/netconf/status');
        $this->get('/plugin/netconf/status')->assertOk()->assertSee('unknown device group');
        $this->post('/plugin/netconf/bulk', ['os' => 'netconf-test-os'])->assertSessionHasErrors('enabled');
    }

    public function testApplyWithNothingChosenIsRejectedAndTheFormPreselectsNothing(): void
    {
        [$a, $b] = $this->devices();
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));

        // nothing is pre-selected on the fresh form: no os even when the install has one,
        // and a blank first choice in the enabled select
        $this->get('/plugin/netconf/status')->assertOk()
            ->assertSee('<option value="">&mdash; choose &mdash;</option>', false)
            ->assertDontSee('selected>netconf-test-os</option>', false);

        // Apply without a choice is rejected and changes nothing (the previous input is kept)
        foreach ([[], ['enabled' => ''], ['os' => 'netconf-test-os', 'enabled' => '']] as $payload) {
            $this->post('/plugin/netconf/bulk', $payload)->assertSessionHasErrors('enabled');
        }
        $this->assertNull($a->fresh()->getAttrib(NetconfService::ATTRIB_ENABLED));
        $this->assertNull($b->fresh()->getAttrib(NetconfService::ATTRIB_ENABLED));
        $this->get('/plugin/netconf/status')->assertOk()->assertSee('<option value="netconf-test-os" selected>', false);
    }

    /**
     * @return array{Device, Device, Device} two devices with the test os, one with another
     */
    private function devices(): array
    {
        $a = Device::factory()->create(['os' => 'netconf-test-os']);
        $b = Device::factory()->create(['os' => 'netconf-test-os']);
        $other = Device::factory()->create(['os' => 'netconf-test-other']);

        return [$a, $b, $other];
    }

    /**
     * @return list<string> the hostnames, PHP-sorted like the actual side of the assertions
     */
    private function sorted(Device ...$devices): array
    {
        $names = array_map(fn (Device $d) => $d->hostname, $devices);
        sort($names);

        return $names;
    }

    /**
     * @param  list<Device>  $members
     */
    private function group(array $members): DeviceGroup
    {
        $group = DeviceGroup::query()->create(['name' => 'netconf-test-' . uniqid(), 'type' => 'static']);
        $group->devices()->attach(array_map(fn (Device $d) => $d->device_id, $members));

        return $group;
    }
}
