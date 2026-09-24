<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Device;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Fabric\Checks\FabricChecks;
use SafferIt\LibrenmsNetconf\Fabric\Checks\IssueStore;
use SafferIt\LibrenmsNetconf\Support\Pager;

/**
 * Checks tab (plan §7.4 / §7.5): the open issues of a fabric with their label, severity,
 * message, involved devices (linked), first and last seen, plus the counts per severity and
 * per check for the filter bar.
 *
 * Filtering, counting and paging happen in SQL. Loading every row and sorting in PHP worked
 * until a fabric had thousands of issues: on the first production install the unfiltered tab
 * and `?severity=critical` (21,417 rows) died in the compiled Blade with the 128 MB memory
 * limit exhausted, and the warning page took 9.4 s to render 7.19 MB of HTML — so the one page
 * that explains an alarm storm was the one page that failed (plan §10.5). A fabric may
 * legitimately have thousands of issues, so this is independent of the checks that produced
 * them.
 */
final class FabricIssues
{
    /**
     * One page of the fabric's issues, with the counts the filter bar shows.
     *
     * @return array{issues: list<array<string, mixed>>, pager: array{page: int, pages: int, total: int, per_page: int, from: int, to: int}, total: int, shown: int, counts: array{severity: array{critical: int, warning: int, info: int}, checks: array<string, int>}}
     */
    public static function page(int $fabricId, string $q = '', string $severity = '', string $check = '', int|string|null $page = null, int $perPage = Pager::PER_PAGE): array
    {
        $counts = self::counts($fabricId);
        $total = array_sum($counts['severity']);

        $shown = (int) self::filtered($fabricId, $q, $severity, $check)->count();
        $pager = Pager::of($shown, $page, $perPage);

        $rows = self::filtered($fabricId, $q, $severity, $check)
            ->orderByRaw("case `severity` when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderBy('check')->orderBy('subject')->orderBy('id')
            ->offset(Pager::offset($pager))->limit($pager['per_page'])
            ->get(['id', 'check', 'severity', 'subject', 'message', 'details', 'first_seen', 'last_seen']);

        return [
            'issues' => self::withDevices($rows->all()),
            'pager' => $pager,
            'total' => $total,
            'shown' => $shown,
            'counts' => $counts,
        ];
    }

    /**
     * Issues per severity and per check over the whole fabric — what the filter dropdowns
     * offer, so they are not narrowed by the filter itself. One GROUP BY, whatever the size.
     *
     * @return array{severity: array{critical: int, warning: int, info: int}, checks: array<string, int>}
     */
    public static function counts(int $fabricId): array
    {
        $severity = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $checks = [];
        foreach (DB::table(IssueStore::TABLE)->where('fabric_id', $fabricId)->groupBy('severity', 'check')->get([DB::raw('severity'), DB::raw('`check`'), DB::raw('count(*) as n')]) as $row) {
            $n = (int) $row->n;
            $severity[(string) $row->severity] = ($severity[(string) $row->severity] ?? 0) + $n;
            $checks[(string) $row->check] = ($checks[(string) $row->check] ?? 0) + $n;
        }
        ksort($checks);

        return ['severity' => $severity, 'checks' => $checks];
    }

    /**
     * The fabric's issues narrowed by the filter bar: severity and check as equality, the
     * needle over message and subject, the check id and label, and the names of the devices
     * the issue involves (`display` is what Device::displayName() shows).
     */
    private static function filtered(int $fabricId, string $q, string $severity, string $check): Builder
    {
        $query = DB::table(IssueStore::TABLE)->where('fabric_id', $fabricId);
        if ($severity !== '') {
            $query->where('severity', $severity);
        }
        if ($check !== '') {
            $query->where('check', $check);
        }
        $needle = trim($q);
        if ($needle === '') {
            return $query;
        }

        $like = '%' . addcslashes(mb_strtolower($needle), '%_\\') . '%';
        $checks = [];
        foreach (FabricChecks::CHECKS as $id => [$label]) {
            if (str_contains(mb_strtolower($id . ' ' . $label), mb_strtolower($needle))) {
                $checks[] = $id;
            }
        }

        return $query->where(function (Builder $where) use ($like, $checks) {
            $where->whereRaw('lower(message) like ?', [$like])
                ->orWhereRaw('lower(subject) like ?', [$like])
                ->orWhereExists(function (Builder $exists) use ($like) {
                    $exists->from(IssueStore::DEVICE_TABLE . ' as d')
                        ->join('devices as dev', 'dev.device_id', '=', 'd.device_id')
                        ->whereColumn('d.issue_id', IssueStore::TABLE . '.id')
                        ->where(fn (Builder $name) => $name->whereRaw('lower(dev.hostname) like ?', [$like])->orWhereRaw('lower(dev.display) like ?', [$like]));
                });
            if ($checks !== []) {
                $where->orWhereIn('check', $checks);
            }
        });
    }

    /**
     * The view's rows: the stored columns plus the label, description and device models of the
     * page's issues — the device link query is over one page, not over the fabric.
     *
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function withDevices(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $devices = [];
        foreach (DB::table(IssueStore::DEVICE_TABLE)->whereIn('issue_id', array_map(fn ($row) => (int) $row->id, $rows))->get() as $link) {
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

        return $issues;
    }
}
