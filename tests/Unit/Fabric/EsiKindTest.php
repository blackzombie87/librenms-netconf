<?php

use SafferIt\LibrenmsNetconf\Fabric\View\EsiKind;

it('tells an anycast gateway segment from an ESI-LAG', function () {
    expect(EsiKind::isGateway('05:00:00:01:00:00:00:10:00', 'irb.12'))->toBeTrue()
        ->and(EsiKind::isGateway('05:00:00:01:00:00:00:10:00', null))->toBeTrue()
        ->and(EsiKind::isGateway('01:00:00:00:00:00:00:00:01', 'irb'))->toBeTrue()
        ->and(EsiKind::isGateway('01:00:00:00:00:00:00:00:01', 'ae2.0'))->toBeFalse()
        ->and(EsiKind::isGateway('01:00:00:00:00:00:00:00:01', null))->toBeFalse()
        // an interface that merely starts with the letters is not an IRB unit
        ->and(EsiKind::isGateway('01:aa', 'irbfoo'))->toBeFalse();
});

it('calls a row an ESI-LAG when any side is not a gateway interface', function () {
    $gateway = ['esi' => '05:aa', 'sides' => [1 => ['ifname' => 'irb.101'], 2 => ['ifname' => 'irb.101']]];
    $lag = ['esi' => '01:aa', 'sides' => [1 => ['ifname' => 'ae2.0'], 2 => ['ifname' => 'ae2.0']]];
    // one side on an IRB and one on a LAG: the segment still has a LAG side to draw
    $mixed = ['esi' => '01:aa', 'sides' => [1 => ['ifname' => 'irb.101'], 2 => ['ifname' => 'ae7.0']]];
    // a type-5 value is a gateway segment whatever interface it sits on
    $typeFiveOnAe = ['esi' => '05:aa', 'sides' => [1 => ['ifname' => 'ae7.0']]];

    expect(EsiKind::isLag($gateway))->toBeFalse()
        ->and(EsiKind::isLag($lag))->toBeTrue()
        ->and(EsiKind::isLag($mixed))->toBeTrue()
        // a segment nobody has a local interface for is not a LAG we can draw
        ->and(EsiKind::isLag($typeFiveOnAe))->toBeFalse()
        ->and(EsiKind::isLag(['esi' => '01:aa', 'sides' => []]))->toBeFalse();
});

it('chips the degraded flags, which are not the tab danger flags', function () {
    expect(EsiKind::CHIP)->not->toBe(EsiKind::TAB_DANGER)
        ->and(EsiKind::CHIP)->not->toContain('single-pe')
        ->and(EsiKind::TAB_DANGER)->not->toContain('lacp-degraded')
        ->and(EsiKind::CHIP)->toContain('lacp-degraded')
        ->and(array_values(array_diff(EsiKind::TAB_DANGER, ['single-pe'])))->toBe(array_values(array_diff(EsiKind::CHIP, ['lacp-degraded'])))
        ->and(EsiKind::degraded(['lag-down']))->toBeTrue()
        ->and(EsiKind::degraded(['lacp-degraded']))->toBeTrue()
        ->and(EsiKind::degraded(['single-pe']))->toBeFalse()
        ->and(EsiKind::degraded(['no-aliasing']))->toBeFalse()
        ->and(EsiKind::degraded([]))->toBeFalse();
});
