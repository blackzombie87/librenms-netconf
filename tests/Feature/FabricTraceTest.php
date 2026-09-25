<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * The tracer end to end (plan §12 T5): the tab resolves both endpoints from the stored
 * tables, finds the underlay path and prints the one-liner; live mode is admin-only; and the
 * CLI prints the same string the page does.
 */
final class FabricTraceTest extends LibrenmsTestCase
{
    public function testTheTabTracesBetweenTwoLeavesAndPrintsEveryHopInterface(): void
    {
        $this->actingAs(User::factory()->read()->create(['enabled' => 1]));
        $fabric = $this->threeLeafFabric();

        $page = $this->get("/plugin/netconf/fabric/$fabric/trace?from=02:00:00:00:11:40&to=02:00:00:00:11:41")->assertOk()->getContent();

        $this->assertStringContainsString('(ge-0/0/38)[leaf-1](et-0/0/52.2121) &lt;-&gt; (et-0/0/52.2121)[leaf-2](et-0/0/50.2261) &lt;-&gt; (et-0/0/53.2261)[leaf-3](ge-0/0/28)', $page);
        $this->assertStringContainsString('Both endpoints are in the same VNI', $page);
        $this->assertStringContainsString('EVPN session', $page);
        // the sources it consulted are on the page, including the empty ones
        $this->assertStringContainsString('core bridge tables (ports_fdb)', $page);
        $this->assertStringContainsString('EVPN MAC database', $page);
    }

    public function testAnEndpointThatIsNowhereSaysWhichSourcesWereConsulted(): void
    {
        $this->actingAs(User::factory()->read()->create(['enabled' => 1]));
        $fabric = $this->threeLeafFabric();

        $page = $this->get("/plugin/netconf/fabric/$fabric/trace?from=02:00:00:00:99:99&to=02:00:00:00:11:41")->assertOk()->getContent();

        $this->assertStringContainsString('could not be resolved', $page);
        $this->assertStringContainsString('core ARP (ipv4_mac)', $page);
    }

    public function testLiveModeIsAdminOnly(): void
    {
        $fabric = $this->threeLeafFabric();
        $payload = ['from' => '02:00:00:00:11:40', 'to' => '02:00:00:00:11:41'];

        $this->actingAs(User::factory()->read()->create(['enabled' => 1]));
        $this->get("/plugin/netconf/fabric/$fabric/trace")->assertOk();
        $this->post("/plugin/netconf/fabric/$fabric/trace", $payload)->assertForbidden();

        // an admin gets the redirect; the walk itself has no device to reach in a test
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $this->post("/plugin/netconf/fabric/$fabric/trace", $payload)
            ->assertRedirect("/plugin/netconf/fabric/$fabric/trace");
        $this->post("/plugin/netconf/fabric/$fabric/trace", ['from' => ''])->assertSessionHasErrors('from');
    }

