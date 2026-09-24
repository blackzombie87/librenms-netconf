<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Checks;

use App\Models\Eventlog;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\Severity;

/**
 * Keeps netconf_evpn_issue in step with the findings of one resolve: new issues are inserted
 * and logged (eventlog type netconf-evpn on every monitored device involved), open ones keep
 * their first_seen and get the current message, cleared ones are deleted and logged as ok.
 * Only changes reach the eventlog, so a standing issue is silent between polls.
 */
class IssueStore
{
    public const TABLE = 'netconf_evpn_issue';

    public const DEVICE_TABLE = 'netconf_evpn_issue_device';

    public const EVENT_TYPE = 'netconf-evpn';

    /**
     * How many issues of one check a resolve may log one by one before the eventlog gets a
     * single summary row instead (plan §10.6). Onboarding twelve leaves wrote 67,335
     * `netconf-evpn` rows in 13 minutes — 37% of that instance's whole eventlog — because
     * every appearing issue is logged on every device it involves. X1 and X2 take ~99% of
     * that volume away but do not bound it: a fabric that really changes, or a member that
     * comes back after an outage, produces a burst again.
     */
    public const LOG_LIMIT = 10;

    /**
     * Eventlog entries of the sync in progress, grouped by check and what happened, so a burst
     * can be summarised instead of written out one by one.
     *
     * @var array<string, array{severity: Severity, entries: list<array{ids: list<int>, message: string}>}>
     */
    private array $pending = [];

    /**
     * @param  list<Issue>  $issues
     * @return array{total: int, new: int, cleared: int, changed: int}
     */
    public function sync(int $fabricId, array $issues, string $now): array
    {
        $existing = DB::table(self::TABLE)->where('fabric_id', $fabricId)->get(['id', 'issue_key', 'check', 'subject', 'severity', 'message', 'details'])->keyBy('issue_key');
        $devices = [];
        foreach (DB::table(self::DEVICE_TABLE)->whereIn('issue_id', $existing->pluck('id')->all() ?: [0])->get() as $row) {
            $devices[(int) $row->issue_id][] = (int) $row->device_id;
        }

        $seen = [];
        $new = 0;
        $changed = 0;
        /** @var list<int> $touch open issues whose only change is that they were seen again */
        $touch = [];
        foreach ($issues as $issue) {
            $key = $issue->key();
            if (isset($seen[$key])) {
                continue;   // the same finding reached from two rows
            }
            $seen[$key] = true;
            $ids = array_values(array_unique($issue->deviceIds));
            sort($ids);
            $row = $existing->get($key);
            if ($row === null) {
                // a row from before the digest keys (check|subject cut at the column): re-keyed
                // in place, so first_seen survives and the eventlog sees no appear/clear pair
                $legacy = Issue::legacyKey($issue->check, $issue->subject);
                $row = $existing->get($legacy);
                if ($row !== null && (string) $row->check === $issue->check && (string) $row->subject === mb_substr($issue->subject, 0, 191)) {
                    DB::table(self::TABLE)->where('id', $row->id)->update(['issue_key' => $key]);
                    $existing->forget($legacy);
                    $existing->put($key, $row);
                } else {
                    $row = null;
                }
            }

            if ($row === null) {
                $id = (int) DB::table(self::TABLE)->insertGetId([
                    'fabric_id' => $fabricId,
                    'issue_key' => $key,
                    'check' => $issue->check,
                    'severity' => $issue->severity,
                    'subject' => mb_substr($issue->subject, 0, 191),
                    'message' => $issue->message,
                    'details' => $issue->details === [] ? null : json_encode($issue->details),
                    'first_seen' => $now,
                    'last_seen' => $now,
                ]);
                $this->writeDevices($id, $ids);
                $this->queueLog($issue->check, 'appeared', $ids, self::severity($issue->severity), sprintf('EVPN fabric check %s: %s', $issue->check, $issue->message));
                $new++;
                continue;
            }

            // an UPDATE per open issue per resolve is what made a big fabric expensive: the
            // resolver runs on every member poll that wrote rows, inside one transaction, so
            // 12 leaves × 22,867 open issues was ~900 writes/s of nothing (plan §10.8)
            $details = $issue->details === [] ? null : json_encode($issue->details);
            $update = [];
            if ((string) $row->message !== $issue->message) {
                $update['message'] = $issue->message;
            }
            if (($row->details === null ? null : (string) $row->details) !== $details) {
                $update['details'] = $details;
            }
            if ((string) $row->severity !== $issue->severity) {
                $update['severity'] = $issue->severity;
                $this->queueLog($issue->check, 'changed severity', $ids, self::severity($issue->severity), sprintf('EVPN fabric check %s is now %s: %s', $issue->check, $issue->severity, $issue->message));
                $changed++;
            }
            if ($update === []) {
                $touch[] = (int) $row->id;
            } else {
                DB::table(self::TABLE)->where('id', $row->id)->update($update + ['last_seen' => $now]);
            }
            $had = $devices[(int) $row->id] ?? [];
            sort($had);
            if ($had !== $ids) {
                DB::table(self::DEVICE_TABLE)->where('issue_id', $row->id)->delete();
                $this->writeDevices((int) $row->id, $ids);
            }
        }

        foreach (array_chunk($touch, 1000) as $chunk) {
            DB::table(self::TABLE)->whereIn('id', $chunk)->update(['last_seen' => $now]);
        }

        $cleared = 0;
        foreach ($existing as $key => $row) {
            if (isset($seen[$key])) {
                continue;
            }
            $ids = $devices[(int) $row->id] ?? [];
            DB::table(self::DEVICE_TABLE)->where('issue_id', $row->id)->delete();
            DB::table(self::TABLE)->where('id', $row->id)->delete();
            $this->queueLog((string) $row->check, 'cleared', $ids, Severity::Ok, sprintf('EVPN fabric check %s cleared: %s', $row->check, $row->message));
            $cleared++;
        }

        $this->flushLog();

        return ['total' => count($seen), 'new' => $new, 'cleared' => $cleared, 'changed' => $changed];
    }

