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
        // the picture: three device cards, and the interfaces of a hop on the link between them
        $this->assertSame(3, substr_count($page, 'nt-card nt-leaf'));
        $this->assertStringContainsString('<code title="et-0/0/52.2121">et-0/0/52.2121</code>', $page);
        $this->assertStringNotContainsString('<img class="nt-spark" alt="port 0"', $page);
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
            ->assertRedirectContains("/plugin/netconf/fabric/$fabric/trace");
        $this->post("/plugin/netconf/fabric/$fabric/trace", ['from' => ''])->assertSessionHasErrors('from');
    }

    /**
     * The live button submits a hidden form, so the pair it carries has to be the one in the
     * boxes on the way out (the blade copies it across) and the one that was walked on the way
     * back. Before this the hidden fields held the *previous* request's query string: a first
     * click posted an empty pair, and a later one posted the pair before the one just typed.
     */
    public function testALiveTraceComesBackWithThePairItWalkedInTheBoxes(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $fabric = $this->threeLeafFabric();
        $payload = ['from' => '02:00:00:00:11:40', 'to' => '02:00:00:00:11:41', 'vni' => '10010'];

        $location = (string) $this->post("/plugin/netconf/fabric/$fabric/trace", $payload)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['from' => '02:00:00:00:11:40', 'to' => '02:00:00:00:11:41', 'vni' => '10010'], $query);

        $page = $this->followingRedirects()
            ->post("/plugin/netconf/fabric/$fabric/trace", $payload)->assertOk()->getContent();

        // the visible boxes and the hidden POST form both start out on the pair that was
        // walked, so the next click on "Trace live" repeats that walk and not an empty one
        $this->assertStringContainsString('id="netconf-trace-from" class="form-control" placeholder="source MAC or IP" value="02:00:00:00:11:40"', $page);
        $this->assertStringContainsString('<input type="hidden" name="from" value="02:00:00:00:11:40">', $page);
        $this->assertStringContainsString('<input type="hidden" name="to" value="02:00:00:00:11:41">', $page);
        $this->assertStringContainsString('<input type="hidden" name="vni" value="10010">', $page);
    }

    /**
     * The redirect puts the pair back on the query string, which is also what a graph trace
     * reads — so the tab must not also run the stored-table trace nobody asked for and the
     * blade does not show while a live result is in the session.
     */
    public function testTheTabAfterALiveWalkDoesNotAlsoRunTheGraphTrace(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $fabric = $this->threeLeafFabric();
        $url = "/plugin/netconf/fabric/$fabric/trace?from=02:00:00:00:11:40&to=02:00:00:00:11:41";
        $sources = ['consulted' => [], 'candidates' => [], 'notes' => []];
        $live = ['ok' => false, 'mode' => 'live', 'reason' => 'no session to any member', 'from' => 'a', 'to' => 'b', 'a_sources' => $sources, 'b_sources' => $sources];
        $macTable = TableSchema::tableName('mac');
        // the endpoint resolution is the only thing on this tab that reads the MAC database,
        // so counting those is a stabler witness than a total query count
        $lookups = fn () => count(array_filter(DB::getQueryLog(), fn (array $q) => str_contains((string) $q['query'], $macTable)));

        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $graph = $lookups();

        DB::flushQueryLog();
        $page = $this->withSession(['netconf_trace' => $live])->get($url)->assertOk()->getContent();
        $afterLive = $lookups();
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $graph);
        $this->assertSame(0, $afterLive, 'the live result is what the page shows, so the graph trace is dead work');
        $this->assertStringContainsString('no session to any member', $page);
        $this->assertStringNotContainsString('(ge-0/0/38)[leaf-1]', $page);
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

    public function testTheTabRoutesBetweenTwoVnisThroughTheGatewayThatOwnsBothIrbs(): void
    {
        $fabric = $this->routedFabric();

        $this->actingAs(User::factory()->read()->create(['enabled' => 1]));

        $page = $this->get("/plugin/netconf/fabric/$fabric/trace?from=02:00:00:00:11:40&to=02:00:00:00:11:41")->assertOk()->getContent();

        // the one-liner names the routing step on the gateway it happens on, and the two
        // legs are checked against their own VNIs
        $this->assertStringContainsString(
            '(ge-0/0/38)[leaf-1](et-0/0/52.2121) &lt;-&gt; (et-0/0/52.2121)[leaf-2: irb.10 → irb.20](et-0/0/50.2261) &lt;-&gt; (et-0/0/53.2261)[leaf-3](ge-0/0/28)',
            $page,
        );
        $this->assertStringContainsString('routed on leaf-2', $page);
        $this->assertStringContainsString('L3 context master', $page);
        $this->assertStringContainsString('nt-card nt-leaf nt-routes', $page);   // the routing step sits on leaf-2's card
        $this->assertStringContainsString('Routed between VNI 10010 and VNI 10020', $page);
        $this->assertStringNotContainsString('local switching', $page);
    }

    public function testTheCommandRoutesBetweenTwoVnisAndNamesTheGateway(): void
    {
        $fabric = $this->routedFabric();

        $code = \Illuminate\Support\Facades\Artisan::call('netconf:trace', ['from' => '02:00:00:00:11:40', 'to' => '02:00:00:00:11:41', '--fabric' => (string) $fabric]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('[leaf-2: irb.10 → irb.20]', $output);
        $this->assertStringContainsString('routed on leaf-2: irb.10 → irb.20 in L3 context master', $output);
    }

    /**
     * The same three leaves, with the middle one turned into the anycast gateway: it has an
     * IRB in both VNIs, in one L3 context, and the destination MAC moves to the second VNI.
     * The routed shape of plan §12.9 T6a, which is what a centrally-routed fabric looks like.
     */
    private function routedFabric(): int
    {
        $fabric = $this->threeLeafFabric();
        $now = now();
        $gateway = (int) DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '192.0.2.62')->value('device_id');
        $leaf3 = (int) DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '192.0.2.63')->value('device_id');

        // the destination lives in a second VNI, so the flow is routed and not bridged
        DB::table(TableSchema::tableName('mac'))->where('device_id', $leaf3)->update(['vni' => 10020]);
        DB::table(TableSchema::tableName('vni'))->insert(['device_id' => $leaf3, 'vni' => 10020, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.63', 'last_seen' => $now]);

        foreach ([[10010, 'irb.10'], [10020, 'irb.20']] as [$vni, $ifname]) {
            DB::table(TableSchema::tableName('vni'))->updateOrInsert(
                ['device_id' => $gateway, 'vni' => $vni],
                ['instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.62', 'irb_ifname' => $ifname, 'irb_status' => 'Up', 'irb_l3_context' => 'master', 'last_seen' => $now],
            );
            DB::table('ports')->insert(['device_id' => $gateway, 'ifName' => $ifname, 'ifIndex' => 700 + $vni, 'deleted' => 0]);
        }

        // the second VNI is flooded between the gateway and the leaf that owns the endpoint
        foreach ([[$gateway, '192.0.2.63'], [$leaf3, '192.0.2.62']] as [$deviceId, $remote]) {
            DB::table(TableSchema::tableName('vni_vtep'))->insert(['device_id' => $deviceId, 'vni' => 10020, 'remote_vtep_ip' => $remote, 'last_seen' => $now]);
        }

        return $fabric;
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
