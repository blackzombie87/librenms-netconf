<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\Sensor;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Collect\SensorWriter;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Support\FixtureReplay;
use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\FakeTransport;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

require_once __DIR__ . '/LibrenmsTestCase.php';
require_once __DIR__ . '/MemoryDatastore.php';

/**
 * A discovery and a poll through the service with recorded replies (plan G6): sensors are
 * created, values recorded into the datastore and the device's sensor rows, the status row
 * reflects the run, and the module's cleanup and dump see the same data.
 */
final class ModuleTest extends LibrenmsTestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->transport] = FixtureReplay::transport(dirname(__DIR__) . '/fixtures/junos', [
            'show system uptime', 'show system commit', 'show krt queue', 'show chassis alarms', 'show system alarms',
            'show route summary', 'show bgp summary', 'show bgp neighbor', 'show ntp associations', 'show ntp status',
            'show system license usage', 'show lacp interfaces', 'show vlans', 'show vrrp summary',
            'show ethernet-switching table summary', 'show interfaces extensive', 'show virtual-chassis status',
            'show ldp session', 'show ldp neighbor', 'show validation session', 'show validation statistics',
        ]);
        $fake = $this->transport;
        $this->app->instance(TransportFactory::class, new class($fake) extends TransportFactory
        {
            public function __construct(private FakeTransport $fake)
            {
            }

            public function make(Credentials $credentials, ?SshClientInterface $client = null): TransportInterface
            {
                return $this->fake;
            }
        });
    }

    public function testDiscoverThenPollThenCleanup(): void
    {
        $device = $this->device();
        $service = NetconfService::make();

        // discovery: sensors are created, nothing is written to the datastore
        $datastore = new MemoryDatastore;
        $discovery = $service->run($device, $datastore, true);

        $this->assertSame([], $discovery->errors);
        $this->assertGreaterThan(10, $discovery->summary['sensors']);
        $this->assertSame([], $datastore->puts);
        $sensors = Sensor::query()->where('device_id', $device->device_id)->where('poller_type', SensorWriter::POLLER_TYPE)->count();
        $this->assertSame($discovery->summary['sensors'], $sensors);
        $this->assertGreaterThan(0, NetconfMetric::query()->where('device_id', $device->device_id)->count());

        // poll: the same sensors are recorded, values land in the datastore and the rows
        $datastore = new MemoryDatastore;
        $poll = $service->run($device, $datastore, false);

        $this->assertSame([], $poll->errors);
        $this->assertSame($sensors, $poll->summary['sensors_recorded']);
        $this->assertCount($sensors, $datastore->fields('sensor'));
        $this->assertNotNull(Sensor::query()->where('device_id', $device->device_id)->where('poller_type', SensorWriter::POLLER_TYPE)->value('sensor_current'));

        // state sensors carry their translations, so the UI shows labels instead of numbers
        $state = Sensor::query()->where('device_id', $device->device_id)->where('poller_type', SensorWriter::POLLER_TYPE)
            ->where('sensor_class', 'state')->firstOrFail();
        $translations = \Illuminate\Support\Facades\DB::table('sensors_to_state_indexes as si')
            ->join('state_translations as st', 'st.state_index_id', '=', 'si.state_index_id')
            ->where('si.sensor_id', $state->sensor_id)->pluck('st.state_descr')->all();
        $this->assertNotEmpty($translations);

        // the status row carries the run
        $status = NetconfDeviceStatus::query()->where('device_id', $device->device_id)->sole();
        $this->assertNull($status->last_error);
        $this->assertSame(0, (int) $status->consecutive_failures);
        $this->assertSame($sensors, $status->last_summary['sensors']);
        $this->assertSame('fake', $status->transport);

        // the module sees the data, dumps it and removes every trace of the device
        $module = new \LibreNMS\Modules\Netconf;
        $this->assertTrue($module->dataExists($device));
        $dump = $module->dump($device, 'netconf');
        $this->assertNotNull($dump);
        $this->assertCount($sensors, $dump['sensors']);
        $this->assertGreaterThan(0, $module->cleanup($device));
        $this->assertFalse($module->dataExists($device));
        $this->assertSame(0, Sensor::query()->where('device_id', $device->device_id)->where('poller_type', SensorWriter::POLLER_TYPE)->count());
        $this->assertSame(0, NetconfMetric::query()->where('device_id', $device->device_id)->count());
        $this->assertSame(0, NetconfDeviceStatus::query()->where('device_id', $device->device_id)->count());
        foreach (TableSchema::writable() as $table) {
            $this->assertSame(0, \Illuminate\Support\Facades\DB::table(TableSchema::tableName($table))->where('device_id', $device->device_id)->count());
        }
    }

    public function testAFailedConnectIsARecordedFailureAndNotAnException(): void
    {
        $device = $this->device();
        $this->transport->connectError = new \SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException('leaf1:22: connection refused');

        $report = NetconfService::make()->run($device, new MemoryDatastore, false);

        $this->assertSame(['leaf1:22: connection refused'], $report->errors);
        $status = NetconfDeviceStatus::query()->where('device_id', $device->device_id)->sole();
        $this->assertSame(1, (int) $status->consecutive_failures);
        $this->assertNotNull($status->next_attempt);
        $this->assertStringContainsString('connection refused', (string) $status->last_error);
        $this->assertSame(0, Sensor::query()->where('device_id', $device->device_id)->where('poller_type', SensorWriter::POLLER_TYPE)->count());
    }

    private function device(): Device
    {
        $device = Device::factory()->create(['os' => 'junos', 'hostname' => 'leaf-module-' . uniqid() . '.example.net']);
        $device->setAttrib(NetconfService::ATTRIB_ENABLED, '1');
        $device->setAttrib('netconf_username', 'librenms');
        $device->setAttrib('netconf_password', 'not-used-by-the-fake');

        return $device;
    }
}
