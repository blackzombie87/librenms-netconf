<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

use Illuminate\Support\Facades\DB;

/**
 * Which BGP sessions of the monitored devices carry EVPN: core bgpPeers_cbgp rows with the
 * evpn SAFI, plus the routing.yaml per-RIB metric rows (index "peer/bgp.evpn.0") for devices
 * whose SNMP lacks the table. The one place overlay sessions are discovered; the resolver
 * unions the endpoints, the BGP overlay tab adds state and counters on top.
 */
final class EvpnSessions
{
    /**
     * @param  list<int>|null  $deviceIds  restrict to these devices, null = every device
     * @return list<array{device_id: int, peer: string, local: string|null, description: string|null, source: list<string>}>
     */
    public static function discover(?array $deviceIds = null): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $scoped = fn ($query) => $deviceIds === null ? $query : $query->whereIn('device_id', $deviceIds);

        $sessions = [];
        $descriptions = [];
        foreach ($scoped(DB::table('bgpPeers'))->get(['device_id', 'bgpPeerIdentifier', 'bgpLocalAddr', 'bgpPeerDescr']) as $peer) {
            $descriptions[(int) $peer->device_id][(string) $peer->bgpPeerIdentifier] = [
                'local' => $peer->bgpLocalAddr !== null && $peer->bgpLocalAddr !== '' && $peer->bgpLocalAddr !== '0.0.0.0' ? (string) $peer->bgpLocalAddr : null,
                'description' => $peer->bgpPeerDescr !== null && $peer->bgpPeerDescr !== '' ? (string) $peer->bgpPeerDescr : null,
            ];
        }

        foreach ($scoped(DB::table('bgpPeers_cbgp'))->where('safi', 'evpn')->get(['device_id', 'bgpPeerIdentifier']) as $row) {
            $info = $descriptions[(int) $row->device_id][(string) $row->bgpPeerIdentifier] ?? ['local' => null, 'description' => null];
            $sessions[$row->device_id . '/' . $row->bgpPeerIdentifier] = ['device_id' => (int) $row->device_id, 'peer' => (string) $row->bgpPeerIdentifier] + $info + ['source' => ['bgp']];
        }

        foreach ($scoped(DB::table('netconf_metrics'))->where('mapping', 'bgp-peer-rib')->where('metric_index', 'like', '%/bgp.evpn.0')->get(['device_id', 'metric_index']) as $metric) {
            $peer = explode('/', (string) $metric->metric_index, 2)[0];
            if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $key = $metric->device_id . '/' . $peer;
            if (isset($sessions[$key])) {
                $sessions[$key]['source'][] = 'rib';
                continue;
            }
            $info = $descriptions[(int) $metric->device_id][$peer] ?? ['local' => null, 'description' => null];
            $sessions[$key] = ['device_id' => (int) $metric->device_id, 'peer' => $peer] + $info + ['source' => ['rib']];
        }

        // BGP descriptions from the routing.yaml peer metric where the core row has none
        foreach ($scoped(DB::table('netconf_metrics'))->where('mapping', 'bgp-peer')->get(['device_id', 'metric_index', 'labels']) as $label) {
            $key = $label->device_id . '/' . $label->metric_index;
            if (! isset($sessions[$key]) || $sessions[$key]['description'] !== null) {
                continue;
            }
            $decoded = json_decode((string) $label->labels, true);
            if (is_array($decoded) && ! empty($decoded['description'])) {
                $sessions[$key]['description'] = (string) $decoded['description'];
            }
        }

        return array_values($sessions);
    }
}
