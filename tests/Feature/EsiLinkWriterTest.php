<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\EsiLinks;
use SafferIt\LibrenmsNetconf\Fabric\EsiLinkWriter;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * EsiLinkWriter against the core links table: rows keep their id across syncs, the peer's
 * side is filled in once the peer is monitored, and rows no ESI produces any more go.
 */
final class EsiLinkWriterTest extends LibrenmsTestCase
{
    public function testLinksKeepTheirIdsAcrossSyncsAndFollowTheEsiTable(): void
    {
        $leafA = Device::factory()->create(['hostname' => 'leaf-a.example.net']);
        $portA = Port::factory()->create(['device_id' => $leafA->device_id, 'ifName' => 'ae1', 'ifIndex' => 601]);
        $esi = '00:11:22:33:44:55:00:00:01:00';
        DB::table(TableSchema::tableName('esi'))->insert(['device_id' => $leafA->device_id, 'esi' => $esi, 'local_ifname' => 'ae1.0', 'local_port_id' => $portA->port_id, 'remote_vtep_ips' => json_encode(['192.0.2.12']), 'last_seen' => now()]);
        DB::table(TableSchema::tableName('vtep'))->insert(['vtep_ip' => '192.0.2.12', 'device_id' => null, 'name_hint' => 'leaf-b', 'first_seen' => now(), 'last_seen' => now()]);
        $writer = new EsiLinkWriter;
        $links = fn () => DB::table('links')->where('protocol', EsiLinks::PROTOCOL)->orderBy('id')->get();

        $this->assertSame(1, $writer->sync([$leafA->device_id]));
        $first = $links()->first();
        $this->assertSame('leaf-b', $first->remote_hostname);
        $this->assertSame(0, (int) $first->remote_device_id);
        $this->assertSame('', (string) $first->remote_port);
        // unchanged input: the row is updated in place, its id stays
        $this->assertSame(1, $writer->sync([$leafA->device_id]));
        $this->assertSame((int) $first->id, (int) $links()->first()->id);

        // the peer becomes monitored: the row identity (local port, remote hostname, remote
        // port — core's own link key) changes, so the row is replaced by one pointing at the
        // device and its AE; still exactly one row
        $leafB = Device::factory()->create(['hostname' => 'leaf-b.example.net', 'hardware' => 'EX4650']);
        $portB = Port::factory()->create(['device_id' => $leafB->device_id, 'ifName' => 'ae7', 'ifIndex' => 607]);
        DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '192.0.2.12')->update(['device_id' => $leafB->device_id]);
        DB::table(TableSchema::tableName('esi'))->insert(['device_id' => $leafB->device_id, 'esi' => $esi, 'local_ifname' => 'ae7.0', 'local_port_id' => $portB->port_id, 'remote_vtep_ips' => json_encode(['192.0.2.11']), 'last_seen' => now()]);
        $this->assertSame(1, $writer->sync([$leafA->device_id]));
        $this->assertCount(1, $links());
        $second = $links()->first();
        $this->assertSame('leaf-b.example.net', $second->remote_hostname);
        $this->assertSame($leafB->device_id, (int) $second->remote_device_id);
        $this->assertSame($portB->port_id, (int) $second->remote_port_id);
        $this->assertSame('ae7.0', $second->remote_port);
        $this->assertSame('EX4650', $second->remote_platform);
        $writer->sync([$leafA->device_id]);
        $this->assertSame((int) $second->id, (int) $links()->first()->id);

        // no remote PE any more: the row goes; forget() removes what a device owns
        DB::table(TableSchema::tableName('esi'))->where('device_id', $leafA->device_id)->update(['remote_vtep_ips' => '[]']);
        $this->assertSame(0, $writer->sync([$leafA->device_id]));
        $this->assertCount(0, $links());
        DB::table(TableSchema::tableName('esi'))->where('device_id', $leafA->device_id)->update(['remote_vtep_ips' => json_encode(['192.0.2.12'])]);
        $writer->sync([$leafA->device_id]);
        $this->assertSame(1, $writer->forget($leafA->device_id));
        $this->assertCount(0, $links());
    }
}
