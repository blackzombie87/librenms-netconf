<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

/**
 * Every minimum-hop path through the underlay between two VTEPs (plan §12.5). Pure BFS over
 * an adjacency array; the graph fallback for the live walk, and the only engine when a hop
 * has no NETCONF session.
 *
 * Deliberately protocol-blind (plan §12.4a). It never prefers a `bgp` edge over an `ospf`
 * one, never ranks by anything but hop count, and the word for what it returns is **fewest
 * hops**, not "shortest path" — it has no metric and does not pretend to. Where several
 * paths tie it returns all of them rather than picking one, because on a fabric with
 * parallel /30s between the same pair that choice belongs to the FIB, not to us.
 *
 * Parallel links between the same two members are separate hops and separate paths. They are
 * never collapsed into one: which of the two carries the traffic is exactly the question a
 * trace is asked.
 */
final class UnderlayPath
{
    public const MAX_HOPS = 8;

    public const MAX_PATHS = 8;

    /**
     * @param  list<array<string, mixed>>  $edges  underlay rows with a, b, a_port, b_port, protocol, state, up, lldp, wan, link_key
     * @return list<list<array{a: string, a_ifname: string|null, a_port_id: int|null, b: string, b_ifname: string|null, b_port_id: int|null, protocol: string, state: string|null, up: bool|null, lldp: bool, wan: bool, link_key: string, live: bool}>>
     */
    public static function between(array $edges, string $from, string $to): array
    {
        if ($from === $to) {
            return [];
        }

        /** @var array<string, list<array<string, mixed>>> $adjacency */
        $adjacency = [];
        foreach ($edges as $edge) {
            if ($edge['b'] === null || $edge['a'] === null || $edge['a'] === $edge['b']) {
                continue;
            }
            $adjacency[(string) $edge['a']][] = $edge;
            $adjacency[(string) $edge['b']][] = $edge;
        }
        if (! isset($adjacency[$from], $adjacency[$to])) {
            return [];
        }

        // hop count first, so the enumeration below only ever walks towards the destination
        $distance = [$to => 0];
        $queue = [$to];
        while ($queue !== []) {
            $node = array_shift($queue);
            foreach ($adjacency[$node] ?? [] as $edge) {
                $next = (string) $edge['a'] === $node ? (string) $edge['b'] : (string) $edge['a'];
                if (! isset($distance[$next])) {
                    $distance[$next] = $distance[$node] + 1;
                    $queue[] = $next;
                }
            }
        }
        if (! isset($distance[$from]) || $distance[$from] > self::MAX_HOPS) {
            return [];
        }

        $paths = [];
        self::walk($adjacency, $distance, $from, $to, [], $paths);

        return $paths;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $adjacency
     * @param  array<string, int>  $distance
     * @param  list<array<string, mixed>>  $sofar
     * @param  list<list<array<string, mixed>>>  $paths
     */
    private static function walk(array $adjacency, array $distance, string $node, string $to, array $sofar, array &$paths): void
    {
        if (count($paths) >= self::MAX_PATHS) {
            return;
        }
        if ($node === $to) {
            $paths[] = $sofar;

            return;
        }
        foreach ($adjacency[$node] ?? [] as $edge) {
            $next = (string) $edge['a'] === $node ? (string) $edge['b'] : (string) $edge['a'];
            if (($distance[$next] ?? PHP_INT_MAX) !== $distance[$node] - 1) {
                continue;
            }
            self::walk($adjacency, $distance, $next, $to, [...$sofar, self::hop($edge, $node, $next)], $paths);
            if (count($paths) >= self::MAX_PATHS) {
                return;
            }
        }
    }

    /**
     * One hop, oriented in the direction of travel.
     *
     * @param  array<string, mixed>  $edge
     * @return array{a: string, a_ifname: string|null, a_port_id: int|null, b: string, b_ifname: string|null, b_port_id: int|null, protocol: string, state: string|null, up: bool|null, lldp: bool, wan: bool, link_key: string, live: bool}
     */
    public static function hop(array $edge, string $from, string $to): array
    {
        $forward = (string) $edge['a'] === $from;
        $out = fn (string $key) => $forward ? ($edge['a_' . $key] ?? null) : ($edge['b_' . $key] ?? null);
        $in = fn (string $key) => $forward ? ($edge['b_' . $key] ?? null) : ($edge['a_' . $key] ?? null);

        return [
            'a' => $from,
            'a_ifname' => $out('port') === null ? null : (string) $out('port'),
            'a_port_id' => $out('port_id') === null ? null : (int) $out('port_id'),
            'b' => $to,
            'b_ifname' => $in('port') === null ? null : (string) $in('port'),
            'b_port_id' => $in('port_id') === null ? null : (int) $in('port_id'),
            'protocol' => (string) $edge['protocol'],
            'state' => $edge['state'] === null ? null : (string) $edge['state'],
            'up' => $edge['up'] ?? null,
            'lldp' => (bool) ($edge['lldp'] ?? false),
            'wan' => (bool) ($edge['wan'] ?? false),
            'link_key' => (string) ($edge['link_key'] ?? ''),
            'live' => false,
        ];
    }
}
