<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricTopologyInput;

/**
 * Everything `FabricTrace::build()` checks a path against, read once per request: the
 * tunnels, the flood lists, the EVPN neighbour lists, the IRBs per member and the ESI rows.
 * All of it is already stored; the trace adds no poll work and writes nothing.
 */
final class TraceContext
{
    /**
     * @return array{names: array<string, string>, members: array<string, array<string, mixed>>, tunnels: array<string, array<string, array<string, mixed>>>, flood: array<string, array<int, list<string>>>, neighbours: array<string, list<string>>, irb_vnis: array<string, list<int>>, esis: array<string, array<string, mixed>>, edges: list<array<string, mixed>>, addresses: array<string, string>}
     */
    public static function forFabric(int $fabricId, FabricNodes $nodes): array
    {
        $deviceIds = $nodes->deviceIds() ?: [0];
        $names = [];
        $members = [];
        foreach ($nodes->all() as $ip => $node) {
            if ($node['member']) {
                $names[(string) $ip] = $node['name'];
                $members[(string) $ip] = [
                    'device_id' => $node['device_id'],
                    'collected' => $node['device_id'] !== null && $nodes->isCollected($node['device_id']),
                ];
            }
        }

        $tunnels = [];
        foreach (DB::table(TableSchema::tableName('tunnel'))->whereIn('device_id', $deviceIds)->get(['device_id', 'remote_vtep_ip', 'ifname', 'port_id', 'mac_count']) as $row) {
            $own = $nodes->addressOf((int) $row->device_id);
            if ($own !== null) {
                $tunnels[$own][$nodes->canonical((string) $row->remote_vtep_ip)] = [
                    'ifname' => $row->ifname === null ? null : (string) $row->ifname,
                    'port_id' => $row->port_id === null ? null : (int) $row->port_id,
                    'mac_count' => $row->mac_count === null ? null : (int) $row->mac_count,
                ];
            }
        }

        $flood = [];
        foreach (DB::table(TableSchema::tableName('vni_vtep'))->whereIn('device_id', $deviceIds)->distinct()->get(['device_id', 'vni', 'remote_vtep_ip']) as $row) {
            $own = $nodes->addressOf((int) $row->device_id);
            if ($own !== null) {
                $flood[$own][(int) $row->vni][] = $nodes->canonical((string) $row->remote_vtep_ip);
            }
        }

        $neighbours = [];
        foreach (DB::table(TableSchema::tableName('neighbor'))->whereIn('device_id', $deviceIds)->distinct()->get(['device_id', 'neighbor_ip']) as $row) {
            $own = $nodes->addressOf((int) $row->device_id);
            if ($own !== null) {
                $neighbours[$own][] = $nodes->canonical((string) $row->neighbor_ip);
            }
        }

        $irbVnis = [];
        foreach (DB::table(TableSchema::tableName('vni'))->whereIn('device_id', $deviceIds)->whereNotNull('irb_ifname')->get(['device_id', 'vni']) as $row) {
            $own = $nodes->addressOf((int) $row->device_id);
            if ($own !== null) {
                $irbVnis[$own][] = (int) $row->vni;
            }
        }

        $esis = [];
        foreach (DB::table(TableSchema::tableName('esi'))->whereIn('device_id', $deviceIds)->get(['esi', 'mode', 'df_ip', 'aliasing']) as $row) {
            $esi = (string) $row->esi;
            $esis[$esi] ??= ['mode' => null, 'df_ip' => null, 'aliasing' => null];
            $esis[$esi]['mode'] ??= $row->mode === null ? null : (string) $row->mode;
            $esis[$esi]['df_ip'] ??= $row->df_ip === null ? null : (string) $row->df_ip;
            if ($row->aliasing !== null && ! (bool) $row->aliasing) {
                $esis[$esi]['aliasing'] = false;
            }
        }

        // the eagle rows, because a hop needs the link_key for a highlight and the two port
        // ids for its traffic graph; the layout rows carry neither
        $input = FabricTopologyInput::load($fabricId, $nodes, eagle: true);

        return [
            'names' => $names,
            'members' => $members,
            'tunnels' => $tunnels,
            'flood' => $flood,
            'neighbours' => $neighbours,
            'irb_vnis' => $irbVnis,
            'esis' => $esis,
            'edges' => $input['underlay'],
            'addresses' => self::interfaceAddresses($deviceIds, $nodes),
        ];
    }

    /**
     * Every interface address of the fabric's devices, mapped to the member address that owns
     * it: what turns a next hop from `show route` into a device (plan §12.4).
     *
     * @param  list<int>  $deviceIds
     * @return array<string, string>
     */
    private static function interfaceAddresses(array $deviceIds, FabricNodes $nodes): array
    {
        $out = [];
        $rows = DB::table('ipv4_addresses')
            ->join('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
            ->whereIn('ports.device_id', $deviceIds)->where('ports.deleted', 0)
            ->get(['ipv4_addresses.ipv4_address', 'ports.device_id']);
        foreach ($rows as $row) {
            $own = $nodes->addressOf((int) $row->device_id);
            if ($own !== null) {
                $out[(string) $row->ipv4_address] ??= $own;
            }
        }
        foreach ($nodes->all() as $ip => $node) {
            if ($node['device_id'] !== null) {
                $out[(string) $ip] ??= $nodes->addressOf($node['device_id']) ?? (string) $ip;
            }
        }

        return $out;
    }
}
