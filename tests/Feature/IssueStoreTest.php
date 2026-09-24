<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\Checks\Issue;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueStore;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * IssueStore against the issue tables (F5 5): a subject longer than the column used to be
 * inserted under a truncated key that the next resolve's lookup missed, so the second sync
 * hit the unique index and every later resolve failed. Keys are digests now; rows with
 * the old key shape are re-keyed silently on the first sync.
 */
final class IssueStoreTest extends LibrenmsTestCase
{
    public function testALongSubjectSyncsRepeatedlyAndKeepsFirstSeen(): void
    {
        $fabricId = $this->fabric();
        $device = Device::factory()->create(['hostname' => 'leaf-a.example.net']);
        $prefix = str_repeat('00:11:22:33:44:55:00:00:01:00/', 7);
        $issues = fn (string $message) => [
            new Issue('esi-lag-down', Issue::CRITICAL, $prefix . '/leaf-a', "$message a", [$device->device_id]),
            new Issue('esi-lag-down', Issue::CRITICAL, $prefix . '/leaf-b', "$message b", [$device->device_id]),
        ];
        $store = new IssueStore;
        $events = fn () => DB::table('eventlog')->where('type', IssueStore::EVENT_TYPE)->count();

        // two subjects that agree on their first 191 characters are two issues
        $this->assertSame(['total' => 2, 'new' => 2, 'cleared' => 0, 'changed' => 0], $store->sync($fabricId, $issues('first'), '2026-09-22 10:00:00'));
        $rows = DB::table(IssueStore::TABLE)->where('fabric_id', $fabricId)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(191, mb_strlen($rows[0]->subject));
        $this->assertSame(2, $events());

        // the same findings again: found by key, first_seen kept, no eventlog entry, no duplicate-key error
        $this->assertSame(['total' => 2, 'new' => 0, 'cleared' => 0, 'changed' => 0], $store->sync($fabricId, $issues('second'), '2026-09-22 10:05:00'));
        $again = DB::table(IssueStore::TABLE)->where('fabric_id', $fabricId)->orderBy('id')->get();
        $this->assertSame($rows->pluck('id')->all(), $again->pluck('id')->all());
        $this->assertSame('2026-09-22 10:00:00', $again[0]->first_seen);
        $this->assertSame('2026-09-22 10:05:00', $again[0]->last_seen);
        $this->assertSame('second a', $again[0]->message);
        $this->assertSame(2, $events());

        // one of them clears: the eventlog names the check from the row, not from the key
        $this->assertSame(['total' => 1, 'new' => 0, 'cleared' => 1, 'changed' => 0], $store->sync($fabricId, [$issues('third')[0]], '2026-09-22 10:10:00'));
        $this->assertSame('EVPN fabric check esi-lag-down cleared: second b', DB::table('eventlog')->where('type', IssueStore::EVENT_TYPE)->orderByDesc('event_id')->value('message'));
    }

