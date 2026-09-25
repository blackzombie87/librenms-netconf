<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * What shape a fabric has, as three independent answers rather than one label (plan §11 E1): the
 * underlay template, where the routing happens,
 * and whether the overlay is a mesh, a route-reflector fabric or neither. The production
 * fabric is CRB **and** a leaf mesh **and** a full mesh at the same time, so a single enum
 * would have to lie about two of them.
 *
 * Pure: no query, no facade. `FabricTopologyInput` hands it arrays. It does not recompute the
 * ≥2-member far-end set either — it echoes the list it was given, so the two pictures cannot
 * disagree about which far end is a spine nobody monitors.
 *
 * Nothing here reads the stored role to decide a tier. `FabricResolver` marks a member
 * `gateway` as soon as any VNI has an IRB, so an ERB leaf is stored as a gateway; tiering on
 * that would lift every ERB leaf out of its site. The tier comes from "has IRBs" crossed with
 * "also has ESI-LAGs", and the card keeps the stored role colour so the Members tab and the
 * picture still agree.
 */
final class FabricShape
{
    public const UNDERLAY_LEAF_MESH = 'leaf-mesh';

    public const UNDERLAY_SPINE_LEAF = 'spine-leaf';

    public const ROUTING_NONE = 'none';

    public const ROUTING_CRB = 'crb';

    public const ROUTING_ERB = 'erb';

    public const ROUTING_MIXED = 'mixed';

    public const OVERLAY_FULL = 'full-mesh';

    public const OVERLAY_RR = 'route-reflector';

    public const OVERLAY_PARTIAL = 'partial';

    public const TIER_SPINE = 'spine';

    public const TIER_GATEWAY = 'gateway';

    public const TIER_LEAF = 'leaf';

