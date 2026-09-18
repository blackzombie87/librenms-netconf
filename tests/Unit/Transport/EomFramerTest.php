<?php

use SafferIt\LibrenmsNetconf\Transport\Framing\EomFramer;

it('encodes a message with the end-of-message delimiter', function () {
    $framer = new EomFramer;

    expect($framer->encode('<hello/>'))->toBe("<hello/>\n]]>]]>\n");
    expect($framer->readDelimiter())->toBe(']]>]]>');
});

it('decodes one message at a time and keeps the rest buffered', function () {
    $framer = new EomFramer;
    $buffer = "<a/>]]>]]>\n<b/>]]>]]>";

    expect($framer->decode($buffer))->toBe('<a/>');
    expect($buffer)->toBe('<b/>]]>]]>');
    expect($framer->decode($buffer))->toBe('<b/>');
    expect($buffer)->toBe('');
});

it('returns null while the delimiter is incomplete', function () {
    $framer = new EomFramer;
    $buffer = '<a/>]]>]]';

    expect($framer->decode($buffer))->toBeNull();
    expect($buffer)->toBe('<a/>]]>]]');
});
