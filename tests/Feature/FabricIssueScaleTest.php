<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueStore;
use SafferIt\LibrenmsNetconf\Fabric\FabricResolver;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * Plan §10 X7: the first production fabric was 12 leaves with 285 VNIs each, instantiated from
 * one fleet-wide VLAN template — a shape the suite had never built. `FabricScaleTest` (G18)
 * meshes every leaf with every VNI, so every leaf advertised everything and the checks were
 * quiet; the real fabric is the opposite, and it produced 22,867 issues, 67,335 eventlog rows
 * and a Checks tab that died at the memory limit.
 *
 * Two things are pinned here: a templated fabric produces no issue storm (X1/X2), and the
 * Checks tab pages instead of rendering everything (X3) whatever the issue count.
 */
final class FabricIssueScaleTest extends LibrenmsTestCase
{
    private const LEAVES = 12;

    private const VNIS = 285;

    /** Leaves that announce every VNI, as the four spines of the production fabric do. */
    private const ANNOUNCE_ALL = 3;

    public function testATemplatedFabricIsQuiet(): void
    {
        $this->buildTemplatedFabric();

        DB::table('eventlog')->delete();
        $result = FabricResolver::make()->resolve();

        $issues = DB::table(IssueStore::TABLE)->get();
        $events = DB::table('eventlog')->where('type', IssueStore::EVENT_TYPE)->count();

        $this->assertSame(1, $result['fabrics']);
        $this->assertSame(self::LEAVES, $result['devices']);
        $this->assertSame(
            [],
            $issues->pluck('message', 'check')->all(),
            sprintf('%d VNIs on %d leaves, %d announced everywhere: nothing is wrong with this fabric', self::VNIS, self::LEAVES, self::ANNOUNCE_ALL)
        );
        $this->assertSame(0, $events, 'and nothing reaches the eventlog');

        // a leaf that really drops out of one peer's flood list is still found
        $device = (int) DB::table(TableSchema::tableName('vni'))->orderBy('device_id')->value('device_id');
        DB::table(TableSchema::tableName('vni_vtep'))->where('device_id', $device)->where('vni', 10000)->where('remote_vtep_ip', '192.51.100.2')->delete();
        FabricResolver::make()->resolve();

        $this->assertSame(['vni-flood-gap'], DB::table(IssueStore::TABLE)->pluck('check')->unique()->values()->all());
        $this->assertSame(1, DB::table(IssueStore::TABLE)->count());
        $this->assertSame(2, DB::table('eventlog')->where('type', IssueStore::EVENT_TYPE)->count(), 'one row per device involved');
    }

    public function testTheChecksTabPagesThroughThousandsOfIssues(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $fabric = $this->fabricWithIssues(5000);
        $tab = fn (string $query = '') => $this->get("/plugin/netconf/fabric/$fabric/checks$query")->assertOk();

        $tab();   // once unmeasured: the first request of a process also fills LibreNMS's config
        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $tab()->getContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(100, substr_count($first, '<tr class='), 'one page of rows, not 5,000');
        $this->assertLessThan(300_000, strlen($first), 'the page that explains an alarm storm must not be the one that dies');
        $this->assertStringContainsString('5000 of 5000 shown', $first);
        $this->assertStringContainsString('rows 1&ndash;100 of 5000', $first);
        // critical first: the whole first page is flood gaps (the check ids also appear in the
        // legend and the filter dropdown, so the rows are counted by their severity class)
        $this->assertSame(100, substr_count($first, '<tr class="danger">'));
        $this->assertSame(0, substr_count($first, '<tr class="warning">'));

        $filtered = $tab('?severity=warning')->getContent();
        $this->assertStringContainsString('1000 of 5000 shown', $filtered);
        $this->assertSame(100, substr_count($filtered, '<tr class="warning">'));
        $this->assertSame(0, substr_count($filtered, '<tr class="danger">'));

        $this->assertStringContainsString('rows 4901&ndash;5000 of 5000', $tab('?page=99')->getContent());   // clamped, not a 404
        $this->assertStringContainsString('1 of 5000 shown', $tab('?q=leaf-4242')->getContent());
        $this->assertStringContainsString('4000 of 5000 shown', $tab('?check=vni-flood-gap')->getContent());

        // a hundred times the issues must not cost more queries
        $small = $this->fabricWithIssues(50);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get("/plugin/netconf/fabric/$small/checks")->assertOk();
        $fewQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($fewQueries, $queries, "5,000 issues cost $queries queries, 50 cost $fewQueries");
    }

