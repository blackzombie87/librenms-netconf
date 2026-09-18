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

it('resolves rrdcached listings against the host directory', function () {
    $dir = sys_get_temp_dir() . '/netconf-uninstall-' . uniqid();
    mkdir($dir . '/leaf1', 0777, true);
    touch($dir . '/leaf1/netconf-junos-system-uptime-uptime.rrd');
    try {
        $absolute = $dir . '/leaf1/netconf-junos-system-uptime-uptime.rrd';
        expect(Uninstaller::localRrdPath($absolute, $dir . '/leaf1'))->toBe($absolute)
            // bare name relative to the host directory
            ->and(Uninstaller::localRrdPath('netconf-junos-system-uptime-uptime.rrd', $dir . '/leaf1'))->toBe($absolute)
            // /<host>/<file> relative to the daemon base looks absolute but is not
            ->and(Uninstaller::localRrdPath('/leaf1/netconf-junos-system-uptime-uptime.rrd', $dir . '/leaf1'))->toBe($absolute)
            // unknown file: the host-directory path is reported, nothing invented
            ->and(Uninstaller::localRrdPath('/leaf1/netconf-gone.rrd', $dir . '/leaf1/'))->toBe($dir . '/leaf1/netconf-gone.rrd');
    } finally {
        unlink($dir . '/leaf1/netconf-junos-system-uptime-uptime.rrd');
        rmdir($dir . '/leaf1');
        rmdir($dir);
    }
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
