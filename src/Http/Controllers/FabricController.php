<?php

namespace SafferIt\LibrenmsNetconf\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
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
     * The overview picture: one view, the eagle SVG. `topo` is not read — an `?topo=interactive`
     * or `?topo=static` bookmark renders this picture rather than 404ing or bouncing through a
     * redirect, because the two older renderings are gone and the parameter is harmless once
     * ignored. The response carries no vis payload and no one-row SVG.
     *
     * @return array<string, mixed>
     */
    private function overview(int $fabricId, FabricNodes $nodes, Request $request): array
    {
        $input = FabricTopologyInput::load($fabricId, $nodes);
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
        $saved = self::savedLayout($fabricId, self::placedIds($shape, $input, $eagle));

        return [
            'shape' => $shape,
            'eagle' => $eagle,
            'highlight' => $highlight,
            'eagle_input' => $input,
            'focus' => (string) $request->query('focus', ''),
            'view' => $view,
        ] + $saved;
    }

    /**
     * Every id `place()` could have drawn a card for: the members it was given plus the
     * `far:` cards it made for the unmonitored addresses on the spine tier.
     *
     * A collapsed site still lists its members, so collapsing does not change this set and
     * cannot turn a saved arrangement into a mismatch.
     *
     * @param  array<string, mixed>  $shape
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $eagle
     * @return list<string>
     */
    private static function placedIds(array $shape, array $input, array $eagle): array
    {
        $tier = $shape['tier'] ?? [];
        $ids = [];
        foreach ($input['nodes'] as $n) {
            if (isset($tier[(string) $n['ip']])) {
                $ids[] = (string) $n['ip'];
            }
        }
        foreach ($eagle['nodes'] as $id => $node) {
            if ($node['kind'] === 'far') {
                $ids[] = (string) $id;
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * The arrangement someone saved for this fabric, when it still describes this fabric.
     *
     * A document whose member list no longer matches is not applied and is **not** deleted: a
     * transient load that saw a different set must not wipe what an operator arranged. The next
     * explicit save or reset is what writes.
     *
     * @param  list<string>  $ids
     * @return array{layout?: array<string, mixed>, layout_by?: string|null, layout_at?: string}
     */
    private static function savedLayout(int $fabricId, array $ids): array
    {
        $row = DB::table(TableSchema::tableName('fabric_layout'))->where('fabric_id', $fabricId)->first();
        if ($row === null) {
            return [];
        }
        $document = json_decode((string) $row->positions, true);
        if (! is_array($document) || ($document['members'] ?? null) !== $ids) {
            return [];
        }

        return [
            'layout' => $document,
            'layout_by' => $row->user_id === null ? null : DB::table('users')->where('user_id', $row->user_id)->value('username'),
            'layout_at' => (string) $row->updated_at,
        ];
    }

    /**
     * Save or forget the arrangement of this fabric's overview picture.
     *
     * One row per fabric and last write wins: two operators dragging at the same time is
     * accepted, and the row records who wrote it and when so the page can say so. The body is
     * coordinates and ids this user has already been shown, and every one of them is checked
     * against a fresh `place()` before anything is written.
     */
    public function layout(Request $request, int $fabric): Response
    {
        $table = TableSchema::tableName('fabric_layout');
        abort_unless(DB::table(TableSchema::tableName('fabric'))->where('id', $fabric)->exists(), 404, 'No such fabric');

        if ($request->input('reset') === true) {
            DB::table($table)->where('fabric_id', $fabric)->delete();

            return response()->noContent();
        }

        $nodes = FabricNodes::forFabric($fabric);
        $input = FabricTopologyInput::load($fabric, $nodes);
        $shape = FabricShape::classify($input['members'], $input['overlay'], $input['shared_far_ends'], $input['missing']);
        $eagle = EagleLayout::place($shape, $input['nodes'], $input['underlay'], $shape['overlay_edges'], $input['shared_far_ends'], $input['esi_pairs']);
        $document = self::validLayout($request->all(), self::placedIds($shape, $input, $eagle), array_column($eagle['groups'], 'key'));
        abort_if($document === null, 400, 'Not a layout of this fabric');

        DB::table($table)->updateOrInsert(['fabric_id' => $fabric], [
            'positions' => json_encode($document),
            'user_id' => auth()->id(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return response()->noContent();
    }

    /**
     * The posted document, or null if it is not one. Nothing is written before this returns.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $ids  the cards this fabric has
     * @param  list<string>  $siteKeys  the compounds it has
     * @return array{v: int, members: list<string>, nodes: array<string, array{x: int, y: int}>, groups: array<string, array{x: int, y: int}>}|null
     */
    private static function validLayout(array $body, array $ids, array $siteKeys): ?array
    {
        if (array_diff(array_keys($body), ['v', 'members', 'nodes', 'groups']) !== [] || ($body['v'] ?? null) !== 1) {
            return null;
        }
        $members = $body['members'] ?? null;
        if (! is_array($members) || array_map('strval', array_values($members)) !== $ids) {
            return null;
        }
        $nodes = $body['nodes'] ?? [];
        $groups = $body['groups'] ?? [];
        if (! is_array($nodes) || ! is_array($groups) || count($nodes) + count($groups) > 512) {
            return null;
        }

        $point = function (mixed $value): ?array {
            if (! is_array($value) || array_diff(array_keys($value), ['x', 'y']) !== [] || count($value) !== 2) {
                return null;
            }
            $out = [];
            foreach (['x', 'y'] as $axis) {
                if (! is_numeric($value[$axis]) || ! is_finite((float) $value[$axis]) || abs((float) $value[$axis]) > 100000) {
                    return null;
                }
                $out[$axis] = (int) round((float) $value[$axis]);
            }

            return $out;
        };

        $clean = [];
        foreach ([['nodes', $nodes, $ids], ['groups', $groups, $siteKeys]] as [$field, $values, $allowed]) {
            $clean[$field] = [];
            foreach ($values as $key => $value) {
                // the body is never rendered as HTML, and a key that is not one of this
                // fabric's own ids is not stored under any circumstances
                if (! is_string($key) || ! in_array($key, $allowed, true) || str_contains($key, '<') || str_contains($key, '>')) {
                    return null;
                }
                $coordinates = $point($value);
                if ($coordinates === null) {
                    return null;
                }
                $clean[$field][$key] = $coordinates;
            }
        }

        return ['v' => 1, 'members' => $ids, 'nodes' => $clean['nodes'], 'groups' => $clean['groups']];
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
        $vniTo = $request->query('vni_to');
        $live = $request->session()->get('netconf_trace');

        $result = null;
        // a live POST redirects back here with its own pair on the query string, so the boxes
        // keep it — but the stored tables have nothing to add to a walk that just asked the
        // devices themselves, and the blade would not show it. Do not pay for that query.
        if ($from !== '' && $to !== '' && ! is_array($live)) {
            $result = (new TraceRunner($fabricId, $nodes))->run(
                $from,
                $to,
                is_numeric($vni) ? (int) $vni : null,
                is_numeric($vniTo) ? (int) $vniTo : null,
            );
        }

        return [
            'trace_from' => $from,
            'trace_to' => $to,
            'trace_vni' => is_numeric($vni) ? (string) $vni : '',
            'trace_vni_to' => is_numeric($vniTo) ? (string) $vniTo : '',
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
            'vni_to' => 'nullable|integer|min:0',
        ]);
        $nodes = FabricNodes::forFabric($fabric);
        abort_if($nodes->deviceIds() === [], 404, 'No such fabric');

        $runner = new TraceRunner($fabric, $nodes);
        $walker = TraceRunner::walker();
        $result = $runner->run(trim($data['from']), trim($data['to']), $data['vni'] ?? null, $data['vni_to'] ?? null, live: true, walker: $walker);
        $result['log'] = $walker->log;

        // the pair goes back on the query string, not into flashed input: the boxes are filled
        // from there, the result is shareable and reloadable as a graph trace, and the next
        // click on "Trace live" has the pair that was actually walked in front of it
        return redirect()->route('netconf.fabric', array_filter([
            $fabric, 'trace',
            'from' => trim($data['from']),
            'to' => trim($data['to']),
            'vni' => $data['vni'] ?? null,
            'vni_to' => $data['vni_to'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''))
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
