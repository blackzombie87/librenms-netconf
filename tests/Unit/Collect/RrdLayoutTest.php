<?php

use SafferIt\LibrenmsNetconf\Collect\RrdLayout;

it('parses rrdtool info into data sources in file order', function () {
    $info = <<<'INFO'
filename = "rrd/h/netconf-junos-ntp-system-system.rrd"
rrd_version = "0003"
step = 300
last_update = 1789730280
header_size = 4512
ds[rootdelay_ms].index = 0
ds[rootdelay_ms].type = "GAUGE"
ds[rootdelay_ms].minimal_heartbeat = 600
ds[rootdelay_ms].min = NaN
ds[flaps].index = 1
ds[flaps].type = "COUNTER"
ds[flaps].minimal_heartbeat = 600
rra[0].cf = "AVERAGE"
INFO;

    expect(RrdLayout::parseInfo($info))->toBe(['rootdelay_ms' => 'GAUGE', 'flaps' => 'COUNTER'])
        ->and(RrdLayout::parseInfo('ERROR: not an rrd file'))->toBe([]);
});

it('keeps the file order and appends the fields the file lacks', function () {
    $file = ['stratum' => 'GAUGE', 'delay_ms' => 'GAUGE', 'reach' => 'GAUGE'];           // created by an older version
    $mapping = ['stratum' => 'GAUGE', 'delay_ms' => 'GAUGE', 'offset_ms' => 'GAUGE', 'selected' => 'GAUGE'];

    $plan = RrdLayout::plan($file, $mapping);

    expect(array_keys($plan['order']))->toBe(['stratum', 'delay_ms', 'reach', 'offset_ms', 'selected'])
        ->and($plan['add'])->toBe(['offset_ms' => 'GAUGE', 'selected' => 'GAUGE'])
        ->and(RrdLayout::plan($mapping, $mapping))->toBe(['order' => $mapping, 'add' => []]);
});
