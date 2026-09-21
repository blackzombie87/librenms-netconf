<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\Port;
use SafferIt\LibrenmsNetconf\Collect\MetricWriter;
use SafferIt\LibrenmsNetconf\Collect\PortMetricWriter;
use SafferIt\LibrenmsNetconf\Collect\RrdLayout;
use SafferIt\LibrenmsNetconf\Definitions\MetricField;
use SafferIt\LibrenmsNetconf\Definitions\MetricMapping;
use SafferIt\LibrenmsNetconf\Definitions\PortMapping;
use SafferIt\LibrenmsNetconf\Extract\MetricRow;
use SafferIt\LibrenmsNetconf\Extract\PortMetricRow;
use SafferIt\LibrenmsNetconf\Models\NetconfMetric;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;

require_once __DIR__ . '/LibrenmsTestCase.php';
require_once __DIR__ . '/MemoryDatastore.php';

/**
 * The metric writers against the database and a datastore (plan G6): what lands in the row
 * and what the RRD is named have to agree, which is what a long index used to break (F4a 8).
 */
final class WritersTest extends LibrenmsTestCase
{
    public function testALongIndexIsTruncatedOnceForTheRowAndTheRrdName(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $index = str_repeat('a', 200);
        $datastore = new MemoryDatastore;

        $result = (new MetricWriter($device, $this->layout()))->write([$this->metricRow($index)], $datastore);

        $this->assertSame(['rows' => 1, 'written' => 1], $result);
        $stored = NetconfMetric::query()->where('device_id', $device->device_id)->sole();
        $this->assertSame(191, mb_strlen($stored->metric_index));
        $this->assertSame(mb_substr($index, 0, 191), $stored->metric_index);

        // the datastore names the file from the stored index, not from the untruncated one
        $put = $datastore->puts[0];
        $this->assertSame(['netconf', 'test', 'rib', $stored->metric_index], $put['tags']['rrd_name']);
        $this->assertSame($stored->metric_index, $put['tags']['index']);
        $this->assertSame(['routes' => 7.0], $put['fields']);

        // a second run with the same long index updates the one row instead of adding another
        (new MetricWriter($device, $this->layout()))->write([$this->metricRow($index, 9.0)], $datastore);
        $this->assertSame(1, NetconfMetric::query()->where('device_id', $device->device_id)->count());
        $this->assertEquals(['routes' => 9.0], NetconfMetric::query()->where('device_id', $device->device_id)->sole()->values);
    }

    public function testAMetricRowSurvivesADiscoveryRunAndIsPrunedWhenTheIndexDisappears(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $writer = new MetricWriter($device, $this->layout());

        // discovery: no datastore, so the row is written but nothing is put
        $datastore = new MemoryDatastore;
        $writer->write([$this->metricRow('192.0.2.1/bgp.evpn.0')], null);
        $this->assertSame(1, NetconfMetric::query()->where('device_id', $device->device_id)->count());
        $this->assertSame([], $datastore->puts);

        // the index is gone from the reply: the row of a mapping that did deliver data goes
        $this->assertSame(1, $writer->prune([], ['test/rib']));
        $this->assertSame(0, NetconfMetric::query()->where('device_id', $device->device_id)->count());
    }

    public function testPortMetricsResolveThePortAndWriteOneRowPerMapping(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $port = Port::factory()->create(['device_id' => $device->device_id, 'ifName' => 'et-0/0/48', 'ifIndex' => 548]);
        $datastore = new MemoryDatastore;
        $writer = new PortMetricWriter($device, $this->layout());

        $result = $writer->write([$this->portRow('et-0/0/48'), $this->portRow('et-0/0/99')], $datastore);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(['ifName=et-0/0/99'], $result['unmatched']);
        $stored = NetconfPortMetric::query()->where('device_id', $device->device_id)->sole();
        $this->assertSame($port->port_id, (int) $stored->port_id);
        $this->assertEquals(['errors' => 3.0], $stored->values);
        $this->assertSame(['netconf-port', $port->port_id, 'test', 'fec'], $datastore->puts[0]['tags']['rrd_name']);

        // the next run's mapping no longer lists the port: its row goes (a fresh writer, so
        // nothing was written for the mapping this time)
        $this->assertSame(1, (new PortMetricWriter($device, $this->layout()))->prune(['test/fec']));
        $this->assertSame(0, NetconfPortMetric::query()->where('device_id', $device->device_id)->count());
    }

    private function metricRow(string $index, float $value = 7.0): MetricRow
    {
        $mapping = new MetricMapping(id: 'rib', command: 'show route summary', index: 'string(name)', fields: [new MetricField('routes', 'number(count)')], descr: '{index}');

        return new MetricRow('test', $mapping, $index, 'RIB ' . mb_substr($index, 0, 20), ['routes' => $value], [], ['routes' => 'GAUGE']);
    }

    private function portRow(string $ifName): PortMetricRow
    {
        $mapping = new PortMapping(id: 'fec', command: 'show interfaces extensive', rows: '//physical-interface', matchField: 'ifName', matchXpath: 'string(name)', metrics: [new MetricField('errors', 'number(errors)', 'COUNTER')]);

        return new PortMetricRow('test', $mapping, 'ifName', $ifName, ['errors' => 3.0], ['errors' => 'COUNTER']);
    }

    /** An RRD layout pointed at a scratch directory: no file exists, so nothing is reconciled. */
    private function layout(): RrdLayout
    {
        return new RrdLayout(rrdtool: '/bin/false', rrdDir: sys_get_temp_dir() . '/netconf-test-rrd');
    }
}
