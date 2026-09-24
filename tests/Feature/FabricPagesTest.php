<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * HTTP authorisation of the fabric pages (G6 first slice): global read for the pages, admin
 * for anything that changes a fabric or talks to a device, login for everything. Plus the
 * paging and the issues-only default of the VNIs tab (F4a 3).
 */
final class FabricPagesTest extends LibrenmsTestCase
{
    public function testPagesRedirectToLoginWithoutASession(): void
    {
        foreach (['/plugin/netconf/fabrics', '/plugin/netconf/fabric/1', '/plugin/netconf/evpn/mac', '/plugin/netconf/status'] as $path) {
            $this->get($path)->assertStatus(302)->assertRedirectContains('/login');
        }
    }

    public function testAdminSeesTheFabricListAndTheTabs(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $fabric = $this->fabric();

        $this->get('/plugin/netconf/fabrics')->assertOk()->assertSee('Fabric test');
        foreach (['overview', 'members', 'bgp', 'vnis', 'esis', 'tunnels', 'macs', 'checks'] as $tab) {
            $this->get("/plugin/netconf/fabric/$fabric/$tab")->assertOk();
        }
        $this->get("/plugin/netconf/fabric/$fabric/nope")->assertNotFound();
        $this->get('/plugin/netconf/fabric/999999')->assertNotFound();
        $this->get('/plugin/netconf/evpn/mac?q=00:11:22:33:44:55')->assertOk();
    }

    public function testAdminRenamesAFabric(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $fabric = $this->fabric();

        $this->post("/plugin/netconf/fabric/$fabric", ['name' => 'Renamed', 'notes' => 'note'])
            ->assertRedirect("/plugin/netconf/fabric/$fabric/overview");
        $this->assertSame('Renamed', DB::table(TableSchema::tableName('fabric'))->where('id', $fabric)->value('name'));
        // a second, identical save is not a missing fabric (0 rows changed)
        $this->post("/plugin/netconf/fabric/$fabric", ['name' => 'Renamed', 'notes' => 'note'])->assertStatus(302);
        $this->post('/plugin/netconf/fabric/999999', ['name' => 'x'])->assertNotFound();
    }

    public function testGlobalReadUserReadsButCannotChangeAnything(): void
    {
        $this->actingAs(User::factory()->read()->create(['enabled' => 1]));
        $fabric = $this->fabric();

        $this->get('/plugin/netconf/fabrics')->assertOk();
        $this->get("/plugin/netconf/fabric/$fabric")->assertOk();
        $this->post("/plugin/netconf/fabric/$fabric", ['name' => 'Hacked'])->assertForbidden();
        $this->post('/plugin/netconf/run', ['device' => '1', 'command' => 'show version'])->assertForbidden();
        $this->assertSame('Fabric test', DB::table(TableSchema::tableName('fabric'))->where('id', $fabric)->value('name'));
    }

    public function testTheOverviewCarriesBothTopologyRenderings(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        [$fabric] = $this->fabricWithVnis(3);

        $page = $this->get("/plugin/netconf/fabric/$fabric")->assertOk()->getContent();

        // the interactive map, from the vis-network LibreNMS itself ships (no CDN)
        $this->assertStringContainsString('js/vis-network.min.js', $page);
        $this->assertStringContainsString('id="nt-net"', $page);
        $this->assertStringContainsString('"overlay_pairs"', $page);
        // ... and the static SVG behind it, for a core without vis and for a picture to paste
        $this->assertStringContainsString('id="nt-svg"', $page);
        $this->assertStringContainsString('id="nt-static-wrap"', $page);
    }

