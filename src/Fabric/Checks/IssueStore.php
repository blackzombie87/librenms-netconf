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
     * @param  list<Issue>  $issues
     * @return array{total: int, new: int, cleared: int, changed: int}
     */
    public function sync(int $fabricId, array $issues, string $now): array
    {
        $existing = DB::table(self::TABLE)->where('fabric_id', $fabricId)->get(['id', 'issue_key', 'severity', 'message'])->keyBy('issue_key');
        $devices = [];
        foreach (DB::table(self::DEVICE_TABLE)->whereIn('issue_id', $existing->pluck('id')->all() ?: [0])->get() as $row) {
            $devices[(int) $row->issue_id][] = (int) $row->device_id;
        }

        $seen = [];
        $new = 0;
        $changed = 0;
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
                $id = (int) DB::table(self::TABLE)->insertGetId([
                    'fabric_id' => $fabricId,
                    'issue_key' => mb_substr($key, 0, 191),
                    'check' => $issue->check,
                    'severity' => $issue->severity,
                    'subject' => mb_substr($issue->subject, 0, 191),
                    'message' => $issue->message,
                    'details' => $issue->details === [] ? null : json_encode($issue->details),
                    'first_seen' => $now,
                    'last_seen' => $now,
                ]);
                $this->writeDevices($id, $ids);
                $this->logTo($ids, self::severity($issue->severity), sprintf('EVPN fabric check %s: %s', $issue->check, $issue->message));
                $new++;
                continue;
            }

            $update = ['last_seen' => $now, 'message' => $issue->message, 'details' => $issue->details === [] ? null : json_encode($issue->details)];
            if ((string) $row->severity !== $issue->severity) {
                $update['severity'] = $issue->severity;
                $this->logTo($ids, self::severity($issue->severity), sprintf('EVPN fabric check %s is now %s: %s', $issue->check, $issue->severity, $issue->message));
                $changed++;
            }
            DB::table(self::TABLE)->where('id', $row->id)->update($update);
            $had = $devices[(int) $row->id] ?? [];
            sort($had);
            if ($had !== $ids) {
                DB::table(self::DEVICE_TABLE)->where('issue_id', $row->id)->delete();
                $this->writeDevices((int) $row->id, $ids);
            }
        }

        $cleared = 0;
        foreach ($existing as $key => $row) {
            if (isset($seen[$key])) {
                continue;
            }
            $ids = $devices[(int) $row->id] ?? [];
            DB::table(self::DEVICE_TABLE)->where('issue_id', $row->id)->delete();
            DB::table(self::TABLE)->where('id', $row->id)->delete();
            $this->logTo($ids, Severity::Ok, sprintf('EVPN fabric check %s cleared: %s', explode('|', (string) $key, 2)[0], $row->message));
            $cleared++;
        }

        return ['total' => count($seen), 'new' => $new, 'cleared' => $cleared, 'changed' => $changed];
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
