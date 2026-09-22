<?php

use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\Identity;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

/**
 * The index identity policy (F5 4): an index fits its column or is shortened once, at
 * extraction time, to prefix ~ hash; distinct long indices stay distinct.
 */
it('leaves an index that fits untouched and shortens a longer one to the column width', function () {
    $fits = str_repeat('x', 128);
    $long = str_repeat('x', 129);

    expect(Identity::fit($fits, Identity::SENSOR_WIDTH))->toBe($fits)
        ->and(Identity::exceeds($fits, Identity::SENSOR_WIDTH))->toBeFalse()
        ->and(Identity::exceeds($long, Identity::SENSOR_WIDTH))->toBeTrue()
        ->and(mb_strlen(Identity::fit($long, Identity::SENSOR_WIDTH)))->toBe(128)
        ->and(Identity::fit($long, Identity::SENSOR_WIDTH))->toBe(str_repeat('x', 119) . '~' . substr(sha1($long), 0, 8))
        ->and(mb_strlen(Identity::fit(str_repeat('ü', 300), Identity::METRIC_WIDTH)))->toBe(191)
        ->and(Identity::fit(str_repeat('y', 191), Identity::METRIC_WIDTH))->toBe(str_repeat('y', 191));
});

it('keeps two long indices apart that share the visible prefix', function () {
    $a = str_repeat('p', 119) . '/instance-a';
    $b = str_repeat('p', 119) . '/instance-b';

    $fa = Identity::fit($a, Identity::SENSOR_WIDTH);
    $fb = Identity::fit($b, Identity::SENSOR_WIDTH);

    expect($fa)->not->toBe($fb)
        ->and(substr($fa, 0, 120))->toBe(substr($fb, 0, 120))
        ->and(Identity::fit($a, Identity::SENSOR_WIDTH))->toBe($fa);   // deterministic
});

it('fits the index while extracting and warns once per row, so the replay of a fixture shows it', function () {
    $def = (new DefinitionParser)->parse(['name' => 'ex', 'commands' => ['c' => 'show x'], 'sensors' => [
        ['id' => 's', 'class' => 'count', 'command' => 'c', 'rows' => '//i', 'index' => 'string(n)', 'descr' => '{index}', 'value' => 'number(v)'],
    ], 'metrics' => [
        ['id' => 'm', 'command' => 'c', 'rows' => '//i', 'index' => 'string(n)', 'fields' => ['v' => 'number(v)']],
    ]], 'ex.yaml');
    $long = str_repeat('n', 200);
    $doc = new XmlDocument("<r><i><n>short</n><v>1</v></i><i><n>$long</n><v>2</v></i></r>");
    $x = new Extractor;

    $sensors = $x->sensors($def, $def->sensors[0], $doc);
    $metrics = $x->metrics($def, $def->metrics[0], $doc);

    expect(array_map(fn ($s) => $s->index, $sensors))->toBe(['short', Identity::fit($long, Identity::SENSOR_WIDTH)])
        ->and($sensors[1]->descr)->toBe(Identity::fit($long, Identity::SENSOR_WIDTH))   // {index} is the stored one
        ->and(array_map(fn ($m) => $m->index, $metrics))->toBe(['short', Identity::fit($long, Identity::METRIC_WIDTH)])
        ->and($x->warnings())->toHaveCount(2)
        ->and($x->warnings()[0])->toContain('ex/s: index')->toContain('has 200 characters, the column holds 128')
        ->and($x->warnings()[1])->toContain('ex/m: index')->toContain('the column holds 191');
});
