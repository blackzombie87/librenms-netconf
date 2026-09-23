<?php

use SafferIt\LibrenmsNetconf\Support\GraphPeriod;

it('keeps every period the pages offer', function () {
    foreach (array_keys(GraphPeriod::OFFERED) as $token) {
        expect(GraphPeriod::sanitise($token))->toBe($token);
    }
    // the one that used to fall through: "mo" is the month, "m" a minute (F6 2)
    expect(GraphPeriod::sanitise('-1mo'))->toBe('-1mo');
});

it('takes the units LibreNMS parses, in both directions', function () {
    expect(GraphPeriod::sanitise('-30s'))->toBe('-30s')
        ->and(GraphPeriod::sanitise('-15m'))->toBe('-15m')     // a minute, and a valid period
        ->and(GraphPeriod::sanitise('-48h'))->toBe('-48h')
        ->and(GraphPeriod::sanitise('-2w'))->toBe('-2w')
        ->and(GraphPeriod::sanitise('-6mo'))->toBe('-6mo')
        ->and(GraphPeriod::sanitise('+1d'))->toBe('+1d');
});

it('falls back to a day for anything else', function () {
    expect(GraphPeriod::sanitise('1d'))->toBe('-1d')           // no sign
        ->and(GraphPeriod::sanitise('-1x'))->toBe('-1d')       // no such unit
        ->and(GraphPeriod::sanitise('-1moo'))->toBe('-1d')
        ->and(GraphPeriod::sanitise('-d'))->toBe('-1d')
        ->and(GraphPeriod::sanitise(''))->toBe('-1d')
        ->and(GraphPeriod::sanitise(null))->toBe('-1d')
        ->and(GraphPeriod::sanitise('-1d; rm -rf /'))->toBe('-1d')
        ->and(GraphPeriod::sanitise("-1d\n--imgformat=PNG"))->toBe('-1d')
        ->and(GraphPeriod::sanitise('<script>alert(1)</script>'))->toBe('-1d');
});