    public function testTheCommandPrintsTheSameLineAsThePage(): void
    {
        $fabric = $this->threeLeafFabric();

        $code = \Illuminate\Support\Facades\Artisan::call('netconf:trace', ['from' => '02:00:00:00:11:40', 'to' => '02:00:00:00:11:41', '--fabric' => (string) $fabric]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString(
            '(ge-0/0/38)[leaf-1](et-0/0/52.2121) <-> (et-0/0/52.2121)[leaf-2](et-0/0/50.2261) <-> (et-0/0/53.2261)[leaf-3](ge-0/0/28)',
            \Illuminate\Support\Facades\Artisan::output(),
        );

        $failed = \Illuminate\Support\Facades\Artisan::call('netconf:trace', ['from' => '02:00:00:00:99:99', 'to' => '02:00:00:00:11:41', '--fabric' => (string) $fabric]);
        $this->assertSame(1, $failed);
        $this->assertStringContainsString('core ARP (ipv4_mac)', \Illuminate\Support\Facades\Artisan::output());
    }

    /**
     * Three leaves in a row with one MAC at each end: BER1 — BER2 — RLG1, the shape §12.1
     * verified on the production fabric.
     */
    private function threeLeafFabric(): int
    {
        $now = now();
        $fabric = (int) DB::table(TableSchema::tableName('fabric'))->insertGetId(['name' => 'Trace test', 'key' => '192.0.2.61', 'auto' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $devices = [];
        foreach ([1, 2, 3] as $n) {
            $device = Device::factory()->create(['os' => 'junos', 'hostname' => "leaf-$n", 'sysName' => "leaf-$n"]);
            $devices[$n] = (int) $device->device_id;
            DB::table(TableSchema::tableName('vtep'))->insert(['vtep_ip' => "192.0.2.6$n", 'device_id' => $devices[$n], 'role' => 'leaf', 'last_seen' => $now]);
            DB::table(TableSchema::tableName('fabric_member'))->insert(['fabric_id' => $fabric, 'vtep_ip' => "192.0.2.6$n", 'role' => 'leaf', 'since' => $now]);
            DB::table(TableSchema::tableName('vni'))->insert(['device_id' => $devices[$n], 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => "192.0.2.6$n", 'last_seen' => $now]);
        }
        foreach ([1, 2, 3] as $n) {
            foreach ([1, 2, 3] as $m) {
                if ($n === $m) {
                    continue;
                }
                DB::table(TableSchema::tableName('tunnel'))->insert(['device_id' => $devices[$n], 'remote_vtep_ip' => "192.0.2.6$m", 'ifname' => 'vtep.3276' . $m, 'last_seen' => $now]);
                DB::table(TableSchema::tableName('vni_vtep'))->insert(['device_id' => $devices[$n], 'vni' => 10010, 'remote_vtep_ip' => "192.0.2.6$m", 'last_seen' => $now]);
                DB::table(TableSchema::tableName('neighbor'))->insert(['device_id' => $devices[$n], 'instance' => 'MACVRF-A', 'neighbor_ip' => "192.0.2.6$m", 'last_seen' => $now]);
            }
        }

        $ports = [];
        foreach ([[1, 'et-0/0/52.2121'], [2, 'et-0/0/52.2121'], [2, 'et-0/0/50.2261'], [3, 'et-0/0/53.2261']] as $i => [$n, $ifName]) {
            $ports[$i] = (int) DB::table('ports')->insertGetId(['device_id' => $devices[$n], 'ifName' => $ifName, 'ifIndex' => 500 + $i, 'deleted' => 0]);
        }
        DB::table(TableSchema::tableName('underlay_link'))->insert([
            ['fabric_id' => $fabric, 'link_key' => 'k1', 'a_device_id' => $devices[1], 'a_port_id' => $ports[0], 'a_address' => '10.1.1.0', 'b_device_id' => $devices[2], 'b_port_id' => $ports[1], 'b_address' => '10.1.1.1', 'network' => '10.1.1.0/31', 'protocol' => 'ospf', 'state' => 'Full', 'lldp' => 1, 'wan' => 0, 'last_seen' => $now],
            ['fabric_id' => $fabric, 'link_key' => 'k2', 'a_device_id' => $devices[2], 'a_port_id' => $ports[2], 'a_address' => '10.1.2.0', 'b_device_id' => $devices[3], 'b_port_id' => $ports[3], 'b_address' => '10.1.2.1', 'network' => '10.1.2.0/31', 'protocol' => 'ospf', 'state' => 'Full', 'lldp' => 1, 'wan' => 0, 'last_seen' => $now],
        ]);

        foreach ([[1, '020000001140', 'ge-0/0/38.0'], [3, '020000001141', 'ge-0/0/28.0']] as [$n, $mac, $ifl]) {
            $port = (int) DB::table('ports')->insertGetId(['device_id' => $devices[$n], 'ifName' => rtrim($ifl, '.0'), 'ifIndex' => 600 + $n, 'deleted' => 0]);
            DB::table(TableSchema::tableName('mac'))->insert([
                'device_id' => $devices[$n], 'vni' => 10010, 'instance' => 'MACVRF-A', 'mac_address' => $mac,
                'source_type' => 'local', 'source' => $ifl, 'source_device_id' => null, 'ip_addresses' => '[]',
                'moves' => 0, 'is_duplicate' => 0, 'first_seen' => $now, 'last_seen' => $now,
            ]);
            $this->assertGreaterThan(0, $port);
        }

        return $fabric;
    }
}
