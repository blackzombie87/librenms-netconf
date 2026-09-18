<?php

use SafferIt\LibrenmsNetconf\Collect\Backoff;
use SafferIt\LibrenmsNetconf\Collect\SensorEvents;

it('fires when a limit-0 counter goes from 0 to 1', function () {
    $events = SensorEvents::evaluate('Count', 'EVPN duplicate MACs (total)', '', 0.0, 1.0, 0.0, null);

    expect($events)->toHaveCount(1)
        ->and($events[0]['severity'])->toBe(SensorEvents::WARNING)
        ->and($events[0]['message'])->toBe('Count above threshold: 1 (> 0) - EVPN duplicate MACs (total)');
});

it('stays quiet without a previous value, while above the limit, or when alerting is off', function () {
    expect(SensorEvents::evaluate('Count', 'x', '', null, 5.0, 0.0, null))->toBe([])
        ->and(SensorEvents::evaluate('Count', 'x', '', 3.0, 5.0, 0.0, null))->toBe([])
        ->and(SensorEvents::evaluate('Count', 'x', '', 0.0, 5.0, 0.0, null, alert: false))->toBe([]);
});

it('reports low thresholds with units', function () {
    $events = SensorEvents::evaluate('Temperature', 'Inlet', 'C', 20.0, 4.5, null, 5.0);

    expect($events[0]['message'])->toBe('Temperature under threshold: 4.5 C (< 5 C) - Inlet');
});

it('reports state changes with labels', function () {
    $events = SensorEvents::evaluate('State', 'ESI-LAG ae4.0', '', 1.0, 2.0, null, null, true, 'Up', 'Down');

    expect($events)->toHaveCount(1)
        ->and($events[0]['severity'])->toBe(SensorEvents::NOTICE)
        ->and($events[0]['message'])->toBe('State sensor ESI-LAG ae4.0 has changed from Up (1) to Down (2)');

    expect(SensorEvents::evaluate('State', 'x', '', 1.0, 1.0, null, null, true, 'Up', 'Up'))->toBe([]);
});

it('backs off exponentially up to the maximum', function () {
    expect(Backoff::skipPolls(0, 32))->toBe(0)
        ->and(Backoff::skipPolls(1, 32))->toBe(1)
        ->and(Backoff::skipPolls(2, 32))->toBe(2)
        ->and(Backoff::skipPolls(4, 32))->toBe(8)
        ->and(Backoff::skipPolls(10, 32))->toBe(32)
        ->and(Backoff::skipPolls(100, 32))->toBe(32)
        ->and(Backoff::delaySeconds(3, 32, 300))->toBe(1200)
        ->and(Backoff::delaySeconds(1, 32, 10))->toBe(60);
});