    /**
     * Plan §10.8: the resolver runs on every member poll that wrote rows, inside one
     * transaction, and re-wrote every open issue of the fabric each time — 22,867 UPDATEs per
     * resolve × 12 leaves per cycle on the first production fabric. Only what changed is
     * written now, and being seen again costs one statement for the whole fabric.
     */
    public function testASecondSyncOfUnchangedIssuesCostsOneStatement(): void
    {
        $fabricId = $this->fabric();
        $device = Device::factory()->create(['hostname' => 'leaf-a.example.net']);
        $issues = fn (string $message) => array_map(
            fn (int $i) => new Issue('vni-flood-gap', Issue::CRITICAL, "1000$i/1>2", "$message $i", [$device->device_id]),
            range(1, 20),
        );
        $store = new IssueStore;
        $store->sync($fabricId, $issues('gap'), '2026-09-24 10:00:00');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $store->sync($fabricId, $issues('gap'), '2026-09-24 10:05:00');
        $writes = array_values(array_filter(array_map(fn (array $q) => (string) $q['query'], DB::getQueryLog()), fn (string $q) => str_starts_with($q, 'update') || str_starts_with($q, 'insert') || str_starts_with($q, 'delete')));
        DB::disableQueryLog();

        $this->assertSame(['total' => 20, 'new' => 0, 'cleared' => 0, 'changed' => 0], $result);
        $this->assertCount(1, $writes, "20 unchanged issues wrote:\n" . implode("\n", $writes));
        $this->assertStringStartsWith('update `' . IssueStore::TABLE . '` set `last_seen`', $writes[0]);
        $this->assertSame(
            ['2026-09-24 10:05:00'],
            DB::table(IssueStore::TABLE)->where('fabric_id', $fabricId)->distinct()->pluck('last_seen')->all(),
            'every issue was still seen'
        );

        // a changed message is written, and only that one
        DB::flushQueryLog();
        DB::enableQueryLog();
        $changed = $issues('gap');
        $changed[0] = new Issue('vni-flood-gap', Issue::CRITICAL, '10001/1>2', 'gap 1 (now on ae7)', [$device->device_id]);
        $store->sync($fabricId, $changed, '2026-09-24 10:10:00');
        $updates = array_values(array_filter(array_map(fn (array $q) => (string) $q['query'], DB::getQueryLog()), fn (string $q) => str_starts_with($q, 'update')));
        DB::disableQueryLog();

        $this->assertCount(2, $updates, "one row plus the bulk last_seen:\n" . implode("\n", $updates));
        $this->assertSame('gap 1 (now on ae7)', DB::table(IssueStore::TABLE)->where('subject', '10001/1>2')->value('message'));
    }

    public function testRowsWithTheOldKeyShapeAreReKeyedWithoutAnEventlogEntry(): void
    {
        $fabricId = $this->fabric();
        $device = Device::factory()->create(['hostname' => 'leaf-a.example.net']);
        $subject = str_repeat('00:11:22:33:44:55:00:00:01:00/', 7) . '/leaf-a';
        $legacyId = (int) DB::table(IssueStore::TABLE)->insertGetId([
            'fabric_id' => $fabricId, 'issue_key' => Issue::legacyKey('esi-lag-down', $subject), 'check' => 'esi-lag-down', 'severity' => Issue::CRITICAL,
            'subject' => mb_substr($subject, 0, 191), 'message' => 'old', 'details' => null, 'first_seen' => '2026-09-21 09:00:00', 'last_seen' => '2026-09-21 09:00:00',
        ]);
        DB::table(IssueStore::DEVICE_TABLE)->insert(['issue_id' => $legacyId, 'device_id' => $device->device_id]);
        $shortId = (int) DB::table(IssueStore::TABLE)->insertGetId([
            'fabric_id' => $fabricId, 'issue_key' => Issue::legacyKey('unknown-vtep', '192.0.2.9'), 'check' => 'unknown-vtep', 'severity' => Issue::WARNING,
            'subject' => '192.0.2.9', 'message' => 'old', 'details' => null, 'first_seen' => '2026-09-21 09:00:00', 'last_seen' => '2026-09-21 09:00:00',
        ]);

        $result = (new IssueStore)->sync($fabricId, [
            new Issue('esi-lag-down', Issue::CRITICAL, $subject, 'new', [$device->device_id]),
            new Issue('unknown-vtep', Issue::WARNING, '192.0.2.9', 'new'),
        ], '2026-09-22 10:00:00');

        $this->assertSame(['total' => 2, 'new' => 0, 'cleared' => 0, 'changed' => 0], $result);
        $this->assertSame(0, DB::table('eventlog')->where('type', IssueStore::EVENT_TYPE)->count());
        $long = DB::table(IssueStore::TABLE)->where('id', $legacyId)->first();
        $this->assertSame(Issue::keyFor('esi-lag-down', $subject), $long->issue_key);
        $this->assertSame(['2026-09-21 09:00:00', '2026-09-22 10:00:00', 'new'], [$long->first_seen, $long->last_seen, $long->message]);
        $this->assertSame(Issue::keyFor('unknown-vtep', '192.0.2.9'), DB::table(IssueStore::TABLE)->where('id', $shortId)->value('issue_key'));
        $this->assertSame(2, DB::table(IssueStore::TABLE)->where('fabric_id', $fabricId)->count());
    }

    private function fabric(): int
    {
        return (int) DB::table(TableSchema::tableName('fabric'))->insertGetId(['name' => 'Fabric test', 'key' => '192.0.2.1', 'auto' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
