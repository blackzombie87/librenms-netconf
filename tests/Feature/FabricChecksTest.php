<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\Sensor;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Collect\SensorWriter;
use SafferIt\LibrenmsNetconf\Collect\TableWriter;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;
use SafferIt\LibrenmsNetconf\Fabric\Checks\Issue;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueSensor;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueStore;
use SafferIt\LibrenmsNetconf\Fabric\FabricResolver;

require_once __DIR__ . '/LibrenmsTestCase.php';
require_once __DIR__ . '/MemoryDatastore.php';

/**
 * The checks engine against the database: a resolve produces issues with eventlog entries
 * only on change, the per-leaf sensor value follows them, a stable issue keeps first_seen,
 * a cleared one is logged as ok, the sensor reads 0 on poll while the fabric view is paused
 * or after the device left the fabric (F4a 5), and the MAC table counts moves.
 */
final class FabricChecksTest extends LibrenmsTestCase
{
    public function testResolveStoresIssuesAndLogsOnlyChanges(): void
    {
        $leaf = Device::factory()->create(['hostname' => 'leaf-a.example.net', 'os' => 'junos']);
        $now = now();
        DB::table(TableSchema::tableName('vni'))->insert([
            ['device_id' => $leaf->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.61', 'last_seen' => $now],
        ]);
        // a single-PE ESI-LAG that is down, and an EVPN neighbour nobody monitors
        DB::table(TableSchema::tableName('esi'))->insert([
            ['device_id' => $leaf->device_id, 'esi' => '00:11:00:00:00:00:00:00:00:01', 'instance' => 'MACVRF-A', 'local_ifname' => 'ae1.0', 'status' => 'Resolved by IFL ae1.0', 'lag_status' => 'Down', 'is_df' => 1, 'remote_vtep_ips' => '[]', 'last_seen' => $now],
        ]);
        DB::table(TableSchema::tableName('neighbor'))->insert([
            ['device_id' => $leaf->device_id, 'instance' => 'MACVRF-A', 'neighbor_ip' => '192.0.2.1', 'router_id' => '192.0.2.61', 'last_seen' => $now],
        ]);
        $events = fn () => DB::table('eventlog')->where('type', IssueStore::EVENT_TYPE)->orderBy('event_id')->get(['device_id', 'severity', 'message'])->map(fn ($r) => (array) $r)->all();

        $result = FabricResolver::make()->run();
        $this->assertNotNull($result);
        $this->assertSame(['issues' => 3, 'issues_new' => 3, 'issues_cleared' => 0], array_intersect_key($result, array_flip(['issues', 'issues_new', 'issues_cleared'])));
        $issues = DB::table(IssueStore::TABLE)->orderBy('check')->get()->keyBy('check');
        $this->assertSame(['esi-lag-down', 'esi-single-pe', 'unknown-vtep'], $issues->keys()->all());
        $this->assertSame(Issue::CRITICAL, $issues['esi-lag-down']->severity);
        $this->assertSame('ESI-LAG leaf-a.example.net ae1.0 is Down (ESI 00:11:00:00:00:00:00:00:00:01)', $issues['esi-lag-down']->message);
        $this->assertSame([$leaf->device_id], DB::table(IssueStore::DEVICE_TABLE)->where('issue_id', $issues['esi-lag-down']->id)->pluck('device_id')->map(fn ($v) => (int) $v)->all());
        $this->assertCount(3, $events());
        $this->assertNull($events()[2]['device_id']);   // the unknown VTEP is a fabric-level entry without a device
        $this->assertSame(['critical' => 2, 'warning' => 0, 'info' => 0], IssueStore::countForDevice($leaf->device_id));
        $sensor = IssueSensor::value($leaf);
        $this->assertNotNull($sensor);
        $this->assertSame(2.0, $sensor->value);
        $this->assertSame('netconf-evpn-fabric-issues', $sensor->type());

        // nothing changed: same issues, first_seen kept, no new eventlog entry
        $firstSeen = $issues['esi-lag-down']->first_seen;
        $this->travel(5)->minutes();
        $result = FabricResolver::make()->run();
        $this->assertSame(['issues' => 3, 'issues_new' => 0, 'issues_cleared' => 0], array_intersect_key($result, array_flip(['issues', 'issues_new', 'issues_cleared'])));
        $this->assertSame($firstSeen, DB::table(IssueStore::TABLE)->where('check', 'esi-lag-down')->value('first_seen'));
        $this->assertGreaterThan($firstSeen, DB::table(IssueStore::TABLE)->where('check', 'esi-lag-down')->value('last_seen'));
        $this->assertCount(3, $events());

        // the LAG comes up: one issue clears with an ok entry on the device
        DB::table(TableSchema::tableName('esi'))->where('device_id', $leaf->device_id)->update(['lag_status' => 'Up/Forwarding']);
        $result = FabricResolver::make()->run();
        $this->assertSame(['issues' => 2, 'issues_new' => 0, 'issues_cleared' => 1], array_intersect_key($result, array_flip(['issues', 'issues_new', 'issues_cleared'])));
        $all = $events();
        $last = end($all) ?: [];
        $this->assertSame($leaf->device_id, (int) $last['device_id']);
        $this->assertStringStartsWith('EVPN fabric check esi-lag-down cleared:', $last['message']);
        $this->assertSame(['critical' => 1, 'warning' => 0, 'info' => 0], IssueStore::countForDevice($leaf->device_id));

        // the device goes: its issue links go with it, the next run recomputes
        $resolver = FabricResolver::make();
        DB::table(TableSchema::tableName('esi'))->where('device_id', $leaf->device_id)->delete();
        DB::table(TableSchema::tableName('vni'))->where('device_id', $leaf->device_id)->delete();
        DB::table(TableSchema::tableName('neighbor'))->where('device_id', $leaf->device_id)->delete();
        $resolver->forget($leaf->device_id);
        $this->assertSame(0, DB::table(IssueStore::DEVICE_TABLE)->where('device_id', $leaf->device_id)->count());
        $resolver->run();
        $this->assertSame(0, DB::table(IssueStore::TABLE)->count());
        $this->assertNull(IssueSensor::value($leaf));
    }

    public function testIssuesSensorReadsZeroWhileTheFabricIsPausedOrTheDeviceLeft(): void
    {
        $leaf = Device::factory()->create(['hostname' => 'leaf-c.example.net', 'os' => 'junos']);
        $now = now();
        DB::table(TableSchema::tableName('vni'))->insert([
            ['device_id' => $leaf->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.61', 'last_seen' => $now],
        ]);
        DB::table(TableSchema::tableName('esi'))->insert([
            ['device_id' => $leaf->device_id, 'esi' => '00:11:00:00:00:00:00:00:00:01', 'instance' => 'MACVRF-A', 'local_ifname' => 'ae1.0', 'status' => 'Resolved by IFL ae1.0', 'lag_status' => 'Down', 'is_df' => 1, 'remote_vtep_ips' => '[]', 'last_seen' => $now],
        ]);
        FabricResolver::make()->run();
        $writer = new SensorWriter($leaf);
        $datastore = new MemoryDatastore;
        $current = fn () => (float) Sensor::query()->where('device_id', $leaf->device_id)->where('sensor_type', IssueSensor::TYPE)->value('sensor_current');
        $record = function (bool $fabricEnabled) use ($leaf, $writer, $datastore): void {
            $reading = IssueSensor::reading($leaf, $fabricEnabled, discovery: false);
            $this->assertNotNull($reading);
            $this->assertSame(['recorded' => 1, 'unknown' => [], 'events' => 0], array_replace($writer->record([$reading], $datastore), ['events' => 0]));
        };

        // no sensor yet: nothing to record on poll, discovery creates it with the current count
        $this->assertFalse(IssueSensor::exists($leaf));
        $this->assertNull(IssueSensor::reading($leaf, fabricEnabled: false, discovery: false));
        $writer->sync([IssueSensor::reading($leaf, fabricEnabled: true, discovery: true)], [], ['count']);
        $this->assertTrue(IssueSensor::exists($leaf));
        $this->assertSame(2.0, $current());

        // the fabric view is switched off: the poll reads 0 instead of freezing the 2
        $record(false);
        $this->assertSame(0.0, $current());
        $this->assertSame([['sensor' => 0.0]], $datastore->fields('sensor'));

        // switched on again: the count is back
        $record(true);
        $this->assertSame(2.0, $current());

        // the device leaves the fabric (forget): 0 on poll, and discovery drops the sensor
        FabricResolver::make()->forget($leaf->device_id);
        $this->assertNull(IssueSensor::value($leaf));
        $record(true);
        $this->assertSame(0.0, $current());
        $this->assertNull(IssueSensor::reading($leaf, fabricEnabled: true, discovery: true));
        $writer->sync([], [], ['count']);
        $this->assertFalse(IssueSensor::exists($leaf));
    }

    public function testMacTableCountsMoves(): void
    {
        $leaf = Device::factory()->create(['hostname' => 'leaf-b.example.net', 'os' => 'junos']);
        $definition = (new DefinitionParser)->parse([
            'name' => 'macs', 'commands' => ['db' => 'show evpn database'],
            'tables' => [['id' => 'mac', 'table' => 'mac', 'command' => 'db', 'rows' => '//m', 'columns' => ['vni' => 'number(vni)', 'mac_address' => 'string(mac)', 'source' => 'string(src)']]],
        ], 'macs.yaml');
        $rows = fn (string $source) => (new Extractor)->tables($definition, $definition->tables[0], new XmlDocument("<r><m><vni>10010</vni><mac>00:11:22:33:44:ff</mac><src>$source</src></m></r>"));
        $writer = new TableWriter($leaf);
        $row = fn () => (array) DB::table(TableSchema::tableName('mac'))->where('device_id', $leaf->device_id)->first(['source', 'moves', 'moves_recent', 'moves_since']);

        $writer->write($rows('ae1.0'));
        $this->assertSame(['source' => 'ae1.0', 'moves' => 0, 'moves_recent' => 0, 'moves_since' => null], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $row()));

        $writer->write($rows('192.0.2.62'));
        $moved = $row();
        $this->assertSame('192.0.2.62', $moved['source']);
        $this->assertSame([1, 1], [(int) $moved['moves'], (int) $moved['moves_recent']]);
        $this->assertNotNull($moved['moves_since']);

        $writer->write($rows('192.0.2.62'));
        $this->assertSame(1, (int) $row()['moves']);

        $writer->write($rows('ae1.0'));
        $this->assertSame([2, 2], [(int) $row()['moves'], (int) $row()['moves_recent']]);
        $this->assertSame($moved['moves_since'], $row()['moves_since']);
    }
}
