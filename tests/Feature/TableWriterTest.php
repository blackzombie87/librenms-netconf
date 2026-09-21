<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Collect\TableWriter;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * TableWriter against the database: two mappings of one table merge their columns into the
 * same row (device + key), prune() deletes what the run did not produce, orphan tables go.
 */
final class TableWriterTest extends LibrenmsTestCase
{
    public function testMappingsMergeOnTheKeyAndPruneDeletesStaleRows(): void
    {
        $device = Device::factory()->create();
        $definition = (new DefinitionParser)->parse([
            'name' => 'merge', 'commands' => ['a' => 'show a'],
            'tables' => [
                ['id' => 'one', 'table' => 'vni', 'command' => 'a', 'rows' => '//v', 'columns' => ['vni' => 'number(id)', 'instance' => 'string(inst)']],
                ['id' => 'two', 'table' => 'vni', 'command' => 'a', 'rows' => '//v', 'columns' => ['vni' => 'number(id)', 'vlan_id' => 'number(vlan)']],
            ],
        ], 'merge.yaml');
        $doc = new XmlDocument('<r><v><id>10</id><inst>A</inst><vlan>100</vlan></v><v><id>11</id><inst>B</inst><vlan>101</vlan></v></r>');
        $extractor = new Extractor;
        $one = $extractor->tables($definition, $definition->tables[0], $doc);
        $two = $extractor->tables($definition, $definition->tables[1], $doc);
        $writer = new TableWriter($device);
        $table = TableSchema::tableName('vni');

        $this->assertSame(['rows' => 4, 'tables' => 1], $writer->write(array_merge($one, $two)));
        $rows = DB::table($table)->where('device_id', $device->device_id)->orderBy('vni')->get(['vni', 'instance', 'vlan_id'])->map(fn ($r) => (array) $r)->all();
        $this->assertSame([['vni' => 10, 'instance' => 'A', 'vlan_id' => 100], ['vni' => 11, 'instance' => 'B', 'vlan_id' => 101]], array_map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $r), $rows));
        $this->assertSame(['vni'], $writer->owned());

        // the next run only sees VNI 10: 11 goes, 10 keeps both mappings' columns
        $writer->write([$one[0], $two[0]]);
        $this->assertSame(1, $writer->prune([$one[0], $two[0]], ['vni']));
        $this->assertSame([10], DB::table($table)->where('device_id', $device->device_id)->pluck('vni')->map(fn ($v) => (int) $v)->all());
        // a table whose command was skipped is not pruned
        $this->assertSame(0, $writer->prune([], []));
        $this->assertSame(1, DB::table($table)->where('device_id', $device->device_id)->count());

        $this->assertSame(1, $writer->deleteTables(['vni', 'esi']));
        $this->assertFalse($writer->exists());
    }
}
