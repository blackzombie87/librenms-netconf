<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;
use SafferIt\LibrenmsNetconf\Fabric\FabricResolver;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * FabricResolver against the database: a leaf with a VTEP and a distinct router-id becomes
 * one member with an alias, its EVPN neighbour an unknown VTEP; forget() + run() is a round
 * trip while the tables exist and a full cleanup once they are gone.
 */
final class FabricResolverTest extends LibrenmsTestCase
{
    public function testForgetAndRunRoundTrip(): void
    {
        $leaf = Device::factory()->create(['hostname' => 'leaf-a.example.net', 'os' => 'junos']);
        $now = now();
        DB::table(TableSchema::tableName('vni'))->insert([
            ['device_id' => $leaf->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.61', 'last_seen' => $now],
            ['device_id' => $leaf->device_id, 'vni' => 10011, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.61', 'last_seen' => $now],
        ]);
        DB::table(TableSchema::tableName('neighbor'))->insert([
            ['device_id' => $leaf->device_id, 'instance' => 'MACVRF-A', 'neighbor_ip' => '192.0.2.1', 'router_id' => '192.0.2.11', 'last_seen' => $now],
        ]);
        $members = fn () => DB::table(TableSchema::tableName('fabric_member') . ' as m')
            ->join(TableSchema::tableName('vtep') . ' as v', 'v.vtep_ip', '=', 'm.vtep_ip')
            ->orderBy('m.vtep_ip')->get(['m.vtep_ip', 'm.role', 'v.device_id', 'v.router_id'])->map(fn ($r) => (array) $r)->all();
        $resolver = FabricResolver::make();

        $result = $resolver->run();
        $this->assertNotNull($result);
        $this->assertSame(['nodes' => 3, 'devices' => 1, 'unknown' => 1, 'fabrics' => 1], array_intersect_key($result, array_flip(['nodes', 'devices', 'unknown', 'fabrics'])));
        $this->assertSame([
            ['vtep_ip' => '192.0.2.1', 'role' => FabricGraph::ROLE_LEAF, 'device_id' => null, 'router_id' => null],       // neighbour: VXLAN evidence only from the edge
            ['vtep_ip' => '192.0.2.61', 'role' => FabricGraph::ROLE_LEAF, 'device_id' => $leaf->device_id, 'router_id' => '192.0.2.11'],
        ], array_map(fn ($r) => ['vtep_ip' => $r['vtep_ip'], 'role' => $r['role'], 'device_id' => $r['device_id'] === null ? null : (int) $r['device_id'], 'router_id' => $r['router_id']], $members()));
        // the router-id is an alias of the device, not a member
        $this->assertSame($leaf->device_id, (int) DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '192.0.2.11')->value('device_id'));
        $this->assertSame('192.0.2.1', DB::table(TableSchema::tableName('fabric'))->value('key'));

        // forget: memberships and device links go; run: restored from the tables the device still has
        $this->assertGreaterThan(0, $resolver->forget($leaf->device_id));
        $this->assertSame(['192.0.2.1'], array_column($members(), 'vtep_ip'));
        $this->assertNull(DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '192.0.2.61')->value('device_id'));
        $resolver->run();
        $this->assertSame(['192.0.2.1', '192.0.2.61'], array_column($members(), 'vtep_ip'));
        $this->assertSame($leaf->device_id, (int) DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '192.0.2.61')->value('device_id'));

        // the device is deleted: tables gone, forget, run -> nothing is left
        DB::table(TableSchema::tableName('vni'))->where('device_id', $leaf->device_id)->delete();
        DB::table(TableSchema::tableName('neighbor'))->where('device_id', $leaf->device_id)->delete();
        $resolver->forget($leaf->device_id);
        $this->assertSame(0, $resolver->run()['fabrics']);
        $this->assertSame(0, DB::table(TableSchema::tableName('fabric'))->count());
        $this->assertSame(0, DB::table(TableSchema::tableName('fabric_member'))->count());
        $this->assertSame(0, DB::table(TableSchema::tableName('vtep'))->count());
    }
}
