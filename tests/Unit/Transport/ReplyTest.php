<?php

use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException;
use SafferIt\LibrenmsNetconf\Transport\Reply;

it('detects the Junos xnm:error shape from a real fixture', function () {
    $reply = new Reply('show chassis cluster status', fixture('junos/show-chassis-cluster-status-not-enabled.xml'));

    expect($reply->hasError())->toBeTrue()
        ->and($reply->errors())->toBe(['error: Chassis cluster is not enabled']);

    expect(fn () => $reply->assertOk())
        ->toThrow(RpcErrorException::class, 'show chassis cluster status: error: Chassis cluster is not enabled');
});

it('detects NETCONF rpc-error', function () {
    $xml = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="3">'
        . '<rpc-error><error-type>protocol</error-type><error-tag>operation-not-supported</error-tag>'
        . '<error-severity>error</error-severity><error-message>syntax error</error-message>'
        . '<error-info><bad-element>show foo</bad-element></error-info></rpc-error></rpc-reply>';

    $reply = new Reply('show foo', $xml);

    expect($reply->errors())->toBe(['syntax error operation-not-supported show foo']);
});

it('passes a successful reply through assertOk', function () {
    $reply = new Reply('show route summary', fixture('junos/show-route-summary.xml'));

    expect($reply->hasError())->toBeFalse()
        ->and($reply->assertOk())->toBe($reply)
        ->and($reply->payload()?->localName)->toBe('route-summary-information');
});

it('skips the cli banner when locating the payload', function () {
    $reply = new Reply('show evpn database state duplicate', fixture('junos/show-evpn-database-state-duplicate-empty.xml'));

    expect($reply->payload()?->localName)->toBe('evpn-database-information');
});

it('extracts text by local name regardless of namespaces', function () {
    $reply = new Reply('show route summary', fixture('junos/show-route-summary.xml'));

    expect($reply->text('router-id'))->not->toBeNull()
        ->and($reply->text('does-not-exist', 'as-number'))->not->toBeNull()
        ->and($reply->text('does-not-exist'))->toBeNull();
});

it('strips non-xml noise from cli exec output', function () {
    $output = "warning: something\n<rpc-reply><software-information><host-name>leaf1</host-name></software-information><cli><banner>{master:0}</banner></cli></rpc-reply>\n{master:0}\n";

    $reply = Reply::fromCliOutput('show version', $output, 0.1);

    expect($reply->raw)->toStartWith('<rpc-reply>')
        ->and($reply->raw)->toEndWith('</rpc-reply>')
        ->and($reply->text('host-name'))->toBe('leaf1')
        ->and($reply->duration)->toBe(0.1);
});

it('fails clearly when the device returned no xml at all', function () {
    expect(fn () => Reply::fromCliOutput('show version', "error: permission denied\n"))
        ->toThrow(ProtocolException::class, 'did not return XML');
});

it('fails clearly on truncated xml', function () {
    $reply = new Reply('show version', '<rpc-reply><software-information>');

    expect(fn () => $reply->dom())->toThrow(ProtocolException::class, 'not well-formed');
});

it('pretty prints', function () {
    $reply = new Reply('x', '<a><b>1</b></a>');

    expect($reply->pretty())->toBe("<?xml version=\"1.0\"?>\n<a>\n  <b>1</b>\n</a>\n");
});
