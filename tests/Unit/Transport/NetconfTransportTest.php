<?php

use SafferIt\LibrenmsNetconf\Tests\Support\FakeChannel;
use SafferIt\LibrenmsNetconf\Tests\Support\FakeSshClient;
use SafferIt\LibrenmsNetconf\Tests\Support\NetconfServerScript;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;
use SafferIt\LibrenmsNetconf\Transport\Framing\ChunkedFramer;
use SafferIt\LibrenmsNetconf\Transport\NetconfTransport;

function netconfCredentials(): Credentials
{
    return new Credentials('leaf1', 830, Credentials::TRANSPORT_NETCONF, 'librenms', 'pw', commandTimeout: 3);
}

function netconfSetup(NetconfServerScript $server): array
{
    $client = new FakeSshClient;
    $client->channel = $server->channel();
    $transport = new NetconfTransport(netconfCredentials(), $client);

    return [$transport, $client, $client->channel];
}

it('negotiates chunked framing when the server supports base:1.1', function () {
    $server = new NetconfServerScript;
    $server->replies['show version'] = '<software-information><host-name>leaf1</host-name><product-model>ex4650-48y</product-model><junos-version>23.4R2-S3.9</junos-version></software-information>';
    [$transport, $client, $channel] = netconfSetup($server);

    $reply = $transport->run('show version');

    expect($reply->text('host-name'))->toBe('leaf1')
        ->and($reply->text('junos-version'))->toBe('23.4R2-S3.9')
        ->and($channel->written[0])->toContain('<hello')
        ->and($channel->written[0])->toContain('base:1.1')
        ->and($channel->written[0])->toEndWith("]]>]]>\n")
        ->and($channel->written[1])->toStartWith("\n#")
        ->and($channel->written[1])->toContain('<command format="xml">show version</command>')
        ->and($server->receivedRpcs[0])->toContain('message-id="1"');

    $info = $transport->sessionInfo();
    expect($info['framing'])->toBe('chunked (1.1)')
        ->and($info['session_id'])->toBe('4711')
        ->and($info['base_versions'])->toBe('1.0, 1.1')
        ->and($info['auth_method'])->toBe('password');

    $transport->close();
    expect($channel->closed)->toBeTrue()
        ->and($client->disconnected)->toBeTrue()
        ->and(end($server->receivedRpcs))->toContain('<close-session/>');
});

it('falls back to end-of-message framing for base:1.0 only servers', function () {
    $server = new NetconfServerScript(['urn:ietf:params:netconf:base:1.0', 'http://xml.juniper.net/netconf/junos/1.0']);
    $server->replies['show version'] = '<software-information><host-name>old</host-name></software-information>';
    [$transport, , $channel] = netconfSetup($server);

    $reply = $transport->run('show version');

    expect($reply->text('host-name'))->toBe('old')
        ->and($channel->written[1])->toEndWith("]]>]]>\n")
        ->and($transport->sessionInfo()['framing'])->toBe('end-of-message (1.0)');
});

it('correlates message ids across several requests', function () {
    $server = new NetconfServerScript;
    $server->replies['show a'] = '<a/>';
    $server->replies['show b'] = '<b/>';
    [$transport] = netconfSetup($server);

    expect($transport->run('show a')->payload()?->localName)->toBe('a')
        ->and($transport->run('show b')->payload()?->localName)->toBe('b')
        ->and($server->receivedRpcs[1])->toContain('message-id="2"')
        ->and($transport->sessionInfo()['messages'])->toBe(2);
});

it('sends raw rpcs and escapes cli commands', function () {
    $server = new NetconfServerScript;
    [$transport] = netconfSetup($server);

    expect($transport->supportsRpc())->toBeTrue();

    try {
        $transport->run('show interfaces "et-0/0/1" & co');
    } catch (RpcErrorException) {
        // unknown to the script, we only care about the wire format
    }
    expect($server->receivedRpcs[0])->toContain('<command format="xml">show interfaces &quot;et-0/0/1&quot; &amp; co</command>');

    try {
        $transport->rpc('<get-software-information/>');
    } catch (RpcErrorException) {
    }
    expect($server->receivedRpcs[1])->toContain('<rpc message-id="2" xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><get-software-information/></rpc>');
});

it('raises Junos xnm:error replies as RpcErrorException', function () {
    $server = new NetconfServerScript;
    [$transport] = netconfSetup($server);

    expect(fn () => $transport->run('show nonsense'))
        ->toThrow(RpcErrorException::class, 'show nonsense: error: unknown command: show nonsense');
});

it('raises NETCONF rpc-error replies as RpcErrorException', function () {
    $server = new NetconfServerScript;
    $server->replies['show x'] = '<rpc-error><error-tag>access-denied</error-tag><error-message>permission denied</error-message></rpc-error>';
    [$transport] = netconfSetup($server);

    expect(fn () => $transport->run('show x'))->toThrow(RpcErrorException::class, 'permission denied access-denied');
});

it('rejects a mismatched message-id', function () {
    $client = new FakeSshClient;
    $server = new NetconfServerScript;
    $channel = new FakeChannel($server->hello(), function (string $written) {
        if (str_contains($written, '<hello')) {
            return null;
        }

        return (new ChunkedFramer)->encode('<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="99"><ok/></rpc-reply>');
    });
    $client->channel = $channel;
    $transport = new NetconfTransport(netconfCredentials(), $client);

    expect(fn () => $transport->run('show version'))->toThrow(ProtocolException::class, 'expected message-id 1, got 99');
});

it('times out when the server never completes a frame', function () {
    $client = new FakeSshClient;
    $server = new NetconfServerScript;
    $client->channel = new FakeChannel($server->hello(), fn (string $w) => str_contains($w, '<hello') ? null : "\n#500\n<rpc-reply>partial");
    $transport = new NetconfTransport(netconfCredentials(), $client);

    expect(fn () => $transport->run('show version'))->toThrow(TimeoutException::class, 'no complete NETCONF reply to "show version" within 3s');
});

it('reassembles frames delivered in small fragments', function () {
    $server = new NetconfServerScript;
    $server->replies['show version'] = '<software-information><host-name>' . str_repeat('x', 300) . '</host-name></software-information>';
    $channel = $server->channel();
    $channel->fragment = 7;
    $client = new FakeSshClient;
    $client->channel = $channel;
    $transport = new NetconfTransport(netconfCredentials(), $client);

    expect(strlen((string) $transport->run('show version')->text('host-name')))->toBe(300);
});

it('fails when the server does not send a hello', function () {
    $client = new FakeSshClient;
    $client->channel = new FakeChannel('<rpc-reply><ok/></rpc-reply>]]>]]>');
    $transport = new NetconfTransport(netconfCredentials(), $client);

    expect(fn () => $transport->connect())->toThrow(ProtocolException::class, 'expected NETCONF <hello>');
});

it('fails when no supported base capability is advertised', function () {
    $server = new NetconfServerScript(['urn:ietf:params:netconf:base:2.0']);
    [$transport] = netconfSetup($server);

    expect(fn () => $transport->connect())->toThrow(ProtocolException::class, 'neither NETCONF base:1.0 nor base:1.1');
});