    /**
     * @param  list<int>  $ids
     */
    private function queueLog(string $check, string $what, array $ids, Severity $severity, string $message): void
    {
        $key = $check . '|' . $what;
        $this->pending[$key] ??= ['severity' => $severity, 'entries' => []];
        if ($severity->value > $this->pending[$key]['severity']->value) {
            $this->pending[$key]['severity'] = $severity;
        }
        $this->pending[$key]['entries'][] = ['ids' => $ids, 'message' => $message];
    }

    /**
     * Write what the sync queued: one entry per issue and device while a check stays under
     * LOG_LIMIT, one fabric-level summary above it. An operator reading the eventlog learns the
     * same thing from "1,945 vni-flood-gap issues appeared" as from 21,395 rows, and the
     * Checks tab has the detail.
     */
    private function flushLog(): void
    {
        foreach ($this->pending as $key => $group) {
            [$check, $what] = explode('|', $key, 2);
            if (count($group['entries']) <= self::LOG_LIMIT) {
                foreach ($group['entries'] as $entry) {
                    $this->logTo($entry['ids'], $group['severity'], $entry['message']);
                }

                continue;
            }
            $devices = [];
            foreach ($group['entries'] as $entry) {
                foreach ($entry['ids'] as $id) {
                    $devices[$id] = true;
                }
            }
            $this->logTo([], $group['severity'], sprintf(
                'EVPN fabric check %s: %d issues %s in one resolve on %d device%s — see the fabric\'s Checks tab',
                $check,
                count($group['entries']),
                $what,
                count($devices),
                count($devices) === 1 ? '' : 's',
            ));
        }
        $this->pending = [];
    }

    /**
     * Delete the issues of fabrics that no longer exist (no eventlog: their devices are gone
     * or belong to another fabric now, whose next sync reports them).
     *
     * @param  list<int>  $fabricIds  the fabrics that exist
     */
    public function dropOthers(array $fabricIds): int
    {
        $ids = DB::table(self::TABLE)->whereNotIn('fabric_id', $fabricIds ?: [0])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return 0;
        }
        DB::table(self::DEVICE_TABLE)->whereIn('issue_id', $ids)->delete();

        return DB::table(self::TABLE)->whereIn('id', $ids)->delete();
    }

    /** A device leaves: its issue links go; the next sync recomputes the issues themselves. */
    public function forget(int $deviceId): int
    {
        return DB::table(self::DEVICE_TABLE)->where('device_id', $deviceId)->delete();
    }

    /**
     * Open issues of a device by severity (the "EVPN fabric issues" sensor counts all but info).
     *
     * @return array{critical: int, warning: int, info: int}
     */
    public static function countForDevice(int $deviceId): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $rows = DB::table(self::TABLE . ' as i')->join(self::DEVICE_TABLE . ' as d', 'd.issue_id', '=', 'i.id')
            ->where('d.device_id', $deviceId)->selectRaw('i.severity, count(*) as n')->groupBy('i.severity')->get();
        foreach ($rows as $row) {
            $counts[(string) $row->severity] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * Open issues per fabric by severity.
     *
     * @return array<int, array{critical: int, warning: int, info: int}>
     */
    public static function countByFabric(): array
    {
        $counts = [];
        foreach (DB::table(self::TABLE)->selectRaw('fabric_id, severity, count(*) as n')->groupBy('fabric_id', 'severity')->get() as $row) {
            $counts[(int) $row->fabric_id] ??= ['critical' => 0, 'warning' => 0, 'info' => 0];
            $counts[(int) $row->fabric_id][(string) $row->severity] = (int) $row->n;
        }

        return $counts;
    }

    public static function severity(string $severity): Severity
    {
        return match ($severity) {
            Issue::CRITICAL => Severity::Error,
            Issue::WARNING => Severity::Warning,
            default => Severity::Notice,
        };
    }

    /**
     * @param  list<int>  $ids
     */
    private function writeDevices(int $issueId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        DB::table(self::DEVICE_TABLE)->insert(array_map(fn (int $deviceId) => ['issue_id' => $issueId, 'device_id' => $deviceId], $ids));
    }

    /**
     * One eventlog entry per involved device, or one without a device for fabric-level findings.
     *
     * @param  list<int>  $ids
     */
    protected function logTo(array $ids, Severity $severity, string $message): void
    {
        if ($ids === []) {
            Eventlog::log($message, null, self::EVENT_TYPE, $severity);

            return;
        }
        foreach ($ids as $deviceId) {
            Eventlog::log($message, $deviceId, self::EVENT_TYPE, $severity);
        }
    }
}
