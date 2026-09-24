<?php

namespace SafferIt\LibrenmsNetconf\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricIssues;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricMembers;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\EsiMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricSummary;
use SafferIt\LibrenmsNetconf\Fabric\View\MacSearch;
use SafferIt\LibrenmsNetconf\Fabric\View\OverlaySessions;
use SafferIt\LibrenmsNetconf\Fabric\View\Topology;
use SafferIt\LibrenmsNetconf\Support\Pager;
use SafferIt\LibrenmsNetconf\Fabric\View\TunnelMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\VniMatrix;

/**
 * EVPN fabric pages (plan §7.4): the fabric list, one page per fabric with tabs, and the
 * global MAC search. Read access needs global-read (a fabric spans devices); renaming a
 * fabric needs the admin role.
 */
class FabricController extends Controller
{
    /** Tab id => label; the order is the tab bar. */
    public const TABS = [
        'overview' => 'Overview',
        'members' => 'Members',
        'bgp' => 'BGP overlay',
        'vnis' => 'VNIs',
        'esis' => 'ESI / multihoming',
        'tunnels' => 'Tunnels',
        'macs' => 'MACs',
        'checks' => 'Checks',
    ];

    public function index(): View
    {
        return view('netconf::fabrics', [
            'fabrics' => FabricSummary::all(),
            'fabric_enabled' => NetconfService::fabricEnabled(),
            'can_admin' => Gate::allows('admin'),
        ]);
    }

    public function show(Request $request, int $fabric, string $tab = 'overview'): View
    {
        $summary = FabricSummary::one($fabric);
        abort_if($summary === null, 404, 'No such fabric');
        abort_unless(isset(self::TABS[$tab]), 404, 'No such tab');

        $nodes = FabricNodes::forFabric($fabric);
        $data = [
            'fabric' => $summary,
            'tab' => $tab,
            'tabs' => self::TABS,
            'nodes' => $nodes,
            'fabric_enabled' => NetconfService::fabricEnabled(),
            'can_admin' => Gate::allows('admin'),
            'result' => $request->session()->get('netconf_fabric_result'),
        ];

        return view('netconf::fabric', $data + $this->tabData($tab, $summary, $nodes, $request));
    }

    /** Global MAC / IP / VNI search over every opted-in leaf and the core FDB / ARP tables. */
    public function mac(Request $request): View
    {
        $q = (string) $request->query('q', '');

        return view('netconf::evpn-mac', [
            'q' => $q,
            'search' => MacSearch::run($q),
            'fabric_enabled' => NetconfService::fabricEnabled(),
            'can_admin' => Gate::allows('admin'),
        ]);
    }

