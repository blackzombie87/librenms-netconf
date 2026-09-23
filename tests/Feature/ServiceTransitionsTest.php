<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\Sensor;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueSensor;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueStore;
use SafferIt\LibrenmsNetconf\Fabric\FabricResolver;
use SafferIt\LibrenmsNetconf\Models\NetconfDeviceStatus;
use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\FakeTransport;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

require_once __DIR__ . '/LibrenmsTestCase.php';
require_once __DIR__ . '/MemoryDatastore.php';

/**
 * Check transitions through the whole service (F5 3): the fabric checks read this run's
 * sensor values and this run's outcome, not the previous poll's. A duplicate-MAC issue is
 * open exactly while the sensor is > 0, the border role follows the l3-contexts sensor of
 * the same poll, and member-not-polling clears on the first poll that succeeds.
 */
final class ServiceTransitionsTest extends LibrenmsTestCase
{
    private FakeTransport $transport;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netconf.evpn_fabric' => true]);
        $this->dir = sys_get_temp_dir() . '/netconf-transitions-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/test.yaml', <<<'YAML'
            name: test-evpn
            match: { os: junos }
            commands:
              dup: show evpn database state duplicate
              l3: show evpn l3-context
              vni: show vni
            sensors:
              - id: dup-mac-instance
                class: count
                command: dup
                rows: //evpn-database-instance
                index: string(instance-name)
                descr: 'EVPN duplicate MACs {index}'
                value: count(mac-entry)
                limit: 0
              - id: l3-contexts
                class: count
                command: l3
                index: "'total'"
                descr: L3 contexts
                value: count(//ctx)
            tables:
              - id: vni
                table: vni
                command: vni
                rows: //v
                columns: { vni: number(id), instance: string(inst), source_vtep: string(src) }
            YAML);
        $this->app->instance(DefinitionLoader::class, new DefinitionLoader([$this->dir]));

        $this->transport = new FakeTransport;
        $this->replies(0, 0);
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