    /**
     * @param  list<array{ip: string, device_id: int|null, role: string, collected: bool, irbs: int, lag_esis: int}>  $members
     *                                                                                                                          lag_esis counts local ESI rows that are not gateway segments (EsiKind)
     * @param  list<array{0: string, 1: string}>  $overlay  directed neighbour pairs, already canonical (Topology::overlayPairs())
     * @param  list<string>  $sharedFarEnds  unresolved underlay far ends that ≥2 members peer with (plan §10.12)
     * @param  list<array{device_id: int, peer_ip: string}>  $missingSessions  OverlaySessions::missing() rows
     * @return array{underlay: string, routing: string, overlay: string, evidence: string, badge: string, tier: array<string, string>, shared_far_ends: list<string>, faults: list<array{kind: string, a: string, b: string, device_id: int|null, peer_ip: string|null}>, overlay_edges: list<array{kind: string, a: string, b: string, id: string}>, counts: array<string, int>}
     */
    public static function classify(array $members, array $overlay, array $sharedFarEnds, array $missingSessions): array
    {
        $byIp = [];
        $ipOfDevice = [];
        foreach ($members as $m) {
            $byIp[$m['ip']] = $m;
            if ($m['device_id'] !== null) {
                $ipOfDevice[$m['device_id']] ??= $m['ip'];
            }
        }

        // ---- routing: counted on collected members only. A member the plugin has no rows for
        // reads irbs = 0 and lag_esis = 0 because its tables are empty, not because it has none
        $central = [];
        $erbStyle = [];
        $plainWithLag = 0;
        foreach ($byIp as $ip => $m) {
            if (! $m['collected']) {
                continue;
            }
            if ($m['irbs'] > 0 && $m['lag_esis'] === 0) {
                $central[] = $ip;
            } elseif ($m['irbs'] > 0) {
                $erbStyle[] = $ip;
            } elseif ($m['lag_esis'] > 0) {
                $plainWithLag++;
            }
        }
        $routing = match (true) {
            $central !== [] && $erbStyle === [] => self::ROUTING_CRB,
            $central === [] && $erbStyle !== [] => self::ROUTING_ERB,
            $central !== [] && $erbStyle !== [] => self::ROUTING_MIXED,
            default => self::ROUTING_NONE,
        };

        // ---- underlay template: a stored spine, or an unmonitored node several members peer with
        $spines = array_keys(array_filter($byIp, fn ($m) => $m['role'] === FabricGraph::ROLE_SPINE));
        $underlay = $spines !== [] || $sharedFarEnds !== [] ? self::UNDERLAY_SPINE_LEAF : self::UNDERLAY_LEAF_MESH;

        $gatewayTier = $routing === self::ROUTING_CRB || $routing === self::ROUTING_MIXED ? $central : [];
        $tier = [];
        foreach ($byIp as $ip => $m) {
            $tier[$ip] = match (true) {
                $underlay === self::UNDERLAY_SPINE_LEAF && $m['role'] === FabricGraph::ROLE_SPINE => self::TIER_SPINE,
                in_array($ip, $gatewayTier, true) => self::TIER_GATEWAY,
                default => self::TIER_LEAF,
            };
        }

        // ---- overlay: pairs among the collected members only. An unpolled member cannot list
        // anyone, and counting it would punch a hole in a healthy mesh of the rest (plan §10.4)
        $collected = array_keys(array_filter($byIp, fn ($m) => $m['collected']));
        $collectedSet = array_fill_keys($collected, true);
        $pairs = [];
        foreach ($overlay as [$a, $b]) {
            if ($a === $b || ! isset($collectedSet[$a], $collectedSet[$b])) {
                continue;
            }
            $key = self::pairKey($a, $b);
            $pairs[$key]['a'] = FabricGraph::compare($a, $b) <= 0 ? $a : $b;
            $pairs[$key]['b'] = FabricGraph::compare($a, $b) <= 0 ? $b : $a;
            $pairs[$key]['dirs'][$a] = true;
        }
        $pairCount = count($pairs);
        $full = count($collected) > 1 ? intdiv(count($collected) * (count($collected) - 1), 2) : 0;

        $faults = [];
        foreach ($pairs as $p) {
            if (count($p['dirs']) < 2) {
                $faults[] = ['kind' => 'asymmetric', 'a' => $p['a'], 'b' => $p['b'], 'device_id' => null, 'peer_ip' => null];
            }
        }
        $asymmetric = count($faults);
        foreach ($missingSessions as $m) {
            $a = $ipOfDevice[$m['device_id']] ?? null;
            if ($a === null) {
                // a device that is not a member of this fabric gets no invented address
                continue;
            }
            $faults[] = ['kind' => 'missing', 'a' => $a, 'b' => $m['peer_ip'], 'device_id' => $m['device_id'], 'peer_ip' => $m['peer_ip']];
        }
        $missingCount = count($faults) - $asymmetric;

        // route reflectors: tier nodes above the leaves that a collected leaf actually peers with
        $collectedLeaves = array_values(array_filter($collected, fn ($ip) => $tier[$ip] === self::TIER_LEAF));
        $neighboursOfLeaf = [];
        foreach ($pairs as $p) {
            foreach ([[$p['a'], $p['b']], [$p['b'], $p['a']]] as [$x, $y]) {
                if ($tier[$x] === self::TIER_LEAF) {
                    $neighboursOfLeaf[$x][$y] = true;
                }
            }
        }
        $reflectors = [];
        foreach ($neighboursOfLeaf as $peers) {
            foreach (array_keys($peers) as $peer) {
                if ($tier[$peer] !== self::TIER_LEAF) {
                    $reflectors[$peer] = true;
                }
            }
        }
        $reflectors = array_keys($reflectors);

        $leafToLeaf = false;
        foreach ($pairs as $p) {
            $leafToLeaf = $leafToLeaf || ($tier[$p['a']] === self::TIER_LEAF && $tier[$p['b']] === self::TIER_LEAF);
        }
        $everyLeafOnAReflector = $collectedLeaves !== [];
        foreach ($collectedLeaves as $ip) {
            $peers = array_keys($neighboursOfLeaf[$ip] ?? []);
            $everyLeafOnAReflector = $everyLeafOnAReflector && $peers !== [] && array_diff($peers, $reflectors) === [];
        }

        $overlayVerdict = match (true) {
            count($collected) < 2 => self::OVERLAY_PARTIAL,
            $pairCount === $full && $faults === [] => self::OVERLAY_FULL,
            $pairCount > 0 && $reflectors !== [] && ! $leafToLeaf && $everyLeafOnAReflector => self::OVERLAY_RR,
            default => self::OVERLAY_PARTIAL,
        };

        $counts = [
            'members' => count($byIp),
            'collected' => count($collected),
            'uncollected' => count($byIp) - count($collected),
            'central' => count($central),
            'erb_style' => count($erbStyle),
            'plain_with_lag' => $plainWithLag,
            'spines' => count($spines),
            'shared_far_ends' => count($sharedFarEnds),
            'pairs' => $pairCount,
            'full_pairs' => $full,
            'asymmetric' => $asymmetric,
            'missing' => $missingCount,
            'leaves' => count(array_filter($tier, fn ($t) => $t === self::TIER_LEAF)),
        ];

        return [
            'underlay' => $underlay,
            'routing' => $routing,
            'overlay' => $overlayVerdict,
            'evidence' => self::evidence($routing, $underlay, $overlayVerdict, $counts, $reflectors, $erbStyle, $byIp),
            'badge' => self::badge($routing, $underlay, $overlayVerdict),
            'tier' => $tier,
            'shared_far_ends' => $sharedFarEnds,
            'faults' => $faults,
            'overlay_edges' => self::overlayEdges($overlayVerdict, $pairs, $faults),
            'counts' => $counts,
        ];
    }

