<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * HTTP authorisation of the fabric pages (G6 first slice): global read for the pages, admin
 * for anything that changes a fabric or talks to a device, login for everything.
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
        foreach (['overview', 'members', 'bgp', 'vnis', 'esis', 'tunnels', 'macs'] as $tab) {
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

    private function fabric(): int
    {
        return (int) DB::table(TableSchema::tableName('fabric'))->insertGetId(['name' => 'Fabric test', 'key' => '192.0.2.1', 'auto' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
