<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

/**
 * Union-find over EVPN speakers (plan §7.3): nodes are IP addresses (VTEP loopbacks, EVPN
 * BGP peer addresses), edges come from EVPN neighbour lists, ESI remote PEs, VXLAN tunnels,
 * EVPN BGP sessions and confirmed underlay links. Every connected component is one fabric,
 * keyed by its lowest IP. Roles follow the evidence collected per node: a VXLAN endpoint is
 * a leaf, an endpoint with anycast IRBs an L3 gateway, an EVPN session peer without VXLAN
 * evidence a spine / route reflector. Pure PHP, no LibreNMS.
 */
final class FabricGraph
{
    public const ROLE_LEAF = 'leaf';

    public const ROLE_SPINE = 'spine';

    public const ROLE_GATEWAY = 'gateway';

    public const ROLE_UNKNOWN = 'unknown';

    /** @var array<string, string> node => parent */
    private array $parent = [];

    /** @var array<string, array{vtep: bool, session: bool, gateway: bool, border: bool}> */
    private array $evidence = [];

    public function add(string $ip): void
    {
        if (! isset($this->parent[$ip])) {
            $this->parent[$ip] = $ip;
            $this->evidence[$ip] = ['vtep' => false, 'session' => false, 'gateway' => false, 'border' => false];
        }
    }

    public function has(string $ip): bool
    {
        return isset($this->parent[$ip]);
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        $nodes = array_keys($this->parent);
        usort($nodes, self::compare(...));

        return $nodes;
    }

    /** The node terminates VXLAN tunnels (source VTEP, remote VTEP, EVPN neighbour, ESI peer). */
    public function markVtep(string $ip): void
    {
        $this->mark($ip, 'vtep');
    }

    /** The node is an EVPN BGP session endpoint (a leaf or a spine / route reflector). */
    public function markSession(string $ip): void
    {
        $this->mark($ip, 'session');
    }

    /** The node hosts anycast IRB gateways (irb-interfaces > 0). */
    public function markGateway(string $ip): void
    {
        $this->mark($ip, 'gateway');
    }

    /** The node has L3 contexts / type-5 prefixes. */
    public function markBorder(string $ip): void
    {
        $this->mark($ip, 'border');
    }

    public function union(string $a, string $b): void
    {
        $this->add($a);
        $this->add($b);
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra === $rb) {
            return;
        }
        // keep the lowest IP as the root so keys are stable without a second pass
        if (self::compare($ra, $rb) <= 0) {
            $this->parent[$rb] = $ra;
        } else {
            $this->parent[$ra] = $rb;
        }
    }

    public function connected(string $a, string $b): bool
    {
        return $this->has($a) && $this->has($b) && $this->find($a) === $this->find($b);
    }

    /** Key of the node's component: its lowest IP. */
    public function key(string $ip): ?string
    {
        return $this->has($ip) ? $this->find($ip) : null;
    }

    public function role(string $ip): string
    {
        $e = $this->evidence[$ip] ?? null;
        if ($e === null) {
            return self::ROLE_UNKNOWN;
        }
        if ($e['gateway']) {
            return self::ROLE_GATEWAY;
        }
        if ($e['vtep']) {
            return self::ROLE_LEAF;
        }
        if ($e['session']) {
            return self::ROLE_SPINE;
        }

        return self::ROLE_UNKNOWN;
    }

    public function isBorder(string $ip): bool
    {
        return (bool) ($this->evidence[$ip]['border'] ?? false);
    }

    public function isVtep(string $ip): bool
    {
        return (bool) ($this->evidence[$ip]['vtep'] ?? false);
    }

    /**
     * Addresses of one device are aliases of the same node: pool their evidence so every
     * address reports the same role (a router-id marked by a neighbour, a VTEP marked by
     * its own source table, gateway IRBs marked on either).
     *
     * @param  list<string>  $ips
     */
    public function mergeEvidence(array $ips): void
    {
        $known = array_values(array_filter($ips, fn (string $ip) => isset($this->evidence[$ip])));
        if (count($known) < 2) {
            return;
        }
        $merged = ['vtep' => false, 'session' => false, 'gateway' => false, 'border' => false];
        foreach ($known as $ip) {
            foreach ($merged as $flag => $set) {
                $merged[$flag] = $set || $this->evidence[$ip][$flag];
            }
        }
        foreach ($known as $ip) {
            $this->evidence[$ip] = $merged;
        }
    }

    /**
     * Connected components: key (lowest IP) => member IPs sorted by address.
     *
     * @return array<string, list<string>>
     */
    public function components(): array
    {
        $components = [];
        foreach (array_keys($this->parent) as $ip) {
            $components[$this->find($ip)][] = $ip;
        }
        foreach ($components as &$members) {
            usort($members, self::compare(...));
        }
        unset($members);
        uksort($components, self::compare(...));

        return $components;
    }

    /** Numeric address order: IPv4 before IPv6, then by address; non-addresses last, textual. */
    public static function compare(string $a, string $b): int
    {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);
        if ($pa === false || $pb === false) {
            return $pa === $pb ? strcmp($a, $b) : ($pa === false ? 1 : -1);
        }
        if (strlen($pa) !== strlen($pb)) {
            return strlen($pa) <=> strlen($pb);
        }

        return strcmp($pa, $pb);
    }

    /**
     * @param  list<string>  $ips
     */
    public static function lowest(array $ips): ?string
    {
        if ($ips === []) {
            return null;
        }
        usort($ips, self::compare(...));

        return $ips[0];
    }

    private function mark(string $ip, string $flag): void
    {
        $this->add($ip);
        $this->evidence[$ip][$flag] = true;
    }

    private function find(string $ip): string
    {
        $root = $ip;
        while ($this->parent[$root] !== $root) {
            $root = $this->parent[$root];
        }
        // path compression
        while ($this->parent[$ip] !== $root) {
            $next = $this->parent[$ip];
            $this->parent[$ip] = $root;
            $ip = $next;
        }

        return $root;
    }
}
