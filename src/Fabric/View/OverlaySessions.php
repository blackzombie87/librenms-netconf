<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\EvpnSessions;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * EVPN overlay sessions of the monitored fabric members (plan §7.4 "BGP overlay"): the
 * sessions EvpnSessions discovers (evpn SAFI in bgpPeers_cbgp, or a bgp.evpn.0 RIB metric),
 * with the core bgpPeers row (state, uptime, AS), the plugin's routing.yaml peer metrics
 * (flaps, state where the core row is missing) and per-RIB counts, and the EVPN neighbour
 * route counts from `show evpn instance extensive`. One row per device and peer address.
 */
final class OverlaySessions
{
    /**
     * @param  list<int>  $deviceIds
     * @return list<array<string, mixed>>
     */
    public static function forDevices(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $rows "device/peer" => row */
        $rows = [];
        $row = function (int $deviceId, string $peer) use (&$rows): array {
            $key = "$deviceId/$peer";
            $rows[$key] ??= [
                'device_id' => $deviceId,
                'peer_ip' => $peer,
                'state' => null,
                'up' => false,
                'uptime' => null,
                'flaps' => null,
                'remote_as' => null,
                'description' => null,
                'local_ip' => null,
                'source' => [],
                'rib' => null,
                'routes' => null,
            ];

            return $rows[$key];
        };
        $set = function (int $deviceId, string $peer, array $values) use (&$rows, $row): void {
            $row($deviceId, $peer);
            $rows["$deviceId/$peer"] = array_replace($rows["$deviceId/$peer"], $values);
        };

        // the sessions that carry EVPN (shared with the resolver), then the core BGP row of each
        foreach (EvpnSessions::discover($deviceIds) as $s) {
            $set($s['device_id'], $s['peer'], ['description' => $s['description'], 'local_ip' => $s['local'], 'source' => $s['source']]);
        }
        // the same metric rows the discovery just read, not a second pair of queries
        $metrics = EvpnSessions::metricRows();
        if ($rows === []) {
            return [];
        }
        foreach (DB::table('bgpPeers')->whereIn('device_id', $deviceIds)->get(['device_id', 'bgpPeerIdentifier', 'bgpPeerRemoteAs', 'bgpPeerState', 'bgpPeerFsmEstablishedTime', 'bgpPeer_id']) as $r) {
            if (! isset($rows[$r->device_id . '/' . $r->bgpPeerIdentifier])) {
                continue;
            }
            $state = (string) $r->bgpPeerState;
            $set((int) $r->device_id, (string) $r->bgpPeerIdentifier, [
                'state' => $state,
                'up' => strtolower($state) === 'established',
                'uptime' => $r->bgpPeerFsmEstablishedTime === null ? null : (int) $r->bgpPeerFsmEstablishedTime,
                'remote_as' => $r->bgpPeerRemoteAs === null ? null : (int) $r->bgpPeerRemoteAs,
                'bgp_peer_id' => (int) $r->bgpPeer_id,
            ]);
        }

        // per-RIB counts of the plugin's routing.yaml metric
        foreach ($metrics['bgp-peer-rib'] as $r) {
            $peer = explode('/', (string) $r->metric_index, 2)[0];
            if (! isset($rows[$r->device_id . '/' . $peer])) {
                continue;
            }
            $values = json_decode((string) $r->values, true) ?: [];
            $set((int) $r->device_id, $peer, [
                'rib' => ['active' => $values['active'] ?? null, 'received' => $values['received'] ?? null, 'accepted' => $values['accepted'] ?? null, 'suppressed' => $values['suppressed'] ?? null],
            ]);
        }
        // peer metric: flaps always, state / description / AS where core has no row
        foreach ($metrics['bgp-peer'] as $r) {
            $peer = (string) $r->metric_index;
            if (! isset($rows[$r->device_id . '/' . $peer])) {
                continue;
            }
            $values = json_decode((string) $r->values, true) ?: [];
            $labels = json_decode((string) $r->labels, true) ?: [];
            $current = $row((int) $r->device_id, $peer);
            $update = ['flaps' => isset($values['flaps']) ? (int) $values['flaps'] : null];
            if ($current['state'] === null && isset($labels['state'])) {
                $update['state'] = (string) $labels['state'];
                $update['up'] = strtolower((string) $labels['state']) === 'established';
                $update['uptime'] = isset($values['uptime']) ? (int) $values['uptime'] : null;
            }
            if ($current['description'] === null && ! empty($labels['description'])) {
                $update['description'] = (string) $labels['description'];
            }
            if ($current['remote_as'] === null && preg_match('/\bAS(\d+)$/', (string) $r->descr, $m)) {
                $update['remote_as'] = (int) $m[1];
            }
            $set((int) $r->device_id, $peer, $update);
        }

        // EVPN neighbour route counts, summed over the instances
        foreach (DB::table(TableSchema::tableName('neighbor'))->whereIn('device_id', $deviceIds)->get() as $r) {
            $current = $row((int) $r->device_id, (string) $r->neighbor_ip);
            $routes = $current['routes'] ?? ['mac' => 0, 'mac_ip' => 0, 'ead' => 0, 'imet' => 0, 'es' => 0, 'instances' => []];
            $routes['mac'] += (int) $r->mac_routes;
            $routes['mac_ip'] += (int) $r->mac_ip_routes;
            $routes['ead'] += (int) $r->ead_routes;
            $routes['imet'] += (int) $r->imet_routes;
            $routes['es'] += (int) $r->es_routes;
            $routes['instances'][] = (string) $r->instance;
            $set((int) $r->device_id, (string) $r->neighbor_ip, ['routes' => $routes, 'source' => array_values(array_unique(array_merge($current['source'], ['evpn'])))]);
        }

        $list = array_values($rows);
        usort($list, fn ($a, $b) => [$a['device_id'], 0] <=> [$b['device_id'], 0] ?: FabricGraph::compare($a['peer_ip'], $b['peer_ip']));

        return $list;
    }

