<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use SafferIt\LibrenmsNetconf\Console\NetconfRunCommand;
use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;
use SafferIt\LibrenmsNetconf\Transport\FakeTransport;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * The interactive callers (device test button, status-page run form, netconf:test and
 * netconf:run) close the SSH session on every path, not only on success (F4a 2); netconf:run
 * retries once with a fresh connection when the device's SSH rate limit closes the socket.
 */
final class SessionHygieneTest extends LibrenmsTestCase
{
    private FakeTransport $transport;

    /** @var list<FakeTransport> every transport the stubbed factory handed out */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport(['show version' => '<software-information><host-name>leaf1</host-name><product-model>ex4650</product-model><junos-version>23.4R2</junos-version></software-information>']);
        $test = $this;
        $this->app->instance(TransportFactory::class, new class($test) extends TransportFactory
        {
            public function __construct(private SessionHygieneTest $test)
            {
            }

            public function make(Credentials $credentials, ?SshClientInterface $client = null): TransportInterface
            {
                return $this->test->handOut();
            }
        });
        NetconfRunCommand::$retryDelay = 0;
    }

    protected function tearDown(): void
    {
        NetconfRunCommand::$retryDelay = 2;
        parent::tearDown();
    }

    public function handOut(): FakeTransport
    {
        // the same scripted transport every time, so a retry shows up as a second connect
        $this->made[] = $this->transport;

        return $this->transport;
    }

    public function testDeviceTestButtonClosesAfterATimeout(): void
    {
        $device = $this->device();
        $this->transport->runErrors = [new TimeoutException('leaf1: no complete answer to "show version" within 30s')];

        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $this->post("/plugin/netconf/device/{$device->device_id}/test")->assertRedirect("/device/{$device->device_id}/netconf");

        $this->assertSame('danger', session('netconf_result')['type']);
        $this->assertFalse($this->transport->connected);
        $this->assertSame(1, $this->transport->closes);
    }

    public function testRunFormClosesAfterADeniedCommand(): void
    {
        $device = $this->device();

        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        // no reply registered: the fake behaves like a device that rejects the command
        $this->post('/plugin/netconf/run', ['device_id' => $device->device_id, 'command' => 'show chassis hardware'])->assertRedirect('/plugin/netconf/status');

        $this->assertFalse(session('netconf_run')['ok']);
        $this->assertFalse($this->transport->connected);
        $this->assertSame(1, $this->transport->closes);

        $this->post('/plugin/netconf/run', ['device_id' => $device->device_id, 'command' => 'show version'])->assertRedirect('/plugin/netconf/status');
        $this->assertTrue(session('netconf_run')['ok']);
        $this->assertSame(2, $this->transport->closes);
    }

    public function testNetconfTestClosesWhenTheHelloFailsAfterTheLogin(): void
    {
        $device = $this->device();
        $this->transport->connectError = new ProtocolException('leaf1: expected NETCONF <hello>');

        $this->assertSame(1, Artisan::call('netconf:test', ['device' => (string) $device->device_id]));

        $this->assertFalse($this->transport->connected);
        $this->assertSame(1, $this->transport->closes);
    }

    public function testNetconfTestClosesAfterAFailedCommand(): void
    {
        $device = $this->device();
        $this->transport->runErrors = [new TimeoutException('leaf1: no complete answer to "show version" within 30s')];

        $this->assertSame(1, Artisan::call('netconf:test', ['device' => (string) $device->device_id]));

        $this->assertFalse($this->transport->connected);
        $this->assertSame(1, $this->transport->closes);
    }

    public function testNetconfRunRetriesOnceOnTheRateLimitErrorAndClosesBothSessions(): void
    {
        $device = $this->device();
        $this->transport->runErrors = [new ConnectionException('leaf1:22: Error reading SSH identification string')];

        $this->assertSame(0, Artisan::call('netconf:run', ['device' => (string) $device->device_id, 'cmd' => ['show', 'version']]));

        $this->assertSame(['show version', 'show version'], $this->transport->executed);
        $this->assertCount(2, $this->made);
        $this->assertSame(2, $this->transport->closes);
        $this->assertFalse($this->transport->connected);

        // any other failure is final, and still closed
        $this->transport->runErrors = [new TimeoutException('leaf1: timeout')];
        $this->assertSame(1, Artisan::call('netconf:run', ['device' => (string) $device->device_id, 'cmd' => ['show', 'version']]));
        $this->assertSame(3, $this->transport->closes);

        // the rate limit twice in a row gives up after the retry
        $this->transport->runErrors = [new ConnectionException('x: Error reading SSH identification string'), new ConnectionException('x: Error reading SSH identification string')];
        $this->assertSame(1, Artisan::call('netconf:run', ['device' => (string) $device->device_id, 'cmd' => ['show', 'version']]));
        $this->assertSame(5, $this->transport->closes);
        $this->assertFalse($this->transport->connected);
    }

    private function device(): Device
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $device->setAttrib('netconf_username', 'librenms');
        $device->setAttrib('netconf_password', 'not-used-by-the-fake');

        return $device;
    }
}
