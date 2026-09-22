<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

use Illuminate\Support\Facades\DB;

/**
 * Underlay links between fabric members from core LibreNMS tables (plan §7.6): a shared
 * point-to-point subnet (ipv4_addresses / ipv4_networks) is a candidate link, an underlay
 * BGP session (bgpPeers) or OSPF adjacency (ospf_nbrs) on that subnet confirms it and adds
 * state, LLDP (links) marks it physically confirmed. A subnet known on one device only
 * still yields an edge when a routing session points into it (b_address, and the OSPF
 * router-id as b_vtep_ip). LLDP-only links between two members are kept as backup links.
 */
class UnderlayResolver
{
    /** Interfaces that never carry an underlay link: loopbacks, IRBs, tunnels, management. */
    public const IFNAME_EXCLUDE = '/^(lo\d|irb|vtep|em\d|fxp|me\d|vme|jsrv|bme|pfe|pfh|pip|dsc|gre|ipip|lsi|mtun|tap|esi|fti|cbp|demux|pp\d|pimd|pime|rbeb|vlan|vxlan|mgmt|management|dummy|docker|br-|veth)/i';

    /**
     * @param  list<int>  $deviceIds  devices that are fabric nodes
     * @return list<UnderlayEdge>
     */
    public function resolve(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        // ports of the node devices: port_id => [device_id, ifName]
        $ports = [];
        $physical = [];   // device_id => ifName => port_id
        foreach (DB::table('ports')->whereIn('device_id', $deviceIds)->where('deleted', 0)->get(['port_id', 'device_id', 'ifName', 'ifIndex']) as $port) {
            $ports[(int) $port->port_id] = ['device_id' => (int) $port->device_id, 'ifName' => (string) $port->ifName];
            $physical[(int) $port->device_id][(string) $port->ifName] = (int) $port->port_id;
        }

        // candidate addresses on physical / aggregated interfaces, grouped by network
        $byNetwork = [];
        $addresses = DB::table('ipv4_addresses')->whereIn('port_id', array_keys($ports) ?: [0])
            ->get(['port_id', 'ipv4_address', 'ipv4_prefixlen', 'ipv4_network_id']);
        foreach ($addresses as $address) {
            $port = $ports[(int) $address->port_id] ?? null;
            $prefix = (int) $address->ipv4_prefixlen;
            if ($port === null || $prefix < 8 || $prefix > 31 || preg_match(self::IFNAME_EXCLUDE, $port['ifName'])) {
                continue;
            }
            $byNetwork[(int) $address->ipv4_network_id][] = [
                'device_id' => $port['device_id'],
                'port_id' => (int) $address->port_id,
                'address' => (string) $address->ipv4_address,
                'prefix' => $prefix,
            ];
        }
        if ($byNetwork === []) {
            $networks = [];
        } else {
            $networks = DB::table('ipv4_networks')->whereIn('ipv4_network_id', array_keys($byNetwork))->pluck('ipv4_network', 'ipv4_network_id')
                ->map(fn ($n) => (string) $n)->all();
        }

        // routing sessions and LLDP of the node devices
        $bgp = [];
        foreach (DB::table('bgpPeers')->whereIn('device_id', $deviceIds)->get(['device_id', 'bgpPeerIdentifier', 'bgpPeerState']) as $peer) {
            $bgp[(int) $peer->device_id][] = ['ip' => (string) $peer->bgpPeerIdentifier, 'state' => (string) $peer->bgpPeerState];
        }
        $ospf = [];
        foreach (DB::table('ospf_nbrs')->whereIn('device_id', $deviceIds)->get(['device_id', 'ospfNbrIpAddr', 'ospfNbrRtrId', 'ospfNbrState']) as $nbr) {
            $ospf[(int) $nbr->device_id][] = ['ip' => (string) $nbr->ospfNbrIpAddr, 'rtr' => (string) $nbr->ospfNbrRtrId, 'state' => (string) $nbr->ospfNbrState];
        }
        // core discovery protocols only: the plugin's own evpn-esi rows (EsiLinkWriter) describe
        // a logical multihoming relation, not a cable, and must not confirm an underlay edge
        $lldp = [];   // local_port_id => list of [remote_device_id, remote_port_id]
        $links = DB::table('links')->whereIn('local_device_id', $deviceIds)
            ->where(fn ($q) => $q->whereNull('protocol')->orWhere('protocol', '!=', EsiLinks::PROTOCOL))
            ->get(['local_port_id', 'local_device_id', 'remote_device_id', 'remote_port_id']);
        foreach ($links as $link) {
            $lldp[(int) $link->local_port_id][] = ['device_id' => (int) $link->remote_device_id, 'port_id' => (int) $link->remote_port_id, 'local_device_id' => (int) $link->local_device_id];
        }

        // far-end addresses of half edges resolved to any monitored device
        $farAddresses = [];
        foreach ($bgp as $sessions) {
            foreach ($sessions as $s) {
                $farAddresses[$s['ip']] = true;
            }
        }
        foreach ($ospf as $sessions) {
            foreach ($sessions as $s) {
                $farAddresses[$s['ip']] = true;
            }
        }
        $farDevices = [];   // ip => [device_id, port_id]
        if ($farAddresses !== []) {
            $rows = DB::table('ipv4_addresses')->join('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
                ->whereIn('ipv4_address', array_keys($farAddresses))->where('ports.deleted', 0)
                ->get(['ipv4_address', 'ports.device_id', 'ports.port_id']);
            foreach ($rows as $row) {
                $farDevices[(string) $row->ipv4_address] ??= ['device_id' => (int) $row->device_id, 'port_id' => (int) $row->port_id];
            }
        }

        $nodeDevices = array_fill_keys($deviceIds, true);
        $edges = [];
        $coveredPorts = [];

        foreach ($byNetwork as $networkId => $members) {
            $cidr = $networks[$networkId] ?? null;
            $devices = array_unique(array_column($members, 'device_id'));

            if (count($devices) >= 2) {
                // full edges between every pair of members on the subnet
                for ($i = 0; $i < count($members); $i++) {
                    for ($j = $i + 1; $j < count($members); $j++) {
                        [$a, $b] = $members[$i]['device_id'] <= $members[$j]['device_id'] ? [$members[$i], $members[$j]] : [$members[$j], $members[$i]];
                        if ($a['device_id'] === $b['device_id']) {
                            continue;
                        }
                        $protocols = [];
                        $states = [];
                        $vtep = null;
                        self::sessions($bgp[$a['device_id']] ?? [], $ospf[$a['device_id']] ?? [], $b['address'], $protocols, $states, $vtep);
                        self::sessions($bgp[$b['device_id']] ?? [], $ospf[$b['device_id']] ?? [], $a['address'], $protocols, $states, $vtep);
                        $edge = new UnderlayEdge(
                            $a['device_id'], $a['port_id'], $a['address'], $b['device_id'], $b['port_id'], $b['address'], null, $cidr,
                            $protocols === [] ? 'ip' : implode(',', array_keys($protocols)), $states === [] ? null : implode('/', array_unique($states)),
                        );
                        $edge->lldp = self::lldpConfirmed($lldp, $physical, $ports, $a['device_id'], $a['port_id'], $b['device_id'])
                            || self::lldpConfirmed($lldp, $physical, $ports, $b['device_id'], $b['port_id'], $a['device_id']);
                        $edges[$edge->key()] = $edge;
                        $coveredPorts[$a['port_id']] = true;
                        $coveredPorts[$b['port_id']] = true;
                    }
                }
                continue;
            }

            // one member: half edges towards routing sessions inside the subnet
            foreach ($members as $a) {
                $far = [];
                foreach ($bgp[$a['device_id']] ?? [] as $s) {
                    if ($cidr !== null && self::inCidr($s['ip'], $cidr)) {
                        $far[$s['ip']]['protocols']['bgp'] = true;
                        $far[$s['ip']]['states'][] = $s['state'];
                    }
                }
                foreach ($ospf[$a['device_id']] ?? [] as $s) {
                    if ($cidr !== null && self::inCidr($s['ip'], $cidr)) {
                        $far[$s['ip']]['protocols']['ospf'] = true;
                        $far[$s['ip']]['states'][] = $s['state'];
                        $far[$s['ip']]['vtep'] = $s['rtr'];
                    }
                }
                foreach ($far as $ip => $info) {
                    $device = $farDevices[$ip] ?? null;
                    $bDevice = $device['device_id'] ?? null;
                    $edge = new UnderlayEdge(
                        $a['device_id'], $a['port_id'], $a['address'], $bDevice, $device['port_id'] ?? null, (string) $ip, $info['vtep'] ?? null, $cidr,
                        implode(',', array_keys($info['protocols'])), implode('/', array_unique($info['states'])),
                    );
                    $edge->lldp = self::lldpConfirmed($lldp, $physical, $ports, $a['device_id'], $a['port_id'], $bDevice);
                    $edge->wan = $bDevice === null || ! isset($nodeDevices[$bDevice]);
                    $edges[$edge->key()] = $edge;
                    $coveredPorts[$a['port_id']] = true;
                }
            }
        }

        // LLDP-only links between two node devices on ports without an underlay subnet
        foreach ($lldp as $localPort => $remotes) {
            $local = $ports[$localPort] ?? null;
            if ($local === null) {
                continue;
            }
            foreach ($remotes as $remote) {
                if (! isset($nodeDevices[$remote['device_id']]) || $remote['device_id'] === $local['device_id']) {
                    continue;
                }
                if (isset($coveredPorts[$localPort]) || isset($coveredPorts[$remote['port_id']]) || self::unitCovered($coveredPorts, $physical, $ports, $localPort)) {
                    continue;
                }
                [$aDev, $aPort, $bDev, $bPort] = $local['device_id'] <= $remote['device_id']
                    ? [$local['device_id'], $localPort, $remote['device_id'], $remote['port_id']]
                    : [$remote['device_id'], $remote['port_id'], $local['device_id'], $localPort];
                $edge = new UnderlayEdge($aDev, $aPort, null, $bDev, $bPort ?: null, null, null, null, 'lldp-only', null, true);
                $edges[$edge->key()] = $edge;
            }
        }

        return array_values($edges);
    }

    /**
     * @param  list<array{ip: string, state: string}>  $bgp
     * @param  list<array{ip: string, rtr: string, state: string}>  $ospf
     * @param  array<string, true>  $protocols
     * @param  list<string>  $states
     */
    private static function sessions(array $bgp, array $ospf, string $peerAddress, array &$protocols, array &$states, ?string &$vtep): void
    {
        foreach ($bgp as $s) {
            if ($s['ip'] === $peerAddress) {
                $protocols['bgp'] = true;
                $states[] = $s['state'];
            }
        }
        foreach ($ospf as $s) {
            if ($s['ip'] === $peerAddress) {
                $protocols['ospf'] = true;
                $states[] = $s['state'];
                $vtep ??= $s['rtr'];
            }
        }
    }

    /**
     * LLDP on the port or on its physical parent (addresses sit on et-0/0/54.0, LLDP on
     * et-0/0/54) towards the given device (any neighbour when the device is unknown).
     *
     * @param  array<int, list<array{device_id: int, port_id: int, local_device_id: int}>>  $lldp
     * @param  array<int, array<string, int>>  $physical
     * @param  array<int, array{device_id: int, ifName: string}>  $ports
     */
    private static function lldpConfirmed(array $lldp, array $physical, array $ports, int $deviceId, int $portId, ?int $remoteDeviceId): bool
    {
        $candidates = [$portId];
        $ifName = $ports[$portId]['ifName'] ?? '';
        if (($dot = strrpos($ifName, '.')) !== false) {
            $parent = $physical[$deviceId][substr($ifName, 0, $dot)] ?? null;
            if ($parent !== null) {
                $candidates[] = $parent;
            }
        }
        foreach ($candidates as $candidate) {
            foreach ($lldp[$candidate] ?? [] as $remote) {
                if ($remoteDeviceId === null || $remote['device_id'] === $remoteDeviceId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a unit of this physical port already carries an underlay edge.
     *
     * @param  array<int, true>  $coveredPorts
     * @param  array<int, array<string, int>>  $physical
     * @param  array<int, array{device_id: int, ifName: string}>  $ports
     */
    private static function unitCovered(array $coveredPorts, array $physical, array $ports, int $portId): bool
    {
        $port = $ports[$portId] ?? null;
        if ($port === null) {
            return false;
        }
        foreach ($physical[$port['device_id']] ?? [] as $ifName => $id) {
            if (isset($coveredPorts[$id]) && str_starts_with($ifName, $port['ifName'] . '.')) {
                return true;
            }
        }

        return false;
    }

    /** IPv4 / IPv6 membership test for "network/prefix". */
    public static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $pi = @inet_pton($ip);
        $pn = @inet_pton((string) $network);
        if ($pi === false || $pn === false || strlen($pi) !== strlen($pn)) {
            return false;
        }
        $bits = $prefix === null ? strlen($pn) * 8 : (int) $prefix;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($pi, 0, $bytes) !== substr($pn, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($pi[$bytes]) & $mask) === (ord($pn[$bytes]) & $mask);
    }
}
