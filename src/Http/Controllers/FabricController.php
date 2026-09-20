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
use SafferIt\LibrenmsNetconf\Fabric\View\FabricMembers;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\EsiMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricSummary;
use SafferIt\LibrenmsNetconf\Fabric\View\MacSearch;
use SafferIt\LibrenmsNetconf\Fabric\View\OverlaySessions;
use SafferIt\LibrenmsNetconf\Fabric\View\Topology;
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
        $updated = DB::table(TableSchema::tableName('fabric'))->where('id', $fabric)->update([
            'name' => trim($data['name']),
            'notes' => trim((string) ($data['notes'] ?? '')) === '' ? null : trim((string) $data['notes']),
            'updated_at' => now()->toDateTimeString(),
        ]);
        abort_if($updated === 0, 404, 'No such fabric');

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
            'overview' => ['topology' => Topology::forFabric($summary['id'], $nodes)],
            'members' => ['members' => FabricMembers::forFabric($summary['id'])],
            'bgp' => $this->bgp($nodes, $deviceIds),
            'vnis' => $this->vnis($nodes, $request),
            'esis' => $this->esis($nodes, $request),
            'tunnels' => ['tunnels' => TunnelMatrix::forFabric($nodes)],
            'macs' => ['search' => MacSearch::run((string) $request->query('q', ''), $deviceIds), 'q' => (string) $request->query('q', '')],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function vnis(FabricNodes $nodes, Request $request): array
    {
        $rows = VniMatrix::forFabric($nodes);
        $q = (string) $request->query('q', '');
        $issues = (bool) $request->query('issues', '');

        return [
            'vnis' => VniMatrix::filter($rows, $q, $issues),
            'vni_total' => count($rows),
            'vni_issues' => count(array_filter($rows, fn ($r) => $r['flags'] !== [])),
            'q' => $q,
            'issues_only' => $issues,
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
     * @param  list<int>  $deviceIds
     * @return array<string, mixed>
     */
    private function bgp(FabricNodes $nodes, array $deviceIds): array
    {
        $sessions = OverlaySessions::forDevices($deviceIds);
        $missing = OverlaySessions::missing($sessions, $nodes->deviceNodes());
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