    protected function tearDown(): void
    {
        if (isset($this->dir)) {   // not set when the bare-checkout stub skipped setUp()
            @unlink($this->dir . '/test.yaml');
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testDuplicateMacIssueIsOpenExactlyWhileTheSensorCounts(): void
    {
        $leaf = $this->leaf();
        $service = NetconfService::make();
        $sensor = fn () => (float) Sensor::query()->where('device_id', $leaf->device_id)->where('sensor_type', 'netconf-test-evpn-dup-mac-instance')->value('sensor_current');
        $issues = fn () => DB::table(IssueStore::TABLE)->where('check', 'dup-mac')->count();
        $issuesSensor = fn () => (float) Sensor::query()->where('device_id', $leaf->device_id)->where('sensor_type', IssueSensor::TYPE)->value('sensor_current');

        // discovery: no duplicates; the leaf becomes a member and gets its issues sensor in the same run
        $report = $service->run($leaf, null, true);
        $this->assertSame([], $report->errors);
        $this->assertSame([0.0, 0], [$sensor(), $issues()]);
        $this->assertTrue(IssueSensor::exists($leaf));
        $this->assertSame(3, $report->summary['sensors']);   // dup-mac-instance, l3-contexts, fabric issues

        // poll: two suppressed MACs -> the issue opens on this poll, the issues sensor counts it
        $this->replies(2, 0);
        $report = $service->run($leaf, new MemoryDatastore, false);
        $this->assertSame([], $report->errors);
        $this->assertSame([2.0, 1, 1.0], [$sensor(), $issues(), $issuesSensor()]);
        $this->assertSame(3, $report->summary['sensors_recorded']);

        // next poll: none -> the issue is gone on this poll, with its "cleared" eventlog entry
        $this->replies(0, 0);
        $service->run($leaf, new MemoryDatastore, false);
        $this->assertSame([0.0, 0, 0.0], [$sensor(), $issues(), $issuesSensor()]);
        $this->assertSame(1, DB::table('eventlog')->where('type', IssueStore::EVENT_TYPE)->where('message', 'like', 'EVPN fabric check dup-mac cleared:%')->count());
    }

    public function testASensorIndexThatFirstAppearsOnAPollIsSeenByThatPollsChecks(): void
    {
        $leaf = $this->leaf();
        $service = NetconfService::make();
        $issues = fn () => DB::table(IssueStore::TABLE)->where('check', 'dup-mac')->count();
        $sensors = fn () => Sensor::query()->where('device_id', $leaf->device_id)->where('sensor_type', 'netconf-test-evpn-dup-mac-instance');

        // discovery: the command answers no instance at all, so the index does not exist yet
        $this->transport->on('show evpn database state duplicate', '<rpc-reply><evpn-database-information/></rpc-reply>');
        $service->run($leaf, null, true);
        $this->assertSame(0, $sensors()->count());

        // poll: the instance appears with two suppressed MACs. The row is created and its value
        // recorded before the fabric block, so the check of this very poll counts them (F6 4)
        $this->replies(2, 0);
        $report = $service->run($leaf, new MemoryDatastore, false);
        $this->assertSame([], $report->errors);
        $this->assertSame(2.0, (float) $sensors()->value('sensor_current'));
        $this->assertSame(1, $issues());
        $this->assertSame(1.0, (float) Sensor::query()->where('device_id', $leaf->device_id)->where('sensor_type', IssueSensor::TYPE)->value('sensor_current'));
    }

    public function testBorderRoleFollowsTheL3ContextSensorOfTheSamePoll(): void
    {
        $leaf = $this->leaf();
        $service = NetconfService::make();
        $border = fn () => (int) DB::table(TableSchema::tableName('vtep'))->where('vtep_ip', '192.0.2.61')->value('border');

        $service->run($leaf, null, true);
        $this->assertSame(0, $border());

        $this->replies(0, 1);
        $service->run($leaf, new MemoryDatastore, false);
        $this->assertSame(1, $border());

        $this->replies(0, 0);
        $service->run($leaf, new MemoryDatastore, false);
        $this->assertSame(0, $border());
    }

    public function testMemberNotPollingClearsOnTheFirstGoodPoll(): void
    {
        $leaf = $this->leaf();
        $service = NetconfService::make();
        $service->run($leaf, null, true);

        // the collector failed twice since: the resolve of another leaf flags the member
        NetconfDeviceStatus::query()->where('device_id', $leaf->device_id)->update(['consecutive_failures' => 2, 'next_attempt' => now()->subMinute()]);
        FabricResolver::make()->run();
        $open = fn () => DB::table(IssueStore::TABLE)->where('check', 'member-not-polling')->count();
        $this->assertSame(1, $open());

        // this poll succeeds: the status row says so before the resolve, so the issue clears now
        $report = $service->run($leaf, new MemoryDatastore, false);
        $this->assertSame([], $report->errors);
        $this->assertSame(0, $open());
        $status = NetconfDeviceStatus::query()->where('device_id', $leaf->device_id)->sole();
        $this->assertSame(0, (int) $status->consecutive_failures);
        $this->assertSame(3, $status->last_summary['sensors']);
    }

    private function replies(int $duplicates, int $l3Contexts): void
    {
        $entries = str_repeat('<mac-entry><mac-address>02:00:00:00:00:01</mac-address></mac-entry>', $duplicates);
        $this->transport
            ->on('show evpn database state duplicate', "<rpc-reply><evpn-database-information><evpn-database-instance><instance-name>MACVRF-A</instance-name>$entries</evpn-database-instance></evpn-database-information></rpc-reply>")
            ->on('show evpn l3-context', '<rpc-reply><l3>' . str_repeat('<ctx/>', $l3Contexts) . '</l3></rpc-reply>')
            ->on('show vni', '<rpc-reply><t><v><id>10010</id><inst>MACVRF-A</inst><src>192.0.2.61</src></v></t></rpc-reply>');
    }

    private function leaf(): Device
    {
        $device = Device::factory()->create(['os' => 'junos', 'hostname' => 'leaf-transitions-' . uniqid() . '.example.net', 'status' => 1]);
        $device->setAttrib(NetconfService::ATTRIB_ENABLED, '1');
        $device->setAttrib('netconf_username', 'librenms');
        $device->setAttrib('netconf_password', 'not-used-by-the-fake');

        return $device;
    }
}
