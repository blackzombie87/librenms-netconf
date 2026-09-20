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
use SafferIt\LibrenmsNetconf\Fabric\View\FabricSummary;
use SafferIt\LibrenmsNetconf\Fabric\View\OverlaySessions;

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
            'members' => ['members' => FabricMembers::forFabric($summary['id'])],
            'bgp' => $this->bgp($nodes, $deviceIds),
            default => [],
        };
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
