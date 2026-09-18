<?php

use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Framing\ChunkedFramer;

it('encodes a message as a single chunk', function () {
    $framer = new ChunkedFramer;

    expect($framer->encode('<rpc/>'))->toBe("\n#6\n<rpc/>\n##\n");
    expect($framer->readDelimiter())->toBe("\n##\n");
});

it('decodes the RFC 6242 example with several chunks', function () {
    $framer = new ChunkedFramer;
    $buffer = "\n#4\n<rpc\n#18\n message-id=\"102\"\n\n#79\n     xmlns=\"urn:ietf:params:xml:ns:netconf:base:1.0\">\n  <close-session/>\n</rpc>\n##\n";

    $message = $framer->decode($buffer);

    expect($message)->toStartWith('<rpc message-id="102"')
        ->and($message)->toEndWith('</rpc>')
        ->and($buffer)->toBe('');
});

it('round-trips a payload that contains the end marker', function () {
    $framer = new ChunkedFramer;
    $payload = "<a>\n##\n</a>";
    $buffer = $framer->encode($payload) . $framer->encode('<b/>');

    expect($framer->decode($buffer))->toBe($payload);
    expect($framer->decode($buffer))->toBe('<b/>');
    expect($buffer)->toBe('');
});

it('returns null until the whole chunk has arrived', function () {
    $framer = new ChunkedFramer;
    $full = $framer->encode('<abcdef/>');

    for ($i = 1; $i < strlen($full); $i++) {
        $partial = substr($full, 0, $i);
        expect($framer->decode($partial))->toBeNull();
    }

    $buffer = $full;
    expect($framer->decode($buffer))->toBe('<abcdef/>');
});

it('tolerates whitespace between frames', function () {
    $framer = new ChunkedFramer;
    $buffer = "\r\n" . $framer->encode('<a/>');

    expect($framer->decode($buffer))->toBe('<a/>');
});

it('rejects malformed chunk headers', function () {
    $framer = new ChunkedFramer;
    $buffer = '<rpc-reply/>]]>]]>';

    expect(fn () => $framer->decode($buffer))->toThrow(ProtocolException::class, 'Malformed chunk header');
});

it('rejects invalid chunk sizes', function () {
    $framer = new ChunkedFramer;
    $buffer = "\n#0\n\n##\n";

    expect(fn () => $framer->decode($buffer))->toThrow(ProtocolException::class, 'chunk size');
});