    public function testTheVnisTabPagesAndOpensOnTheRowsWithIssues(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        [$fabric, $device] = $this->fabricWithVnis(250);
        $page = fn (string $query = '') => $this->get("/plugin/netconf/fabric/$fabric/vnis$query")->assertOk()->getContent();

        // no issue anywhere: the tab shows everything, 100 rows per page
        $first = $page();
        $this->assertSame(100, substr_count($first, '<tr class='));
        $this->assertStringContainsString('250 of 250 shown', $first);
        $this->assertStringContainsString('rows 1&ndash;100 of 250', $first);
        $this->assertStringContainsString('>10010<', $first);
        $this->assertStringNotContainsString('>10150<', $first);

        $second = $page('?page=2');
        $this->assertStringContainsString('>10150<', $second);
        $this->assertStringContainsString('rows 101&ndash;200 of 250', $second);
        $this->assertSame(50, substr_count($page('?page=3'), '<tr class='));
        // out of range is clamped, not a 404
        $this->assertStringContainsString('rows 201&ndash;250 of 250', $page('?page=99'));

        // the filter survives the paging, and narrows the pager
        $filtered = $page('?q=10010');
        $this->assertSame(1, substr_count($filtered, '<tr class='));
        $this->assertStringContainsString('1 of 250 shown', $filtered);
        $this->assertStringNotContainsString('rows 1&ndash;', $filtered);   // one page, no pager

        // two more carriers of VNI 10010, one of which does not hear the first leaf although
        // the other does: one VNI with a flood-list gap. The tab opens on it, ?issues=0 shows
        // everything again
        $this->carriersWithAGap($fabric, $device, 10010);
        $default = $page();
        $this->assertSame(1, substr_count($default, '<tr class='));
        $this->assertStringContainsString('1 of 250 shown', $default);
        $this->assertStringContainsString('this tab opens on the rows with issues', $default);
        $this->assertSame(100, substr_count($page('?issues=0'), '<tr class='));
    }

    /**
     * Two more monitored carriers of one VNI, and the flood lists that make exactly one gap:
     * the third leaf hears both others (so the first one demonstrably advertises the VNI), the
     * second does not hear the first. A gap needs that witness — with two carriers a flood list
     * cannot be told apart from a VNI the other leaf simply does not announce (plan §10.3).
     */
    private function carriersWithAGap(int $fabric, int $firstDevice, int $vni): void
    {
        $now = now();
        $devices = [1 => $firstDevice];
        foreach ([2, 3] as $n) {
            $devices[$n] = Device::factory()->create(['os' => 'junos'])->device_id;
            DB::table(TableSchema::tableName('vtep'))->insert(['vtep_ip' => "192.0.2.$n", 'device_id' => $devices[$n], 'role' => 'leaf', 'last_seen' => $now]);
            DB::table(TableSchema::tableName('fabric_member'))->insert(['fabric_id' => $fabric, 'vtep_ip' => "192.0.2.$n", 'role' => 'leaf', 'since' => $now]);
            DB::table(TableSchema::tableName('vni'))->insert(['device_id' => $devices[$n], 'vni' => $vni, 'instance' => 'MACVRF-A', 'source_vtep' => "192.0.2.$n", 'last_seen' => $now]);
        }
        $flood = [
            [1, '192.0.2.2'], [1, '192.0.2.3'],   // the first leaf hears both
            [2, '192.0.2.3'],                     // ... the second does not hear the first: the gap
            [3, '192.0.2.1'], [3, '192.0.2.2'],   // ... the third hears both
        ];
        foreach ($flood as [$n, $peer]) {
            DB::table(TableSchema::tableName('vni_vtep'))->insert(['device_id' => $devices[$n], 'vni' => $vni, 'remote_vtep_ip' => $peer, 'last_seen' => $now]);
        }
    }

    /**
     * @return array{int, int} fabric id and the member device
     */
    private function fabricWithVnis(int $count): array
    {
        $fabric = $this->fabric();
        $device = Device::factory()->create(['os' => 'junos']);
        $now = now();
        DB::table(TableSchema::tableName('vtep'))->insert(['vtep_ip' => '192.0.2.1', 'device_id' => $device->device_id, 'role' => 'leaf', 'last_seen' => $now]);
        DB::table(TableSchema::tableName('fabric_member'))->insert(['fabric_id' => $fabric, 'vtep_ip' => '192.0.2.1', 'role' => 'leaf', 'since' => $now]);
        $rows = [];
        $flood = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['device_id' => $device->device_id, 'vni' => 10010 + $i, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.1', 'last_seen' => $now];
            // a flood entry towards an unmonitored VTEP: collected, counted, not judged, so
            // no VNI carries an issue until one of these rows is removed
            $flood[] = ['device_id' => $device->device_id, 'vni' => 10010 + $i, 'remote_vtep_ip' => '192.0.2.99', 'last_seen' => $now];
        }
        DB::table(TableSchema::tableName('vni'))->insert($rows);
        DB::table(TableSchema::tableName('vni_vtep'))->insert($flood);

        return [$fabric, $device->device_id];
    }

    private function fabric(): int
    {
        return (int) DB::table(TableSchema::tableName('fabric'))->insertGetId(['name' => 'Fabric test', 'key' => '192.0.2.1', 'auto' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