    /**
     * The overlay edges the picture draws, which is not the same as the pairs that exist: a
     * healthy full mesh of 14 members is 91 arcs that say less than the sentence does, and an
     * RR fabric's absent leaf-to-leaf sessions are not faults. Faults are always drawn.
     *
     * @param  array<string, array{a: string, b: string, dirs: array<string, true>}>  $pairs
     * @param  list<array{kind: string, a: string, b: string, device_id: int|null, peer_ip: string|null}>  $faults
     * @return list<array{kind: string, a: string, b: string, id: string}>
     */
    private static function overlayEdges(string $verdict, array $pairs, array $faults): array
    {
        $edges = [];
        $faulted = [];
        foreach ($faults as $f) {
            $id = $f['kind'] === 'missing'
                ? $f['device_id'] . '|' . $f['peer_ip']
                : self::pairKey($f['a'], $f['b']);
            $edges[] = ['kind' => $f['kind'], 'a' => $f['a'], 'b' => $f['b'], 'id' => $id];
            $faulted[self::pairKey($f['a'], $f['b'])] = true;
        }

        $drawSymmetric = $verdict === self::OVERLAY_PARTIAL && count($pairs) <= Topology::OVERLAY_ARC_LIMIT;
        if ($drawSymmetric) {
            foreach ($pairs as $key => $p) {
                if (count($p['dirs']) === 2 && ! isset($faulted[$key])) {
                    $edges[] = ['kind' => 'symmetric', 'a' => $p['a'], 'b' => $p['b'], 'id' => $key];
                }
            }
        }

        return $edges;
    }

    private static function pairKey(string $a, string $b): string
    {
        return FabricGraph::compare($a, $b) <= 0 ? "$a|$b" : "$b|$a";
    }

