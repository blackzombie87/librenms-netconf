<?php

use SafferIt\LibrenmsNetconf\Extract\ExtractionException;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;

it('strips namespaces and locates the payload', function () {
    $doc = new XmlDocument(fixture('junos/show-interfaces-extensive.xml'), 'show interfaces extensive');

    expect($doc->root()->localName)->toBe('interface-information')
        ->and($doc->root()->namespaceURI)->toBeNull()
        ->and($doc->rows('//physical-interface'))->toHaveCount(1)
        ->and($doc->scalar('string(//physical-interface/name)'))->toBe('et-0/0/49')
        ->and($doc->scalar('number(//physical-interface/snmp-index)'))->toBe(626.0);
});

it('keeps attributes under their local name', function () {
    $doc = new XmlDocument(fixture('junos/show-interfaces-extensive.xml'));

    expect($doc->scalar('number(//interface-flapped/@seconds)'))->toBe(45406172.0);
});

it('evaluates relative rows from the payload root', function () {
    $doc = new XmlDocument(fixture('junos/show-route-summary.xml'));

    expect($doc->rows('route-table'))->toHaveCount(4)
        ->and($doc->rows('//route-table/protocols'))->toHaveCount(8);
});

it('collapses node-sets and NaN', function () {
    $doc = new XmlDocument('<a><b>x</b><c>12</c></a>');

    expect($doc->scalar('b'))->toBe('x')
        ->and($doc->scalar('missing'))->toBe('')
        ->and($doc->scalar('number(missing)'))->toBeNull()
        ->and($doc->scalar('number(c)'))->toBe(12.0)
        ->and($doc->scalar('count(*)'))->toBe(2.0)
        ->and($doc->bool('c > 10'))->toBeTrue()
        ->and($doc->bool('missing'))->toBeFalse();
});

it('reports the routing engine for multi-RE rows', function () {
    $doc = new XmlDocument(fixture('junos/show-version-multi-re.xml'));
    $rows = $doc->rows('//software-information');

    expect($doc->root()->localName)->toBe('multi-routing-engine-results')
        ->and($rows)->toHaveCount(1)
        ->and($doc->reName($rows[0]))->toBe('localre')
        ->and(($plain = new XmlDocument('<a><b/></a>'))->reName($plain->root()))->toBeNull();
});

it('rejects invalid xpath with a readable message', function () {
    $doc = new XmlDocument('<a/>');

    expect(fn () => $doc->scalar('string(('))->toThrow(ExtractionException::class, 'invalid XPath')
        ->and(XmlDocument::validateExpression('count(//x)'))->toBeNull()
        ->and(XmlDocument::validateExpression('//x['))->toContain('invalid XPath');
});

it('rejects broken xml', function () {
    expect(fn () => new XmlDocument('<a><b>', 'show x'))->toThrow(ProtocolException::class, 'show x: not well-formed XML');
});
