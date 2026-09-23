<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\Sensor;
use LibreNMS\Data\Store\Rrd;
use SafferIt\LibrenmsNetconf\Collect\SensorWriter;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\Identity;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

require_once __DIR__ . '/LibrenmsTestCase.php';
require_once __DIR__ . '/MemoryDatastore.php';

/**
 * Long sensor indices against core's varchar(128) column (F5 4): a 129-character index used
 * to abort discovery with "Data too long". Extracted indices are fitted once, so discovery,
 * the record lookup, the custom limits and the RRD name agree, and two long indices that
 * share the visible prefix are two sensors.
 */
final class SensorIdentityTest extends LibrenmsTestCase
{
    public function testLongIndicesAreStoredRecordedAndKeptApart(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $fits = str_repeat('a', 128);
        $long = str_repeat('b', 129);
        $twinA = str_repeat('c', 119) . '/instance-a';
        $twinB = str_repeat('c', 119) . '/instance-b';
        $definition = (new DefinitionParser)->parse(['name' => 'ident', 'commands' => ['c' => 'show x'], 'sensors' => [
            ['id' => 'n', 'class' => 'count', 'command' => 'c', 'rows' => '//i', 'index' => 'string(n)', 'descr' => 'Thing {index}', 'value' => 'number(v)', 'limit' => 10],
        ]], 'ident.yaml');
        $extract = fn (int $value) => (new Extractor)->sensors($definition, $definition->sensors[0], new XmlDocument(
            "<r><i><n>$fits</n><v>$value</v></i><i><n>$long</n><v>$value</v></i><i><n>$twinA</n><v>$value</v></i><i><n>$twinB</n><v>$value</v></i></r>"
        ));
        $writer = new SensorWriter($device);
        $rows = fn () => Sensor::query()->where('device_id', $device->device_id)->where('poller_type', SensorWriter::POLLER_TYPE)->orderBy('sensor_index')->get();

        // discovery: four sensors, none longer than the column
        $writer->sync($extract(1), [], ['count']);
        $indices = $rows()->pluck('sensor_index')->all();
        $this->assertCount(4, $indices);
        $this->assertSame([128, 128, 128, 128], array_map('mb_strlen', $indices));
        $this->assertContains($fits, $indices);
        $this->assertContains(Identity::fit($long, Identity::SENSOR_WIDTH), $indices);
        $this->assertContains(Identity::fit($twinA, Identity::SENSOR_WIDTH), $indices);
        $this->assertContains(Identity::fit($twinB, Identity::SENSOR_WIDTH), $indices);
        $this->assertSame('Thing ' . $fits, $rows()->firstWhere('sensor_index', $fits)->sensor_descr);   // descr keeps its own 255 width

        // a limit customised in the UI survives rediscovery of the long index
        Sensor::query()->where('device_id', $device->device_id)->where('sensor_index', Identity::fit($long, Identity::SENSOR_WIDTH))->update(['sensor_limit' => 42, 'sensor_custom' => 'Yes']);
        $writer->sync($extract(1), [], ['count']);
        $this->assertSame(42.0, (float) $rows()->firstWhere('sensor_index', Identity::fit($long, Identity::SENSOR_WIDTH))->sensor_limit);
        $this->assertCount(4, $rows());

        // poll: every reading finds its row, the RRD is named from the stored index
        $datastore = new MemoryDatastore;
        $recorded = $writer->record($extract(7), $datastore);
        $this->assertSame(4, $recorded['recorded']);
        $this->assertSame([], $recorded['unknown']);
        $names = array_map(fn ($put) => $put['tags']['rrd_name'][3], $datastore->puts);
        sort($names);
        sort($indices);
        $this->assertSame($indices, $names);
        $this->assertSame([7.0, 7.0, 7.0, 7.0], $rows()->map(fn (Sensor $s) => (float) $s->sensor_current)->all());

        // the RRD file keeps the stored index: safeName() rewrites every character outside
        // [A-Za-z0-9,._-], so the separator between prefix and hash must be one it keeps,
        // or two fitted indices can end up in one file (F6 3)
        foreach ($indices as $index) {
            $this->assertSame($index, Rrd::safeName($index));
        }
        $this->assertNotSame(
            Rrd::safeName(Identity::fit($twinA, Identity::SENSOR_WIDTH)),
            Rrd::safeName(Identity::fit($twinB, Identity::SENSOR_WIDTH))
        );
        // and a natural index carrying the separator is still a different file
        $this->assertNotSame(
            Rrd::safeName(Identity::fit(str_repeat('c', 119) . ',0badc0de/x', Identity::SENSOR_WIDTH)),
            Rrd::safeName(Identity::fit($twinA, Identity::SENSOR_WIDTH))
        );
    }
}
