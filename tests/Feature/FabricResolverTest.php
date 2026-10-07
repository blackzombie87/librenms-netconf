<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\EsiPeers;
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

    public function testALeafWithTwoAddressesIsStoredUnderTheLowerOne(): void
    {
        // 10.10.0.1 sorts before 10.9.0.1 as text and after it as an address (F6 5)
        $leaf = Device::factory()->create(['hostname' => 'leaf-two.example.net', 'os' => 'junos']);
        $now = now();
        DB::table(TableSchema::tableName('vni'))->insert([
            ['device_id' => $leaf->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '10.10.0.1', 'last_seen' => $now],
            ['device_id' => $leaf->device_id, 'vni' => 10020, 'instance' => 'MACVRF-B', 'source_vtep' => '10.9.0.1', 'last_seen' => $now],
        ]);
        DB::table(TableSchema::tableName('neighbor'))->insert([
            ['device_id' => $leaf->device_id, 'instance' => 'MACVRF-A', 'neighbor_ip' => '10.9.0.2', 'router_id' => '10.9.0.1', 'last_seen' => $now],
        ]);
        DB::table(TableSchema::tableName('esi'))->insert([
            'device_id' => $leaf->device_id, 'esi' => '00:11:22:33:44:55:66:77:88:99', 'instance' => 'MACVRF-A',
            'local_ifname' => 'ae0', 'mode' => 'all-active', 'status' => 'Resolved', 'lag_status' => 'Up', 'is_df' => 0,
            'df_ip' => '10.9.0.2', 'bdf_ip' => '10.9.0.1', 'remote_vtep_ips' => json_encode(['10.9.0.2']), 'last_seen' => $now,
        ]);

        FabricResolver::make()->run();

        $members = DB::table(TableSchema::tableName('fabric_member') . ' as m')
            ->join(TableSchema::tableName('vtep') . ' as v', 'v.vtep_ip', '=', 'm.vtep_ip')
            ->where('v.device_id', $leaf->device_id)->pluck('m.vtep_ip')->all();
        $this->assertSame(['10.9.0.1'], $members);   // the other address is an alias, not a second member
        $this->assertSame($leaf->device_id, (int) DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '10.10.0.1')->value('device_id'));
        $this->assertSame('10.9.0.1', DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '10.10.0.1')->value('router_id'));

        // the ESI view names the same address, so the BDF flag agrees with the member row
        $esi = EsiPeers::forDevice($leaf->device_id);
        $this->assertSame('10.9.0.1', $esi['own_vtep']);
        $this->assertTrue($esi['rows'][0]['is_bdf']);
    }

    public function testAnAggregateWinsOverItsLogicalUnitForPortLinks(): void
    {
        // core lists both `ae36` and the IFL `ae36.0`; the ESI-LAG graph belongs to the aggregate
        $leaf = Device::factory()->create(['hostname' => 'leaf-ae.example.net', 'os' => 'junos']);
        $now = now();
        $aggregate = (int) DB::table('ports')->insertGetId(['device_id' => $leaf->device_id, 'ifName' => 'ae36', 'ifIndex' => 536, 'deleted' => 0]);
        $unit = (int) DB::table('ports')->insertGetId(['device_id' => $leaf->device_id, 'ifName' => 'ae36.0', 'ifIndex' => 537, 'deleted' => 0]);
        $unitOnly = (int) DB::table('ports')->insertGetId(['device_id' => $leaf->device_id, 'ifName' => 'ge-0/0/9.0', 'ifIndex' => 538, 'deleted' => 0]);
        DB::table(TableSchema::tableName('vni'))->insert(['device_id' => $leaf->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.61', 'last_seen' => $now]);
        DB::table(TableSchema::tableName('esi'))->insert([
            ['device_id' => $leaf->device_id, 'esi' => '00:11:22:33:44:55:66:77:88:01', 'instance' => 'MACVRF-A', 'local_ifname' => 'ae36.0', 'mode' => 'all-active', 'status' => 'Resolved', 'lag_status' => 'Up', 'is_df' => 0, 'remote_vtep_ips' => json_encode(['192.0.2.62']), 'last_seen' => $now],
            ['device_id' => $leaf->device_id, 'esi' => '00:11:22:33:44:55:66:77:88:02', 'instance' => 'MACVRF-A', 'local_ifname' => 'ge-0/0/9.0', 'mode' => 'all-active', 'status' => 'Resolved', 'lag_status' => 'Up', 'is_df' => 0, 'remote_vtep_ips' => json_encode(['192.0.2.62']), 'last_seen' => $now],
        ]);
        DB::table(TableSchema::tableName('mac_ip'))->insert(['device_id' => $leaf->device_id, 'bridge_domain' => 'VX91', 'mac_address' => '00005e005301', 'ip_address' => '198.51.100.7', 'ifname' => 'ae36.0', 'last_seen' => $now]);

        FabricResolver::make()->run();

        $this->assertNotSame($aggregate, $unit);
        $this->assertSame($aggregate, (int) DB::table(TableSchema::tableName('esi'))->where('local_ifname', 'ae36.0')->value('local_port_id'));
        $this->assertSame($unitOnly, (int) DB::table(TableSchema::tableName('esi'))->where('local_ifname', 'ge-0/0/9.0')->value('local_port_id'));   // no aggregate: the unit it is
        $this->assertSame($aggregate, (int) DB::table(TableSchema::tableName('mac_ip'))->where('device_id', $leaf->device_id)->value('port_id'));
    }

    public function testRemoteMacSourcesAreAssignedToTheDeviceBehindTheVtep(): void
    {
        $leaf = Device::factory()->create(['hostname' => 'leaf-src.example.net', 'os' => 'junos']);
        $remote = Device::factory()->create(['hostname' => 'leaf-far.example.net', 'os' => 'junos']);
        $now = now();
        DB::table(TableSchema::tableName('vni'))->insert([
            ['device_id' => $leaf->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.61', 'last_seen' => $now],
            ['device_id' => $remote->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.62', 'last_seen' => $now],
        ]);
        $mac = fn (string $address, string $source) => [
            'device_id' => $leaf->device_id, 'vni' => 10010, 'mac_address' => $address, 'instance' => 'MACVRF-A',
            'source' => $source, 'source_type' => 'remote', 'first_seen' => $now, 'last_seen' => $now,
        ];
        DB::table(TableSchema::tableName('mac'))->insert([$mac('00005e005301', '192.0.2.62'), $mac('00005e005302', '192.0.2.62'), $mac('00005e005303', '203.0.113.9')]);

        $resolver = FabricResolver::make();
        $resolver->run();
        $bySource = fn () => DB::table(TableSchema::tableName('mac'))->orderBy('mac_address')->pluck('source_device_id')->map(fn ($v) => $v === null ? null : (int) $v)->all();
        $this->assertSame([$remote->device_id, $remote->device_id, null], $bySource());

        // the far leaf goes: its MACs lose the link again
        $resolver->forget($remote->device_id);
        $this->assertSame([null, null, null], $bySource());
    }

    public function testATunnelFindsItsPortByNameAndCountsTheMacsBehindIt(): void
    {
        // an MX reports no ifIndex match for its vtep IFL, and says where a tunnel's MACs live in its MAC database
        $mx = Device::factory()->create(['hostname' => 'router-a.example.net', 'os' => 'junos']);
        $leaf = Device::factory()->create(['hostname' => 'leaf-c.example.net', 'os' => 'junos']);
        $now = now();
        $port = (int) DB::table('ports')->insertGetId(['device_id' => $mx->device_id, 'ifName' => 'vtep.32772', 'ifIndex' => 697, 'deleted' => 0]);
        DB::table(TableSchema::tableName('vni'))->insert([
            ['device_id' => $mx->device_id, 'vni' => 91, 'instance' => 'EVPN-FABRIC', 'source_vtep' => '198.51.100.252', 'last_seen' => $now],
            ['device_id' => $leaf->device_id, 'vni' => 91, 'instance' => 'EVPN-FABRIC', 'source_vtep' => '192.0.2.11', 'last_seen' => $now],
        ]);
        DB::table(TableSchema::tableName('tunnel'))->insert([
            ['device_id' => $mx->device_id, 'remote_vtep_ip' => '192.0.2.11', 'ifname' => 'vtep.32772', 'snmp_index' => null, 'last_seen' => $now],
            ['device_id' => $leaf->device_id, 'remote_vtep_ip' => '198.51.100.252', 'ifname' => 'vtep.32769', 'snmp_index' => null, 'last_seen' => $now],
        ]);
        $mac = fn (string $address, string $source, string $type = 'remote') => [
            'device_id' => $mx->device_id, 'vni' => 91, 'mac_address' => $address, 'instance' => 'EVPN-FABRIC',
            'source' => $source, 'source_type' => $type, 'first_seen' => $now, 'last_seen' => $now,
        ];
        DB::table(TableSchema::tableName('mac'))->insert([$mac('00005e005301', '192.0.2.11'), $mac('00005e005302', '192.0.2.11'), $mac('00005e005303', 'ae1.0', 'local')]);

        FabricResolver::make()->run();

        $tunnel = fn (int $device) => (array) DB::table(TableSchema::tableName('tunnel'))->where('device_id', $device)->first(['port_id', 'mac_count']);
        // the port by name, and two remote MACs: the local one is not behind the tunnel
        $this->assertSame($port, (int) $tunnel($mx->device_id)['port_id']);
        $this->assertSame(2, (int) $tunnel($mx->device_id)['mac_count']);
        // the leaf collects no MACs, so its count is unknown and not 0
        $this->assertNull($tunnel($leaf->device_id)['mac_count']);
        $this->assertNull($tunnel($leaf->device_id)['port_id']);
    }
}
