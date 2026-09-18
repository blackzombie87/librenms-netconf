<?php

use SafferIt\LibrenmsNetconf\Tests\Support\FakeSshClient;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\AuthenticationException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\SshCliTransport;

function cliCredentials(): Credentials
{
    return new Credentials('leaf1', username: 'librenms', password: 'pw', commandTimeout: 12);
}

it('normalizes commands and appends display xml exactly once', function () {
    expect(SshCliTransport::prepare('show   version'))->toBe('show version | display xml')
        ->and(SshCliTransport::prepare('show version | display xml'))->toBe('show version | display xml')
        ->and(SshCliTransport::prepare('show version | display xml | no-more'))->toBe('show version | display xml')
        ->and(SshCliTransport::prepare(' show interfaces extensive et-0/0/0 | no-more '))->toBe('show interfaces extensive et-0/0/0 | display xml');
});

it('connects lazily, runs the command and parses the reply', function () {
    $client = new FakeSshClient;
    $client->execReplies['show route summary | display xml'] = fixture('junos/show-route-summary.xml');
    $transport = new SshCliTransport(cliCredentials(), $client);

    expect($client->connected)->toBeFalse();

    $reply = $transport->run('show route summary');

    expect($client->connected)->toBeTrue()
        ->and($client->executed)->toBe(['show route summary | display xml'])
        ->and($reply->command)->toBe('show route summary')
        ->and($reply->payload()?->localName)->toBe('route-summary-information')
        ->and($transport->sessionInfo()['auth_method'])->toBe('password')
        ->and($transport->sessionInfo()['commands'])->toBe(1);

    $transport->close();
    expect($client->disconnected)->toBeTrue();
});

it('raises rpc errors from the device', function () {
    $client = new FakeSshClient;
    $client->execReplies['show chassis cluster status | display xml'] = fixture('junos/show-chassis-cluster-status-not-enabled.xml');
    $transport = new SshCliTransport(cliCredentials(), $client);

    expect(fn () => $transport->run('show chassis cluster status'))
        ->toThrow(RpcErrorException::class, 'Chassis cluster is not enabled');
});

it('adds stderr to the error when the device did not answer with XML', function () {
    $client = new FakeSshClient;
    $client->execReplies['show version | display xml'] = '';
    $client->stderr = "unknown command: show\n";
    $transport = new SshCliTransport(cliCredentials(), $client);

    expect(fn () => $transport->run('show version'))
        ->toThrow(ProtocolException::class, 'stderr: unknown command: show');
});

it('propagates authentication failures', function () {
    $client = new FakeSshClient;
    $client->acceptMethod = Credentials::AUTH_KEY;
    $transport = new SshCliTransport(cliCredentials(), $client);

    expect(fn () => $transport->run('show version'))->toThrow(AuthenticationException::class);
});

it('propagates timeouts', function () {
    $client = new FakeSshClient;
    $client->timeouts[] = 'show version | display xml';
    $transport = new SshCliTransport(cliCredentials(), $client);

    expect(fn () => $transport->run('show version'))->toThrow(TimeoutException::class);
});

it('does not support raw rpcs', function () {
    $transport = new SshCliTransport(cliCredentials(), new FakeSshClient);

    expect($transport->supportsRpc())->toBeFalse()
        ->and(fn () => $transport->rpc('<get-software-information/>'))->toThrow(TransportException::class);
});
