<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;
use SafferIt\LibrenmsNetconf\Fabric\FabricResolver;

/**
 * lnms netconf:fabric — show the resolved EVPN fabrics (members, roles, unknown VTEPs,
 * underlay links); --resolve recomputes them from the current tables first.
 */
class NetconfFabricCommand extends Command
{
    protected $signature = 'netconf:fabric
        {--resolve : Recompute fabrics, members and underlay links now}
        {--links : Also list the underlay links}';

    protected $description = 'Show the EVPN fabrics resolved from the netconf_evpn_* tables';

    public function handle(): int
    {
        if (! NetconfService::fabricEnabled()) {
            $this->warn('The EVPN fabric view setting is off: no fabric tables are collected and nothing is resolved.');
        }

        if ($this->option('resolve')) {
            $summary = FabricResolver::make()->run();   // under the poller's lock
            if ($summary === null) {
                $this->warn('Not resolved: a poller holds the fabric resolver lock, its run covers this one.');
            } else {
                $this->line(sprintf('<info>Resolved:</info> %d nodes on %d devices (%d unknown), %d fabrics, %d underlay links, %d ESI peer links', $summary['nodes'], $summary['devices'], $summary['unknown'], $summary['fabrics'], $summary['links'], $summary['esi_links']));
            }
        }

        $hostnames = DB::table('devices')->pluck('hostname', 'device_id')->map(fn ($h) => (string) $h)->all();
        $fabrics = DB::table(TableSchema::tableName('fabric'))->orderBy('key')->get();
        if ($fabrics->isEmpty()) {
            $this->line('No fabrics resolved yet (poll a leaf with the EVPN fabric view enabled, or run with --resolve).');

            return self::SUCCESS;
        }

        $members = DB::table(TableSchema::tableName('fabric_member'))
            ->join(TableSchema::tableName('vtep'), TableSchema::tableName('vtep') . '.vtep_ip', '=', TableSchema::tableName('fabric_member') . '.vtep_ip')
            ->get([
                TableSchema::tableName('fabric_member') . '.fabric_id', TableSchema::tableName('fabric_member') . '.vtep_ip', TableSchema::tableName('fabric_member') . '.role',
                TableSchema::tableName('fabric_member') . '.pinned', 'device_id', 'router_id', 'border', 'name_hint', 'last_seen',
            ]);

        $this->table(['Id', 'Name', 'Key', 'Members', 'Leaves', 'Gateways', 'Spines', 'Unknown VTEPs'], $fabrics->map(function ($fabric) use ($members) {
            $own = $members->where('fabric_id', $fabric->id);

            return [
                $fabric->id,
                $fabric->name,
                $fabric->key,
                $own->count(),
                $own->where('role', 'leaf')->count(),
                $own->where('role', 'gateway')->count(),
                $own->where('role', 'spine')->count(),
                $own->whereNull('device_id')->count(),
            ];
        })->all());

        $this->table(['Fabric', 'VTEP', 'Role', 'Device', 'Router-id', 'Border', 'Name hint', 'Pinned', 'Last seen'], $members->sort(fn ($a, $b) => [$a->fabric_id, 0] <=> [$b->fabric_id, 0] ?: FabricGraph::compare((string) $a->vtep_ip, (string) $b->vtep_ip))->map(fn ($m) => [
            $m->fabric_id,
            $m->vtep_ip,
            $m->role,
            $m->device_id === null ? '<comment>unknown</comment>' : ($hostnames[(int) $m->device_id] ?? $m->device_id),
            $m->router_id ?? '',
            $m->border ? 'yes' : '',
            $m->name_hint ?? '',
            $m->pinned ? 'yes' : '',
            $m->last_seen ?? '',
        ])->all());

        if ($this->option('links')) {
            $portNames = DB::table('ports')->whereIn('port_id', DB::table(TableSchema::tableName('underlay_link'))->select('a_port_id')->union(DB::table(TableSchema::tableName('underlay_link'))->select('b_port_id')))
                ->pluck('ifName', 'port_id')->map(fn ($n) => (string) $n)->all();
            $links = DB::table(TableSchema::tableName('underlay_link'))->orderBy('fabric_id')->orderBy('a_device_id')->get();
            $this->table(['Fabric', 'A device', 'A port', 'B device', 'B port / address', 'Network', 'Protocol', 'State', 'LLDP', 'WAN'], $links->map(fn ($l) => [
                $l->fabric_id ?? '',
                $hostnames[(int) $l->a_device_id] ?? $l->a_device_id,
                $portNames[(int) $l->a_port_id] ?? ($l->a_port_id ?? ''),
                $l->b_device_id === null ? '<comment>unknown</comment>' : ($hostnames[(int) $l->b_device_id] ?? $l->b_device_id),
                trim(($portNames[(int) $l->b_port_id] ?? '') . ' ' . ($l->b_address ?? '') . ($l->b_vtep_ip ? " (rtr {$l->b_vtep_ip})" : '')),
                $l->network ?? '',
                $l->protocol,
                $l->state ?? '',
                $l->lldp ? 'yes' : '',
                $l->wan ? 'yes' : '',
            ])->all());
        }

        return self::SUCCESS;
    }
}