    /**
     * An issue can involve every member of the fabric — `vni-irb-partial` names all carriers —
     * and core's deviceLink() carries a ~2 kB tooltip per link. On the production fabric that
     * made 88 issues a 1.9 MB page; the VNI tab had already switched to plain links for the
     * same reason.
     */
    public function testAnIssueThatNamesEveryMemberDoesNotBlowUpThePage(): void
    {
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));
        $fabric = $this->fabricWithIssues(100);
        $devices = [];
        for ($i = 0; $i < 12; $i++) {
            $devices[] = Device::factory()->create(['hostname' => "member-$i.example.net", 'os' => 'junos'])->device_id;
        }
        $links = [];
        foreach (DB::table(IssueStore::TABLE)->where('fabric_id', $fabric)->pluck('id') as $id) {
            foreach ($devices as $deviceId) {
                $links[] = ['issue_id' => $id, 'device_id' => $deviceId];
            }
        }
        foreach (array_chunk($links, 500) as $chunk) {
            DB::table(IssueStore::DEVICE_TABLE)->insert($chunk);
        }

        $page = $this->get("/plugin/netconf/fabric/$fabric/checks")->assertOk()->getContent();

        $this->assertLessThan(400_000, strlen($page), '100 issues × 12 devices');
        $this->assertStringContainsString('and 6 more', $page, 'the rest of the members are counted, not linked');
        $this->assertSame(600, substr_count($page, 'class="list-device"'), '6 links on each of the 100 rows');
    }

    /**
     * 12 leaves, every one of them carrying all 285 VNIs, three of them announcing every VNI
     * and the others only their own one — the production shape, where 3 × 285 + 9 = 864 of the
     * 3,420 (leaf, VNI) pairs are advertised and the other 2,556 are instantiated and silent.
     */
    private function buildTemplatedFabric(): void
    {
        $now = now();
        $ips = [];
        $devices = [];
        for ($i = 1; $i <= self::LEAVES; $i++) {
            $devices[] = Device::factory()->create(['hostname' => "templated-leaf$i.example.net", 'os' => 'junos'])->device_id;
            $ips[] = '192.51.100.' . $i;
        }

        /** @var array<int, list<string>> $announced vni => the leaves that announce it */
        $announced = [];
        for ($v = 0; $v < self::VNIS; $v++) {
            $vni = 10000 + $v;
            for ($n = 0; $n < self::ANNOUNCE_ALL; $n++) {
                $announced[$vni][] = $ips[$n];
            }
            $owner = self::ANNOUNCE_ALL + ($v % (self::LEAVES - self::ANNOUNCE_ALL));
            $announced[$vni][] = $ips[$owner];   // one more leaf has this VNI live
        }

        foreach ($devices as $n => $deviceId) {
            $rows = ['vni' => [], 'neighbor' => [], 'tunnel' => [], 'vni_vtep' => []];
            foreach ($ips as $m => $peer) {
                if ($m !== $n) {
                    $rows['neighbor'][] = ['device_id' => $deviceId, 'instance' => 'MACVRF', 'neighbor_ip' => $peer, 'router_id' => $ips[$n], 'last_seen' => $now];
                    $rows['tunnel'][] = ['device_id' => $deviceId, 'remote_vtep_ip' => $peer, 'ifname' => "vtep.327$m", 'last_seen' => $now];
                }
            }
            for ($v = 0; $v < self::VNIS; $v++) {
                $vni = 10000 + $v;
                $rows['vni'][] = ['device_id' => $deviceId, 'vni' => $vni, 'instance' => 'MACVRF', 'source_vtep' => $ips[$n], 'last_seen' => $now];
                foreach ($announced[$vni] as $peer) {
                    if ($peer !== $ips[$n]) {
                        $rows['vni_vtep'][] = ['device_id' => $deviceId, 'vni' => $vni, 'remote_vtep_ip' => $peer, 'instance' => 'MACVRF', 'last_seen' => $now];
                    }
                }
            }
            foreach ($rows as $table => $insert) {
                foreach (array_chunk($insert, 500) as $chunk) {
                    DB::table(TableSchema::tableName($table))->insert($chunk);
                }
            }
            DB::table('netconf_device_status')->insert(['device_id' => $deviceId, 'last_ok' => $now, 'consecutive_failures' => 0]);
        }
    }

    /** A fabric with $count stored issues: 80% critical flood gaps, 20% warning stale entries. */
    private function fabricWithIssues(int $count): int
    {
        $key = '192.51.101.' . (DB::table(TableSchema::tableName('fabric'))->count() + 1);
        $fabric = (int) DB::table(TableSchema::tableName('fabric'))->insertGetId(['name' => "Storm $key", 'key' => $key, 'auto' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $device = Device::factory()->create(['hostname' => 'leaf-4242.example.net', 'os' => 'junos'])->device_id;
        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $critical = $i % 5 !== 4;
            $check = $critical ? 'vni-flood-gap' : 'vni-stale-flood';
            $subject = sprintf('%d/1>2', 10000 + $i);
            $rows[] = [
                'fabric_id' => $fabric,
                'issue_key' => substr(md5("$fabric/$check/$subject"), 0, 32) . '-' . $i,
                'check' => $check,
                'severity' => $critical ? 'critical' : 'warning',
                'subject' => $subject,
                'message' => sprintf('VNI %d: flood list of leaf-a lacks leaf-b', 10000 + $i),
                'details' => json_encode(['vni' => 10000 + $i]),
                'first_seen' => $now,
                'last_seen' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(IssueStore::TABLE)->insert($chunk);
        }
        // one issue names the device, so the needle search has exactly one hit over 5,000 rows
        $first = (int) DB::table(IssueStore::TABLE)->where('fabric_id', $fabric)->min('id');
        DB::table(IssueStore::DEVICE_TABLE)->insert(['issue_id' => $first, 'device_id' => $device]);

        return $fabric;
    }
}
