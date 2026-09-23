<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricResolver;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * Plan G18: the resolver runs on every leaf poll that wrote or pruned rows, so its cost must
 * follow the size of the fabric, not the number of monitored members. The fabric is built
 * synthetically here (a full mesh of leaves with VNIs, neighbours, tunnels and ESIs) and the
 * test pins the query count as members are added.
 */
final class FabricScaleTest extends LibrenmsTestCase
{
    private const VNIS = 20;

    /**
     * Queries a resolve may cost per extra monitored member. The resolver reads the tables as
     * a whole, so this is well under 1: what is left is chunking (a longer insert, a longer
     * whereIn list). A single per-device query in the hot path pushes it over 1 at once.
     */
    private const QUERIES_PER_MEMBER = 0.5;

    public function testQueryCountDoesNotGrowPerMember(): void
    {
        $small = $this->measure(4);
        $large = $this->measure(24);

        $perMember = ($large['queries'] - $small['queries']) / 20;
        $this->assertLessThan(
            self::QUERIES_PER_MEMBER,
            $perMember,
            sprintf(
                "resolve() costs %.1f queries per extra member (4 members: %d queries / %.2f s, 24 members: %d queries / %.2f s)\nqueries that grew:\n%s",
                $perMember, $small['queries'], $small['seconds'], $large['queries'], $large['seconds'],
                self::grew($small['shapes'], $large['shapes'])
            )
        );
    }

    /**
     * Build a fabric of $members monitored leaves and resolve it.
     *
     * @return array{queries: int, seconds: float, shapes: array<string, int>, result: array<string, int>}
     */
    private function measure(int $members): array
    {
        $this->clearFabric();
        $ips = $this->buildFabric($members);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $result = FabricResolver::make()->resolve();
        $seconds = microtime(true) - $start;
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $shapes = [];
        foreach ($log as $entry) {
            $shapes[self::shape((string) $entry['query'])] = ($shapes[self::shape((string) $entry['query'])] ?? 0) + 1;
        }
        $queries = count($log);

        $this->assertSame($members, $result['devices'], 'every leaf is a member');
        $this->assertSame(1, $result['fabrics'], 'the mesh is one fabric');
        $this->assertSame(count($ips), $result['nodes']);

        return ['queries' => $queries, 'seconds' => $seconds, 'shapes' => $shapes, 'result' => $result];
    }

    /** The statement without its bound values and in-lists, so repetitions collapse. */
    private static function shape(string $sql): string
    {
        return preg_replace(['/\(\s*\?\s*(,\s*\?\s*)*\)/', '/\s+/'], ['(?)', ' '], $sql) ?? $sql;
    }

    /**
     * The statements the bigger fabric ran more often, worst first.
     *
     * @param  array<string, int>  $small
     * @param  array<string, int>  $large
     */
    private static function grew(array $small, array $large): string
    {
        $delta = [];
        foreach ($large as $sql => $count) {
            $diff = $count - ($small[$sql] ?? 0);
            if ($diff > 0) {
                $delta[$sql] = $diff;
            }
        }
        arsort($delta);

        return implode("\n", array_map(fn ($sql, $n) => sprintf('  +%3d  %s', $n, mb_substr($sql, 0, 150)), array_keys(array_slice($delta, 0, 8, true)), array_slice($delta, 0, 8, true)));
    }

    /**
     * A full mesh: every leaf has its own VTEP, the others as EVPN neighbours, a tunnel and a
     * flood-list entry per peer, plus one ESI-LAG.
     *
     * @return list<string> the VTEP address of every leaf
     */
    private function buildFabric(int $members): array
    {
        $now = now();
        $ips = [];
        $devices = [];
        for ($i = 1; $i <= $members; $i++) {
            $devices[] = Device::factory()->create(['hostname' => "scale-leaf$i.example.net", 'os' => 'junos'])->device_id;
            $ips[] = '198.51.100.' . $i;
        }

        foreach ($devices as $n => $deviceId) {
            $rows = ['vni' => [], 'neighbor' => [], 'tunnel' => [], 'vni_vtep' => [], 'esi' => []];
            for ($v = 0; $v < self::VNIS; $v++) {
                $rows['vni'][] = ['device_id' => $deviceId, 'vni' => 10000 + $v, 'instance' => "MACVRF-$v", 'source_vtep' => $ips[$n], 'last_seen' => $now];
            }
            foreach ($ips as $m => $peer) {
                if ($m === $n) {
                    continue;
                }
                $rows['neighbor'][] = ['device_id' => $deviceId, 'instance' => 'default', 'neighbor_ip' => $peer, 'router_id' => $peer, 'last_seen' => $now];
                $rows['tunnel'][] = ['device_id' => $deviceId, 'remote_vtep_ip' => $peer, 'ifname' => "vtep.327{$m}", 'last_seen' => $now];
                $rows['vni_vtep'][] = ['device_id' => $deviceId, 'vni' => 10000, 'remote_vtep_ip' => $peer, 'instance' => 'MACVRF-0', 'last_seen' => $now];
            }
            $rows['esi'][] = [
                'device_id' => $deviceId, 'esi' => sprintf('00:11:22:33:44:55:66:77:88:%02d', $n), 'instance' => 'MACVRF-0',
                'local_ifname' => 'ae0.0', 'remote_vtep_ips' => json_encode([$ips[($n + 1) % $members]]), 'last_seen' => $now,
            ];
            foreach ($rows as $table => $insert) {
                DB::table(TableSchema::tableName($table))->insert($insert);
            }
        }

        return $ips;
    }

    private function clearFabric(): void
    {
        foreach (['vni', 'neighbor', 'tunnel', 'vni_vtep', 'esi', 'mac', 'vtep', 'fabric_member', 'fabric', 'underlay_link'] as $table) {
            DB::table(TableSchema::tableName($table))->delete();
        }
    }
}
