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
use SafferIt\LibrenmsNetconf\Fabric\Trace\TraceRunner;
use SafferIt\LibrenmsNetconf\Fabric\View\EagleLayout;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricIssues;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricMembers;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\EsiMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricShape;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricSummary;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricTopologyInput;
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
    /** The overview rendering `?topo=` selects; absent is the server default. */
    public const TOPO_EAGLE = 'eagle';

    /** Tab id => label; the order is the tab bar. */
    public const TABS = [
        'overview' => 'Overview',
        'members' => 'Members',
        'bgp' => 'BGP overlay',
        'vnis' => 'VNIs',
        'esis' => 'ESI / multihoming',
        'tunnels' => 'Tunnels',
        'macs' => 'MACs',
        'trace' => 'Trace',
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
            'overview' => $this->overview($summary['id'], $nodes, $request),
            'members' => ['members' => FabricMembers::forFabric($summary['id'])],
            'bgp' => $this->bgp($nodes, $deviceIds),
            'vnis' => $this->vnis($nodes, $request),
            'esis' => $this->esis($nodes, $request),
            'tunnels' => ['tunnels' => TunnelMatrix::forFabric($nodes)],
            'macs' => ['search' => MacSearch::run((string) $request->query('q', ''), $deviceIds), 'q' => (string) $request->query('q', '')],
            'trace' => $this->trace($summary['id'], $nodes, $request),
            'checks' => $this->checks($summary['id'], $request),
            default => [],
        };
    }

    /**
     * The overview picture. `?topo=eagle` renders the wrapped, server-laid-out SVG; anything
     * else keeps today's page, which is the static layout plus the same graph as nodes and
     * edges for the interactive map. The two branches share `FabricTopologyInput` and nothing
     * else: the eagle response does not embed the vis JSON, and the default response does not
     * run `EagleLayout`.
     *
     * @return array<string, mixed>
     */
    private function overview(int $fabricId, FabricNodes $nodes, Request $request): array
    {
        $topo = (string) $request->query('topo', '');
        if ($topo !== self::TOPO_EAGLE) {
            $topology = Topology::forFabric($fabricId, $nodes);

            return ['topo' => $topo === 'static' ? 'static' : 'interactive', 'topology' => $topology, 'graph' => Topology::graph($topology, $nodes)];
        }

        $input = FabricTopologyInput::load($fabricId, $nodes, eagle: true);
        $shape = FabricShape::classify($input['members'], $input['overlay'], $input['shared_far_ends'], $input['missing']);
        $view = [
            'collapse' => self::collapseKeys($request),
            'outside' => (bool) $request->query('outside'),
            // null, not [], while the layer is off: the two extra queries have not run, and a
            // summary card that printed "0 attached" would claim they had
            'attached' => $request->query('attached') ? FabricTopologyInput::attached($input['esi_rows'], $nodes) : null,
        ];
        // a trace result is exactly a node and edge id list, which is the whole reason
        // `place()` takes a highlight instead of there being a second picture (plan §11 E-F6)
        $highlight = self::highlight($request);
        $eagle = EagleLayout::place($shape, $input['nodes'], $input['underlay'], $shape['overlay_edges'], $input['shared_far_ends'], $input['esi_pairs'], $view, $highlight);

        return [
            'topo' => self::TOPO_EAGLE,
            'shape' => $shape,
            'eagle' => $eagle,
            'highlight' => $highlight,
            'eagle_input' => $input,
            'focus' => (string) $request->query('focus', ''),
            'view' => $view,
        ];
    }

    /**
     * `collapse[]` is a repeated parameter and never a comma-separated string: a location may
     * itself contain a comma ("BER, hall"), and splitting one would collapse a site named
     * "BER" that the operator never asked about.
     *
     * @return list<string>
     */
    private static function collapseKeys(Request $request): array
    {
        $raw = $request->query('collapse', []);

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? $v : null,
            is_array($raw) ? $raw : [$raw],
        )));
    }

    /**
     * `highlight[]` holds the `link_key` of every hop of a trace; the nodes follow from the
     * hops, so the link is short enough to paste.
     *
     * @return array{nodes: list<string>, edges: list<string>}
     */
    private static function highlight(Request $request): array
    {
        $raw = $request->query('highlight', []);
        $keys = array_values(array_filter(array_map(fn ($v) => is_string($v) && $v !== '' ? $v : null, is_array($raw) ? $raw : [$raw])));
        if ($keys === []) {
            return ['nodes' => [], 'edges' => []];
        }
        $nodes = array_values(array_filter(array_map(fn ($v) => is_string($v) && $v !== '' ? $v : null, (array) $request->query('through', []))));

        return ['nodes' => $nodes, 'edges' => array_map(fn (string $key) => 'edge:underlay:' . $key, $keys)];
    }

    /**
     * The tracer (plan §12). Graph mode runs on this `global-read` page and reads only stored
     * tables; a live walk opens SSH sessions to the devices on the way, so it is an admin
     * POST and its result comes back through the session — the same shape the run-command
     * form uses. Nothing here writes.
     *
     * @return array<string, mixed>
     */
    private function trace(int $fabricId, FabricNodes $nodes, Request $request): array
    {
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        $vni = $request->query('vni');
        $live = $request->session()->get('netconf_trace');

        $result = null;
        if ($from !== '' && $to !== '') {
            $result = (new TraceRunner($fabricId, $nodes))->run($from, $to, is_numeric($vni) ? (int) $vni : null);
        }

        return [
            'trace_from' => $from,
            'trace_to' => $to,
            'trace_vni' => is_numeric($vni) ? (string) $vni : '',
            'trace' => $result,
            'trace_live' => is_array($live) ? $live : null,
        ];
    }

    /**
     * Live mode: admin only, one POST, one result in the session, no state kept anywhere.
     */
    public function traceLive(Request $request, int $fabric): RedirectResponse
    {
        $data = $request->validate([
            'from' => 'required|string|max:64',
            'to' => 'required|string|max:64',
            'vni' => 'nullable|integer|min:0',
        ]);
        $nodes = FabricNodes::forFabric($fabric);
        abort_if($nodes->deviceIds() === [], 404, 'No such fabric');

        $runner = new TraceRunner($fabric, $nodes);
        $walker = TraceRunner::walker();
        $result = $runner->run(trim($data['from']), trim($data['to']), $data['vni'] ?? null, live: true, walker: $walker);
        $result['log'] = $walker->log;

        return redirect()->route('netconf.fabric', [$fabric, 'trace'])
            ->withInput($data)
            ->with('netconf_trace', $result);
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
