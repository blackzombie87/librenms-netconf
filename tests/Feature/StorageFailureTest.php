<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
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

/**
 * The status row of a run whose collection succeeded but whose storage threw (F6 1): the
 * failure counter continues the streak the run found, `last_ok` still names the last run
 * that stored anything, and a fabric resolve that already committed — clearing
 * member-not-polling because the status row said the poll was healthy — is run again.
 */
final class StorageFailureTest extends LibrenmsTestCase
{
    private FakeTransport $transport;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netconf.evpn_fabric' => true]);
        $this->dir = sys_get_temp_dir() . '/netconf-storage-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/test.yaml', <<<'YAML'
            name: test-evpn
            match: { os: junos }
            commands:
              vni: show vni
            sensors:
              - id: vni-count
                class: count
                command: vni
                index: "'total'"
                descr: VNIs
                value: count(//v)
            metrics:
              - id: vni-rows
                command: vni
                rows: //v
                index: string(id)
                descr: VNI {index}
                fields: { vni: number(id) }
            tables:
              - id: vni
                table: vni
                command: vni
                rows: //v
                columns: { vni: number(id), instance: string(inst), source_vtep: string(src) }
            YAML);
        $this->app->instance(DefinitionLoader::class, new DefinitionLoader([$this->dir]));

        $this->transport = (new FakeTransport)->on('show vni', '<rpc-reply><t><v><id>10010</id><inst>MACVRF-A</inst><src>192.0.2.71</src></v></t></rpc-reply>');
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
        if (isset($this->dir)) {
            @unlink($this->dir . '/test.yaml');
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testAThrowBeforeTheResolveKeepsLastOkAndContinuesTheFailureStreak(): void
    {
        $leaf = $this->leaf();
        $service = NetconfService::make();
        $service->run($leaf, null, true);

        // two failures since, and the last successful run is the discovery above
        NetconfDeviceStatus::query()->where('device_id', $leaf->device_id)
            ->update(['consecutive_failures' => 2, 'next_attempt' => now()->subMinute(), 'last_ok' => now()->subHours(2)]);
        FabricResolver::make()->run();
        $this->assertSame(1, $this->openIssues());
        $lastOk = $this->statusRow($leaf)->last_ok;
        $this->assertNotNull($lastOk);

        // the metric writer throws: the whole run is a failure, counted from the streak it found
        $report = $service->run($leaf, new ThrowingDatastore('netconf'), false);
        $this->assertStringContainsString('storing the results failed: no room for this metric', implode('; ', $report->errors));

        $status = $this->statusRow($leaf);
        $this->assertSame($lastOk->toDateTimeString(), $status->last_ok?->toDateTimeString());
        $this->assertSame(3, (int) $status->consecutive_failures);
        $this->assertNotNull($status->next_attempt);
        $this->assertGreaterThan(now()->addSeconds(60)->timestamp, $status->next_attempt->timestamp);   // the third step, not the first
        $this->assertSame(1, $this->openIssues());
    }

    public function testAThrowAfterTheResolveReopensTheMemberIssue(): void
    {
        $leaf = $this->leaf();
        $service = NetconfService::make();
        $service->run($leaf, null, true);

        NetconfDeviceStatus::query()->where('device_id', $leaf->device_id)
            ->update(['consecutive_failures' => 1, 'next_attempt' => now()->subMinute(), 'last_ok' => now()->subHours(2)]);
        FabricResolver::make()->run();
        $this->assertSame(1, $this->openIssues());
        $lastOk = $this->statusRow($leaf)->last_ok;

        // the issues sensor is written after the resolve, so the resolve committed with a status
        // row that said "healthy" and cleared the member issue before this throw
        $report = $service->run($leaf, new ThrowingDatastore(IssueSensor::TYPE), false);
        $this->assertStringContainsString('storing the results failed', implode('; ', $report->errors));

        $this->assertSame(1, $this->openIssues());   // re-opened by the second resolve
        $status = $this->statusRow($leaf);
        $this->assertSame($lastOk?->toDateTimeString(), $status->last_ok?->toDateTimeString());
        $this->assertSame(2, (int) $status->consecutive_failures);
    }

    public function testASuccessfulRunMovesLastOkOnlyWhenStorageIsThrough(): void
    {
        $leaf = $this->leaf();
        $service = NetconfService::make();
        $service->run($leaf, null, true);
        $status = $this->statusRow($leaf);
        $this->assertNotNull($status->last_ok);
        $this->assertSame(0, (int) $status->consecutive_failures);
        $this->assertNotNull($status->last_summary['table_rows'] ?? null);
    }

    private function openIssues(): int
    {
        return DB::table(IssueStore::TABLE)->where('check', 'member-not-polling')->count();
    }

    private function statusRow(Device $device): NetconfDeviceStatus
    {
        return NetconfDeviceStatus::query()->where('device_id', $device->device_id)->sole();
    }

    private function leaf(): Device
    {
        $device = Device::factory()->create(['os' => 'junos', 'hostname' => 'leaf-storage-' . uniqid() . '.example.net', 'status' => 1]);
        $device->setAttrib(NetconfService::ATTRIB_ENABLED, '1');
        $device->setAttrib('netconf_username', 'librenms');
        $device->setAttrib('netconf_password', 'not-used-by-the-fake');

        return $device;
    }
}

/*
 * A datastore that throws for one measurement or sensor type, so a test can pick the point in
 * store() where the writers fail: 'netconf' is the metric write (before the fabric resolve),
 * the issues sensor type is the very last put of a poll (after it).
 */
if (interface_exists(DataStorageInterface::class)) {
final class ThrowingDatastore implements DataStorageInterface
{
    public function __construct(private string $throwOn)
    {
    }

    public function put($device, $measurement, $tags, $fields): void
    {
        if ($measurement === $this->throwOn || ($tags['sensor_type'] ?? null) === $this->throwOn) {
            throw new \RuntimeException('no room for this metric');
        }
    }
}
}