    /**
     * Peers that other monitored members of the fabric have a session to but this one lacks
     * (plan §7.5 check 2, the "everyone else peers with this spine" heuristic): a peer counts
     * when at least two monitored members have it, or all others when there are only two.
     * A peer address is counted under the member address of its device when it is a monitored
     * device's alias (router-id vs. VTEP, as Topology::overlayPairs() draws it), so two members
     * that session to the same node on different addresses are one peer, not two half-peers.
     *
     * @param  list<array<string, mixed>>  $sessions  rows of forDevices()
     * @param  array<int, list<string>>  $deviceNodes  device_id => its own addresses, member address first (never "missing" towards itself)
     * @return list<array{device_id: int, peer_ip: string, have: list<int>}> peer_ip is the canonical (member) address
     */
    public static function missing(array $sessions, array $deviceNodes): array
    {
        $devices = array_keys($deviceNodes);
        if (count($devices) < 2) {
            return [];
        }
        /** @var array<string, string> $canonical alias address => member address */
        $canonical = [];
        foreach ($deviceNodes as $ips) {
            foreach ($ips as $ip) {
                $canonical[$ip] = $ips[0];
            }
        }

        $have = [];
        foreach ($sessions as $s) {
            $peer = $canonical[$s['peer_ip']] ?? $s['peer_ip'];
            $have[$peer][$s['device_id']] = true;
        }
        $threshold = count($devices) === 2 ? 1 : 2;

        $missing = [];
        foreach ($have as $peer => $byDevice) {
            if (count($byDevice) < $threshold) {
                continue;
            }
            foreach ($devices as $deviceId) {
                if (isset($byDevice[$deviceId]) || $peer === $deviceNodes[$deviceId][0]) {
                    continue;
                }
                $missing[] = ['device_id' => $deviceId, 'peer_ip' => (string) $peer, 'have' => array_map('intval', array_keys($byDevice))];
            }
        }

        return $missing;
    }
}
