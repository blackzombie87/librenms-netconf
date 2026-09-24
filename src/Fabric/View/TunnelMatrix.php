<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Port;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Tunnels tab (plan §7.4): the VXLAN tunnels of every monitored member — kernel vtep.N IFL,
 * the core port behind it (traffic and errors) when discovered, mode, next-hop id, MAC count —
 * grouped by device, with the reverse tunnel checked where the far end is monitored too.
 */
final class TunnelMatrix
{
    /**
     * @return array{by_device: array<int, list<array<string, mixed>>>, total: int, with_port: int, asymmetric: int}
     */
    public static function forFabric(FabricNodes $nodes): array
    {
        $deviceIds = $nodes->deviceIds() ?: [0];
        $tunnels = DB::table(TableSchema::tableName('tunnel'))->whereIn('device_id', $deviceIds)->get()->map(fn ($r) => (array) $r)->all();
        /** @var \Illuminate\Support\Collection<int, Port> $ports */
        $ports = Port::query()->whereIn('port_id', array_values(array_filter(array_column($tunnels, 'port_id'))) ?: [0])->get()->keyBy('port_id');

        return self::build($tunnels, $nodes->deviceNodes(), fn (int $id) => $ports->get($id), $nodes->collectedIds());
    }

    /**
     * @param  list<array<string, mixed>>  $tunnelRows
     * @param  array<int, list<string>>  $deviceNodes
     * @param  (callable(int): (Port|null))|null  $port
     * @param  list<int>|null  $collected  devices the plugin collects from; null = all of $deviceNodes
     * @return array{by_device: array<int, list<array<string, mixed>>>, total: int, with_port: int, asymmetric: int}
     */
    public static function build(array $tunnelRows, array $deviceNodes, ?callable $port = null, ?array $collected = null): array
    {
        $collects = $collected === null ? null : array_flip($collected);
        $addressDevice = [];
        foreach ($deviceNodes as $deviceId => $ips) {
            foreach ($ips as $ip) {
                $addressDevice[$ip] = $deviceId;
            }
        }
        /** @var array<int, array<int, true>> $has device => remote device => true */
        $has = [];
        foreach ($tunnelRows as $r) {
            $target = $addressDevice[(string) $r['remote_vtep_ip']] ?? null;
            if ($target !== null) {
                $has[(int) $r['device_id']][$target] = true;
            }
        }

        $byDevice = [];
        $withPort = 0;
        $asymmetric = 0;
        foreach ($tunnelRows as $r) {
            $deviceId = (int) $r['device_id'];
            $remote = (string) $r['remote_vtep_ip'];
            $target = $addressDevice[$remote] ?? null;
            $portId = $r['port_id'] === null ? null : (int) $r['port_id'];
            $portModel = $portId !== null && $port !== null ? $port($portId) : null;
            // unknown (null), not "no", when the far end is a member the plugin never polled:
            // its tunnel table is empty because nobody asked it (plan §10.4)
            $reverse = $target === null || ($collects !== null && ! isset($collects[$target])) ? null : isset($has[$target][$deviceId]);
            if ($reverse === false) {
                $asymmetric++;
            }
            if ($portModel !== null) {
                $withPort++;
            }
            $byDevice[$deviceId][] = [
                'device_id' => $deviceId,
                'remote_vtep_ip' => $remote,
                'remote_device_id' => $target,
                'ifname' => $r['ifname'] ?? null,
                'ri_ifname' => $r['ri_ifname'] ?? null,
                'snmp_index' => $r['snmp_index'] ?? null,
                'port_id' => $portId,
                'port' => $portModel,
                'mode' => $r['mode'] ?? null,
                'nh_id' => $r['nh_id'] ?? null,
                'mac_count' => $r['mac_count'] ?? null,
                'reverse' => $reverse,
                'last_seen' => $r['last_seen'] ?? null,
            ];
        }
        foreach ($byDevice as &$rows) {
            usort($rows, fn ($a, $b) => FabricGraph::compare($a['remote_vtep_ip'], $b['remote_vtep_ip']));
        }
        unset($rows);
        ksort($byDevice);

        return ['by_device' => $byDevice, 'total' => count($tunnelRows), 'with_port' => $withPort, 'asymmetric' => $asymmetric];
    }
}
