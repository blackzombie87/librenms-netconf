<?php

use SafferIt\LibrenmsNetconf\Support\Uninstaller;

it('recognises the RRD files the plugin writes', function () {
    expect(Uninstaller::isSensorRrd('/opt/librenms/rrd/leaf1/sensor-count-netconf-junos-evpn-dup-mac-total-total.rrd'))->toBeTrue()
        ->and(Uninstaller::isSensorRrd('sensor-state-netconf-junos-evpn-esi-lag-status-ae1.rrd'))->toBeTrue()
        ->and(Uninstaller::isSensorRrd('sensor-temperature-1.rrd'))->toBeFalse()
        ->and(Uninstaller::isSensorRrd('sensor-count-junos-netconf-x.rrd'))->toBeFalse()
        ->and(Uninstaller::isMetricRrd('/opt/librenms/rrd/leaf1/netconf-junos-routing-bgp-peer-10.0.0.1.rrd'))->toBeTrue()
        ->and(Uninstaller::isMetricRrd('netconf-port-42-junos-interfaces-ethernet.rrd'))->toBeTrue()
        ->and(Uninstaller::isMetricRrd('port-id42.rrd'))->toBeFalse()
        ->and(Uninstaller::isMetricRrd('sensor-count-netconf-junos-alarms-major-major.rrd'))->toBeFalse();
});

it('lists every shipped migration by its recorded name', function () {
    $names = Uninstaller::migrationNames();
    $files = glob(__DIR__ . '/../../../database/migrations/*.php') ?: [];

    expect($names)->toHaveCount(count($files))
        ->and($names)->each->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_.*netconf/')
        ->and($names)->not->toContain('');
});

it('knows the tables of every migration that creates one', function () {
    $created = [];
    foreach (glob(__DIR__ . '/../../../database/migrations/*.php') ?: [] as $file) {
        if (preg_match_all("/Schema::create\\('([a-z_]+)'/", (string) file_get_contents($file), $m)) {
            array_push($created, ...$m[1]);
        }
    }
    sort($created);
    $known = Uninstaller::TABLES;
    sort($known);

    expect($known)->toBe($created);
});
