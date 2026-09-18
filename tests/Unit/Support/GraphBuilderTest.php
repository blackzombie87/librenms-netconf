<?php

use SafferIt\LibrenmsNetconf\Support\GraphBuilder;

it('builds DEF, LINE and GPRINT options per series with rotating colours', function () {
    $b = (new GraphBuilder(['AA0000', '00BB00']))
        ->add('/rrd/h/a.rrd', 'flaps', 'peer 192.0.2.1', 'COUNTER')
        ->add('/rrd/h/b.rrd', 'flaps', 'peer 192.0.2.2', 'COUNTER')
        ->add('/rrd/h/c.rrd', 'flaps', 'peer 192.0.2.3', 'COUNTER');

    $options = $b->options('BGP flaps', 'per second');

    expect($options[0])->toBe('--title')
        ->and($options[1])->toBe('BGP flaps')
        ->and($options[3])->toBe('per second')
        ->and($options)->toContain('DEF:ds0=/rrd/h/a.rrd:flaps:AVERAGE')
        ->and($options)->toContain('DEF:ds1min=/rrd/h/b.rrd:flaps:MIN')
        ->and(collect($options)->first(fn ($o) => str_starts_with($o, 'LINE1.25:ds0#')))->toStartWith('LINE1.25:ds0#AA0000:peer 192.0.2.1')
        ->and(collect($options)->first(fn ($o) => str_starts_with($o, 'LINE1.25:ds2#')))->toStartWith('LINE1.25:ds2#AA0000:')
        ->and($b->isRate())->toBeTrue()
        ->and($b->count())->toBe(3);
});

it('appends comments after the series with colons escaped', function () {
    $options = (new GraphBuilder)->add('/rrd/h/a.rrd', 'total', 'x')->comment('showing 25 of 61 rows: capped')->options('t');

    expect(end($options))->toBe('COMMENT:showing 25 of 61 rows\\: capped\\l');
});

it('escapes colons in RRD paths', function () {
    $options = (new GraphBuilder)->add('/rrd/2001:db8::1/netconf-a-b-c.rrd', 'total', 'x')->options('t');

    expect($options)->toContain('DEF:ds0=/rrd/2001\\:db8\\:\\:1/netconf-a-b-c.rrd:total:AVERAGE')
        ->and(GraphBuilder::safePath('/plain/path.rrd'))->toBe('/plain/path.rrd');
});

it('escapes colons and pads labels', function () {
    expect(GraphBuilder::safeLabel('ae2.0/192.0.2.12', 10))->toBe('ae2.0/192~')
        ->and(GraphBuilder::safeLabel('a:b', 6))->toBe('a\\:b   ')
        ->and(GraphBuilder::safeLabel("caf\u{e9}", 6))->toBe('caf?  ');
});

it('is not a rate graph when a gauge is mixed in', function () {
    $b = (new GraphBuilder)->add('f', 'a', 'a', 'COUNTER')->add('f', 'b', 'b', 'GAUGE');

    expect($b->isRate())->toBeFalse()
        ->and((new GraphBuilder)->isRate())->toBeFalse();
});
