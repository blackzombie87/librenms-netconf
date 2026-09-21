<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Fabric\Checks\FabricChecks;
use SafferIt\LibrenmsNetconf\Fabric\Checks\Issue;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueStore;

/**
 * Checks tab (plan §7.4 / §7.5): the open issues of a fabric with their label, severity,
 * message, involved devices (linked), first and last seen, plus the counts per severity
 * and per check for the filter bar.
 */
final class FabricIssues
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forFabric(int $fabricId): array
    {
        $rows = DB::table(IssueStore::TABLE)->where('fabric_id', $fabricId)->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $devices = [];
        foreach (DB::table(IssueStore::DEVICE_TABLE)->whereIn('issue_id', $rows->pluck('id')->all())->get() as $link) {
            $devices[(int) $link->issue_id][] = (int) $link->device_id;
        }
        $deviceIds = array_values(array_unique(array_merge(...array_values($devices) ?: [[]])));
        /** @var \Illuminate\Support\Collection<int, Device> $models */
        $models = Device::query()->whereIn('device_id', $deviceIds ?: [0])->get()->keyBy('device_id');

        $issues = [];
        foreach ($rows as $row) {
            $check = (string) $row->check;
            $issues[] = [
                'id' => (int) $row->id,
                'check' => $check,
                'label' => FabricChecks::CHECKS[$check][0] ?? $check,
                'description' => FabricChecks::CHECKS[$check][2] ?? '',
                'severity' => (string) $row->severity,
                'subject' => (string) $row->subject,
                'message' => (string) $row->message,
                'details' => $row->details === null ? [] : (json_decode((string) $row->details, true) ?: []),
                'devices' => array_values(array_filter(array_map(fn (int $id) => $models->get($id), $devices[(int) $row->id] ?? []))),
                'first_seen' => (string) $row->first_seen,
                'last_seen' => (string) $row->last_seen,
            ];
        }
        usort($issues, fn ($a, $b) => [Issue::rank($a['severity']), $a['check'], $a['subject']] <=> [Issue::rank($b['severity']), $b['check'], $b['subject']]);

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array{severity: array{critical: int, warning: int, info: int}, checks: array<string, int>}
     */
    public static function counts(array $issues): array
    {
        $severity = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $checks = [];
        foreach ($issues as $issue) {
            $severity[$issue['severity']] = ($severity[$issue['severity']] ?? 0) + 1;
            $checks[$issue['check']] = ($checks[$issue['check']] ?? 0) + 1;
        }
        ksort($checks);

        return ['severity' => $severity, 'checks' => $checks];
    }

    /**
     * Filter by severity, check id and a case-insensitive needle over message, subject and device names.
     *
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    public static function filter(array $issues, string $q, string $severity, string $check): array
    {
        $needle = mb_strtolower(trim($q));

        return array_values(array_filter($issues, function ($issue) use ($needle, $severity, $check) {
            if ($severity !== '' && $issue['severity'] !== $severity) {
                return false;
            }
            if ($check !== '' && $issue['check'] !== $check) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = [$issue['message'], $issue['subject'], $issue['label'], $issue['check']];
            foreach ($issue['devices'] as $device) {
                $haystack[] = $device->displayName();
                $haystack[] = $device->hostname;
            }
            foreach ($haystack as $text) {
                if (str_contains(mb_strtolower((string) $text), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