    public function update(Request $request, int $fabric): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'notes' => 'nullable|string|max:2000',
        ]);
        // exists() first: MySQL reports 0 changed rows for a no-op save within the same
        // second, which is not a missing fabric
        abort_unless(DB::table(TableSchema::tableName('fabric'))->where('id', $fabric)->exists(), 404, 'No such fabric');
        DB::table(TableSchema::tableName('fabric'))->where('id', $fabric)->update([
            'name' => trim($data['name']),
            'notes' => trim((string) ($data['notes'] ?? '')) === '' ? null : trim((string) $data['notes']),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return redirect()->route('netconf.fabric', [$fabric, 'overview'])->with('netconf_fabric_result', ['type' => 'success', 'text' => 'Saved.']);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function tabData(string $tab, array $summary, FabricNodes $nodes, Request $request): array
    {
        $deviceIds = $nodes->deviceIds();

        return match ($tab) {
            'overview' => $this->overview($summary['id'], $nodes),
            'members' => ['members' => FabricMembers::forFabric($summary['id'])],
            'bgp' => $this->bgp($nodes, $deviceIds),
            'vnis' => $this->vnis($nodes, $request),
            'esis' => $this->esis($nodes, $request),
            'tunnels' => ['tunnels' => TunnelMatrix::forFabric($nodes)],
            'macs' => ['search' => MacSearch::run((string) $request->query('q', ''), $deviceIds), 'q' => (string) $request->query('q', '')],
            'checks' => $this->checks($summary['id'], $request),
            default => [],
        };
    }

    /**
     * The topology, twice: the static SVG layout and the same graph as nodes and edges for the
     * interactive map, which starts its physics from the layout's coordinates.
     *
     * @return array<string, mixed>
     */
    private function overview(int $fabricId, FabricNodes $nodes): array
    {
        $topology = Topology::forFabric($fabricId, $nodes);

        return ['topology' => $topology, 'graph' => Topology::graph($topology, $nodes)];
    }

    /**
     * @return array<string, mixed>
     */
    private function vnis(FabricNodes $nodes, Request $request): array
    {
        $rows = VniMatrix::forFabric($nodes);
        $q = (string) $request->query('q', '');
        $issueCount = count(array_filter($rows, fn ($r) => $r['flags'] !== []));
        // the full list grows with every member (285 rows on one leaf here), so the tab opens
        // on the rows that need attention whenever there are any; "show all" is ?issues=0
        $issues = $request->query('issues') === null ? $issueCount > 0 : (bool) $request->query('issues');
        $filtered = VniMatrix::filter($rows, $q, $issues);
        $pager = Pager::slice($filtered, $request->query('page'));

        return [
            'vnis' => $pager['rows'],
            'vni_pager' => $pager,
            'vni_total' => count($rows),
            'vni_shown' => count($filtered),
            'vni_issues' => $issueCount,
            'q' => $q,
            'issues_only' => $issues,
            'issues_default' => $issueCount > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function esis(FabricNodes $nodes, Request $request): array
    {
        $rows = EsiMatrix::forFabric($nodes);
        $q = (string) $request->query('q', '');
        $issues = (bool) $request->query('issues', '');

        return [
            'esis' => EsiMatrix::filter($rows, $q, $issues, $nodes),
            'esi_total' => count($rows),
            'esi_issues' => count(array_filter($rows, fn ($r) => $r['flags'] !== [])),
            'q' => $q,
            'issues_only' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checks(int $fabricId, Request $request): array
    {
        $q = (string) $request->query('q', '');
        $severity = (string) $request->query('severity', '');
        $check = (string) $request->query('check', '');
        // filtered, counted and paged in SQL: a fabric may hold thousands of issues and the
        // unpaged page died in the Blade at 128 MB (plan §10.5)
        $page = FabricIssues::page($fabricId, $q, $severity, $check, $request->query('page'));

        return [
            'issues' => $page['issues'],
            'issue_pager' => $page['pager'],
            'issue_total' => $page['total'],
            'issue_shown' => $page['shown'],
            'issue_counts' => $page['counts'],
            'q' => $q,
            'severity' => $severity,
            'check' => $check,
        ];
    }

    /**
     * @param  list<int>  $deviceIds
     * @return array<string, mixed>
     */
    private function bgp(FabricNodes $nodes, array $deviceIds): array
    {
        $sessions = OverlaySessions::forDevices($deviceIds);
        $missing = OverlaySessions::missing($sessions, $nodes->deviceNodes(), $nodes->collectedIds());
        $byDevice = [];
        foreach ($sessions as $s) {
            $byDevice[$s['device_id']][] = $s;
        }
        foreach ($missing as $m) {
            $byDevice[$m['device_id']][] = ['device_id' => $m['device_id'], 'peer_ip' => $m['peer_ip'], 'missing' => true, 'have' => $m['have'], 'state' => null, 'up' => false, 'uptime' => null, 'flaps' => null, 'remote_as' => null, 'description' => null, 'local_ip' => null, 'source' => [], 'rib' => null, 'routes' => null];
        }
        ksort($byDevice);

        return [
            'sessions_by_device' => $byDevice,
            'sessions_total' => count($sessions),
            'sessions_down' => count(array_filter($sessions, fn ($s) => $s['state'] !== null && ! $s['up'])),
            'sessions_missing' => count($missing),
        ];
    }
}
