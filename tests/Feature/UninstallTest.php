<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SafferIt\LibrenmsNetconf\Collect\SensorWriter;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Support\Uninstaller;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * netconf:uninstall on a seeded device (plan G6): the inventory finds every kind of data,
 * the command without --purge changes nothing, and --purge --force removes the rows, the
 * attributes, the RRD files and the tables.
 *
 * The purge runs DDL, which commits the surrounding transaction, so this test restores the
 * schema and removes what it seeded itself.
 */
final class UninstallTest extends LibrenmsTestCase
{
    /** @var list<int> devices seeded by this test, deleted by hand after the implicit commit */
    private array $seeded = [];

    private string $rrdDir = '';

    protected function tearDown(): void
    {
        if ($this->seeded === [] && $this->rrdDir === '') {
            parent::tearDown();   // the bare-checkout stub skipped in setUp: nothing to undo

            return;
        }
        Artisan::call('migrate', ['--force' => true, '--path' => 'vendor/saffer-it/librenms-netconf/database/migrations', '--realpath' => false]);
        if ($this->seeded !== []) {
            DB::table('devices_attribs')->whereIn('device_id', $this->seeded)->delete();
            DB::table('sensors')->whereIn('device_id', $this->seeded)->delete();
            DB::table('devices')->whereIn('device_id', $this->seeded)->delete();
        }
        foreach (glob($this->rrdDir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    public function testInventoryThenPurge(): void
    {
        $device = $this->seedDevice();
        $uninstaller = app(Uninstaller::class);

        $inventory = $uninstaller->inventory();
        $this->assertSame(1, $inventory['sensors']);
        $this->assertSame(1, $inventory['metrics']);
        $this->assertSame(1, $inventory['status']);
        $this->assertGreaterThanOrEqual(2, $inventory['attribs']);
        $this->assertSame([$device->device_id => $device->hostname], $inventory['devices']);
        $this->assertContains('netconf_metrics', $inventory['tables']);
        $this->assertCount(1, $inventory['rrd_files']);
        $this->assertGreaterThan(0, $inventory['migrations']);

        // without --purge nothing changes
        $this->assertSame(0, Artisan::call('netconf:uninstall'));
        $this->assertStringContainsString('Nothing was changed', Artisan::output());
        $this->assertSame(1, DB::table('sensors')->where('poller_type', SensorWriter::POLLER_TYPE)->count());

        // --purge without --force asks and takes "no" for an answer (the test input is empty)
        $this->assertSame(1, Artisan::call('netconf:uninstall', ['--purge' => true]));
        $this->assertStringContainsString('Aborted, nothing was changed', Artisan::output());
        $this->assertTrue(Schema::hasTable('netconf_metrics'));
        $this->assertSame(1, DB::table('sensors')->where('poller_type', SensorWriter::POLLER_TYPE)->count());

        $this->assertSame(0, Artisan::call('netconf:uninstall', ['--purge' => true, '--force' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('1 sensors deleted', $output);
        $this->assertStringContainsString('1 RRD files deleted on 1 devices', $output);
        $this->assertStringContainsString('table netconf_metrics dropped', $output);

        $this->assertSame(0, DB::table('sensors')->where('poller_type', SensorWriter::POLLER_TYPE)->count());
        $this->assertSame(0, DB::table('devices_attribs')->where('device_id', $device->device_id)->where('attrib_type', 'like', 'netconf\_%')->count());
        $this->assertFalse(Schema::hasTable('netconf_metrics'));
        $this->assertFalse(Schema::hasTable(TableSchema::tableName('vni')));
        $this->assertSame(0, DB::table('migrations')->whereIn('migration', Uninstaller::migrationNames())->count());
        $this->assertSame([], glob($this->rrdDir . '/' . $device->hostname . '/*'));
    }

    private function seedDevice(): Device
    {
        $device = Device::factory()->create(['os' => 'junos', 'hostname' => 'leaf-uninstall-' . uniqid() . '.example.net']);
        $this->seeded[] = $device->device_id;
        $device->setAttrib('netconf_enabled', '1');
        $device->setAttrib('netconf_username', 'librenms');

        DB::table('sensors')->insert([
            'poller_type' => SensorWriter::POLLER_TYPE, 'sensor_class' => 'count', 'device_id' => $device->device_id,
            'sensor_oid' => SensorWriter::DUMMY_OID, 'sensor_index' => 'total', 'sensor_type' => 'netconf-junos-evpn-instances',
            'sensor_descr' => 'EVPN instances', 'sensor_divisor' => 1, 'sensor_multiplier' => 1, 'sensor_current' => 3,
        ]);
        DB::table('netconf_metrics')->insert([
            'device_id' => $device->device_id, 'definition' => 'junos-routing', 'mapping' => 'bgp-peer', 'metric_index' => '192.0.2.1',
            'descr' => 'BGP peer', 'values' => '{"flaps":0}', 'labels' => '{}', 'last_seen' => now(),
        ]);
        DB::table('netconf_device_status')->insert(['device_id' => $device->device_id, 'transport' => 'cli', 'poll_count' => 1, 'consecutive_failures' => 0]);
        DB::table(TableSchema::tableName('vni'))->insert(['device_id' => $device->device_id, 'vni' => 10010, 'instance' => 'MACVRF-A', 'source_vtep' => '192.0.2.1', 'last_seen' => now()]);

        // an RRD file of this device, named the way the writers name theirs
        $this->rrdDir = rtrim((string) \App\Facades\LibrenmsConfig::get('rrd_dir'), '/');
        @mkdir($this->rrdDir . '/' . $device->hostname, 0777, true);
        touch($this->rrdDir . '/' . $device->hostname . '/netconf-junos-routing-bgp-peer-192.0.2.1.rrd');

        return $device;
    }
}