    private static function badge(string $routing, string $underlay, string $overlay): string
    {
        $parts = array_filter([
            $routing === self::ROUTING_NONE ? null : strtoupper($routing),
            $underlay === self::UNDERLAY_SPINE_LEAF ? 'spine-leaf' : 'leaf mesh',
            $overlay === self::OVERLAY_RR ? 'RR' : null,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * One sentence that audits the three verdicts, so a reader can tell whether the classifier
     * looked at the same fabric they are.
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $reflectors
     * @param  list<string>  $erbStyle
     * @param  array<string, array{ip: string, device_id: int|null, role: string, collected: bool, irbs: int, lag_esis: int}>  $byIp
     */
    private static function evidence(string $routing, string $underlay, string $overlay, array $counts, array $reflectors, array $erbStyle, array $byIp): string
    {
        $storedGateway = false;
        foreach ($erbStyle as $ip) {
            $storedGateway = $storedGateway || ($byIp[$ip]['role'] ?? '') === FabricGraph::ROLE_GATEWAY;
        }

        $sentences = [];
        $sentences[] = match ($routing) {
            self::ROUTING_CRB => sprintf(
                'IRBs on %d collected %s that have no ESI-LAG; %d %s ESI-LAGs and no IRB.',
                $counts['central'], self::plural($counts['central'], 'member'),
                $counts['plain_with_lag'], $counts['plain_with_lag'] === 1 ? 'leaf has' : 'leaves have',
            ),
            self::ROUTING_ERB => sprintf(
                'IRBs on %d %s that also %s ESI-LAGs; no separate gateway tier.%s',
                $counts['erb_style'], self::plural($counts['erb_style'], 'leaf', 'leaves'),
                $counts['erb_style'] === 1 ? 'has' : 'have',
                $storedGateway ? ' Stored role is gateway because any IRB marks it.' : '',
            ),
            self::ROUTING_MIXED => sprintf(
                'IRBs on %d %s with no ESI-LAG and on %d %s that have both. Gateway tier kept; those %d sit with the leaves.',
                $counts['central'], self::plural($counts['central'], 'member'),
                $counts['erb_style'], self::plural($counts['erb_style'], 'leaf', 'leaves'),
                $counts['erb_style'],
            ),
            default => 'No IRBs on any collected member, so nothing routes between VNIs here.',
        };

        $sentences[] = $underlay === self::UNDERLAY_SPINE_LEAF
            ? sprintf(
                '%d %s and %d unmonitored shared far %s above %d %s.',
                $counts['spines'], self::plural($counts['spines'], 'spine'),
                $counts['shared_far_ends'], self::plural($counts['shared_far_ends'], 'end'),
                $counts['leaves'], self::plural($counts['leaves'], 'leaf', 'leaves'),
            )
            : 'No spine.';

        $uncollected = $counts['uncollected'] > 0
            ? sprintf(' %d %s no EVPN data.', $counts['uncollected'], $counts['uncollected'] === 1 ? 'member has' : 'members have')
            : '';

        $sentences[] = match ($overlay) {
            self::OVERLAY_FULL => sprintf('Full mesh of %d, %d pairs, both sides.', $counts['collected'], $counts['pairs']) . $uncollected,
            self::OVERLAY_RR => sprintf(
                'Every collected leaf peers with %s; leaf-to-leaf sessions are not expected.',
                self::andList($reflectors),
            ) . $uncollected,
            default => ($counts['pairs'] === 0
                ? sprintf('No EVPN neighbour pairs among %d collected %s.', $counts['collected'], self::plural($counts['collected'], 'member'))
                : sprintf('%d of %d possible neighbour pairs among %d collected members.', $counts['pairs'], $counts['full_pairs'], $counts['collected'])) . $uncollected,
        };

        if ($counts['asymmetric'] > 0) {
            $sentences[] = sprintf('%d %s listed by one side only.', $counts['asymmetric'], self::plural($counts['asymmetric'], 'pair'));
        }
        if ($counts['missing'] > 0) {
            $sentences[] = sprintf('%d %s every other member has.', $counts['missing'], $counts['missing'] === 1 ? 'member lacks a session' : 'members lack a session');
        }

        return implode(' ', $sentences);
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n === 1 ? $one : ($many ?? $one . 's');
    }

    /**
     * @param  list<string>  $items
     */
    private static function andList(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
