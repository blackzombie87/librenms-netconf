<?php

use SafferIt\LibrenmsNetconf\Extract\Template;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

it('detects templates', function () {
    expect(Template::isTemplate('ESI {index}'))->toBeTrue()
        ->and(Template::isTemplate('{row:name}'))->toBeTrue()
        ->and(Template::isTemplate('string(name)'))->toBeFalse()
        ->and(Template::isTemplate("concat('{', name)"))->toBeFalse();
});

it('renders placeholders and row expressions', function () {
    $doc = new XmlDocument('<r><i><name>ae2.0</name><n>7</n></i></r>');
    $row = $doc->rows('//i')[0];

    $out = Template::render('LAG {index} {row:name} n={row:number(n)} {re} {device:hostname}', $doc, $row, [
        'index' => 'x', 're' => 're0', 'n' => '', 'device.hostname' => 'leaf1',
    ]);

    expect($out)->toBe('LAG x ae2.0 n=7 re0 leaf1');
});

it('substitutes {n} inside row expressions', function () {
    $doc = new XmlDocument('<r><v>a</v><v>b</v></r>');

    expect(Template::render('{row:v[{n}]}', $doc, $doc->root(), ['n' => '2']))->toBe('b');
});

it('stringifies numbers without trailing zeros', function () {
    expect(Template::stringify(12.0))->toBe('12')
        ->and(Template::stringify(1.5))->toBe('1.5')
        ->and(Template::stringify(true))->toBe('1')
        ->and(Template::stringify(null))->toBe('');
});
