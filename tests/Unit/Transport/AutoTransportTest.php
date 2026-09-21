<?php

use SafferIt\LibrenmsNetconf\Tests\Support\FakeSshClient;
use SafferIt\LibrenmsNetconf\Tests\Support\NetconfServerScript;
use SafferIt\LibrenmsNetconf\Transport\AutoTransport;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\AuthenticationException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;
use SafferIt\LibrenmsNetconf\Transport\NetconfTransport;
use SafferIt\LibrenmsNetconf\Transport\SshCliTransport;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

function autoCredentials(): Credentials
{
    return new Credentials('leaf1', username: 'librenms', password: 'pw', commandTimeout: 3);
}

it('is what the factory builds for the default transport', function () {
    $factory = new TransportFactory;

    expect(autoCredentials()->transport)->toBe('auto')
        ->and($factory->make(autoCredentials(), new FakeSshClient))->toBeInstanceOf(AutoTransport::class)
        ->and($factory->make(autoCredentials()->with(['transport' => 'cli']), new FakeSshClient))->toBeInstanceOf(SshCliTransport::class)
        ->and($factory->make(autoCredentials()->with(['transport' => 'netconf']), new FakeSshClient))->toBeInstanceOf(NetconfTransport::class);
});

it('uses the netconf subsystem when the server offers it, with a single login', function () {
    $server = new NetconfServerScript;
    $server->replies['show version'] = '<software-information><host-name>leaf1</host-name></software-information>';
    $client = new FakeSshClient;
    $client->channel = $server->channel();
    $transport = new AutoTransport(autoCredentials(), $client);

    expect($transport->name())->toBe('auto');

    $reply = $transport->run('show version');

    expect($reply->text('host-name'))->toBe('leaf1')
        ->and($transport->name())->toBe('netconf')
        ->and($transport->supportsRpc())->toBeTrue()
        ->and($transport->fallbackReason())->toBeNull()
        ->and($client->connects)->toBe(1)
        ->and($client->subsystems)->toBe(['netconf'])
        ->and($client->executed)->toBe([])
        ->and($transport->sessionInfo()['transport'])->toBe('netconf (auto)')
        ->and($transport->sessionInfo()['auth_method'])->toBe('password')
        ->and($transport->sessionInfo()['framing'])->toBe('chunked (1.1)');

    $transport->close();
    expect($client->disconnected)->toBeTrue()
        ->and(end($server->receivedRpcs))->toContain('<close-session/>');
});

it('falls back to exec on the same connection when the subsystem is refused', function () {
    $client = new FakeSshClient;
    $client->subsystemError = new ConnectionException('leaf1:22: subsystem "netconf" refused');
    $client->execReplies['show version | display xml'] = fixture('junos/show-version-multi-re.xml');
    $transport = new AutoTransport(autoCredentials(), $client);

    $reply = $transport->run('show version');

    expect($reply->text('host-name'))->toBe('leaf1')
        ->and($transport->name())->toBe('cli')
        ->and($transport->supportsRpc())->toBeFalse()
        ->and($transport->fallbackReason())->toContain('refused')
        ->and($client->connects)->toBe(1)
        ->and($client->subsystems)->toBe(['netconf'])
        ->and($client->executed)->toBe(['show version | display xml'])
        ->and($transport->sessionInfo()['transport'])->toBe('cli (auto)')
        ->and($transport->sessionInfo()['netconf_subsystem'])->toContain('refused')
        ->and($transport->sessionInfo()['auth_method'])->toBe('password');

    $transport->close();
    expect($client->disconnected)->toBeTrue();
});

it('does not swallow authentication failures', function () {
    $client = new FakeSshClient;
    $client->acceptMethod = Credentials::AUTH_KEY;
    $transport = new AutoTransport(autoCredentials(), $client);

    expect(fn () => $transport->connect())->toThrow(AuthenticationException::class)
        ->and($transport->name())->toBe('auto')
        ->and($client->subsystems)->toBe([]);
});

it('hangs up on close() when the login succeeded but no inner transport was assigned', function () {
    // a timeout while opening the subsystem is not a refusal: connect() throws with SSH up
    $client = new FakeSshClient;
    $client->subsystemError = new TimeoutException('leaf1:22: no answer to the subsystem request');
    $transport = new AutoTransport(autoCredentials(), $client);

    expect(fn () => $transport->connect())->toThrow(TimeoutException::class)
        ->and($client->connected)->toBeTrue()
        ->and($client->disconnected)->toBeFalse();

    $transport->close();
    expect($client->disconnected)->toBeTrue();
});

it('hangs up on close() when the netconf hello failed after the login', function () {
    $client = new FakeSshClient;
    $client->channel = new \SafferIt\LibrenmsNetconf\Tests\Support\FakeChannel('not a hello]]>]]>');
    $transport = new AutoTransport(autoCredentials(), $client);

    expect(fn () => $transport->connect())->toThrow(ProtocolException::class)
        ->and($client->disconnected)->toBeFalse();

    $transport->close();
    expect($client->disconnected)->toBeTrue();
});

it('does nothing on close() before connect()', function () {
    $client = new FakeSshClient;
    (new AutoTransport(autoCredentials(), $client))->close();

    expect($client->disconnected)->toBeFalse();
});
