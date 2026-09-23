<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

/**
 * What one EvpnSessions::discover() run found: the EVPN sessions, and the routing.yaml
 * metric rows they were derived from. The BGP overlay tab wants both — the rows carry its
 * per-RIB counts, flaps and labels — and reading them from here saves it a second pair of
 * queries.
 */
final class EvpnSessionSet
{
    /**
     * @param  list<array{device_id: int, peer: string, local: string|null, description: string|null, source: list<string>}>  $sessions
     * @param  list<object>  $ribMetrics  netconf_metrics rows of mapping bgp-peer-rib, RIB bgp.evpn.0
     * @param  list<object>  $peerMetrics  netconf_metrics rows of mapping bgp-peer
     */
    public function __construct(
        public readonly array $sessions,
        public readonly array $ribMetrics = [],
        public readonly array $peerMetrics = [],
    ) {
    }
}
