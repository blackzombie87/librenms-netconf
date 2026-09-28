<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

/**
 * What is left of the first topology picture once the vis map and the one-row SVG are gone:
 * the protocol-neutral reading of an underlay session's state, the canonicalised overlay
 * pairs, and the arc height. `EagleLayout` and `FabricTrace` are the callers now; the layered
 * layout and the vis payload this class used to build were deleted with the views that drew
 * them (plan "One picture of an EVPN fabric", PR 1).
 */
final class Topology
{
    /**
     * Above this many overlay pairs the arcs are off when the tab opens: a full mesh of n
     * members is n(n-1)/2 arcs — 91 at 14 members — and drawing them all says less than the
     * sentence "full mesh of 14 members" does.
     */
    public const OVERLAY_ARC_LIMIT = 40;

    /**
     * Directed overlay pairs (member address, neighbour address) from the EVPN neighbour rows.
     * The far end is canonicalised: a neighbour listed by its router-id is drawn at the
     * member address of that device, otherwise the picture would drop the arc (F3 review Issue 2).
     *
     * @param  iterable<array{0: int, 1: string}>  $neighbours  (device_id, neighbor_ip) rows
     * @return list<array{0: string, 1: string}>
     */
    public static function overlayPairs(FabricNodes $nodes, iterable $neighbours): array
    {
        $overlay = [];
        foreach ($neighbours as [$deviceId, $neighbourIp]) {
            $a = $nodes->addressOf($deviceId);
            if ($a !== null) {
                $overlay[] = [$a, $nodes->canonical($neighbourIp)];
            }
        }

        return $overlay;
    }

    /** Height of an arc between two nodes of the same row: grows with the distance, flattening out. */
    public static function arcLift(float $dx, bool $underlay = false): int
    {
        return (int) round($underlay ? 20 + sqrt($dx) * 2.5 : 30 + sqrt($dx) * 3);
    }

    /**
     * Whether every routing session of one underlay edge is up.
     *
     * An edge carries joined strings rather than one session: `UnderlayResolver` writes the
     * protocols as `implode(',', …)` and the states as `implode('/', array_unique(…))`, so a
     * link that runs BGP and OSPF reads `bgp,ospf` / `Established/Down` while its OSPF is
     * down. Each state component is therefore judged on its own and one component that is
     * not up makes the whole edge down, in either order of the joined string (plan §12.7 T9).
     *
     * `null` means "no session state at all" — an `ip` or `lldp-only` edge, which a renderer
     * draws as unknown and never as down (plan §12.4a).
     */
    public static function sessionUp(string $protocol, ?string $state): ?bool
    {
        $components = self::sessionComponents($protocol, $state);
        if ($components === []) {
            return null;
        }
        foreach ($components as $component) {
            if (! $component['up']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The edge's sessions one by one, so a trace hop can name the protocol that is down
     * instead of folding `bgp,ospf` into one word (plan §12.6).
     *
     * The protocol is paired with the state only when the two joined lists have the same
     * length — `UnderlayResolver` builds them in the same order, but `array_unique()` on the
     * states can collapse or keep components independently of the protocol list. A component
     * whose protocol cannot be named that way carries `null`, never a guess.
     *
     * @return list<array{protocol: string|null, state: string, up: bool}>
     */
    public static function sessionComponents(string $protocol, ?string $state): array
    {
        if ($state === null || $protocol === 'lldp-only' || $protocol === 'ip') {
            return [];
        }
        $states = array_values(array_filter(array_map(trim(...), explode('/', $state)), fn ($s) => $s !== ''));
        $protocols = array_values(array_filter(array_map(trim(...), explode(',', $protocol)), fn ($s) => $s !== '' && $s !== 'lldp-only' && $s !== 'ip'));
        $paired = count($protocols) === count($states);

        $components = [];
        foreach ($states as $i => $s) {
            $components[] = ['protocol' => $paired ? $protocols[$i] : null, 'state' => $s, 'up' => self::stateUp($s)];
        }

        return $components;
    }

    /**
     * One session state, protocol-neutral by design (plan §12.4a): BGP's `Established`,
     * OSPF's `Full` and `2Way`, or a plain `up` from any other source.
     */
    public static function stateUp(string $state): bool
    {
        $s = strtolower($state);

        return str_contains($s, 'full') || str_contains($s, 'established') || str_contains($s, '2way') || $s === 'up';
    }
}
