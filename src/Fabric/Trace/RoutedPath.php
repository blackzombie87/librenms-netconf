<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

/**
 * A routed (inter-VNI) flow through an anycast gateway (plan §12.9 T6a). Pure: it takes the
 * IRBs of every member, the underlay edges and the two leaves, and answers which gateway
 * bridges the two VNIs and what the two legs to it look like.
 *
 * A routed flow is two bridged legs with a router between them: the source leaf floods to
 * the gateway that owns the IRB of VNI A, the gateway routes from `irb.A` to `irb.B`, and
 * the second leg is the bridged path from that gateway to the destination leaf. Each leg has
 * its own VNI, so the tunnel, flood-list and session questions are asked twice — with the
 * right VNI each time, which is the one thing a bridged trace cannot do here.
 *
 * **The L3 context decides, not the presence of two IRBs.** A gateway with `irb.10` in VRF-A
 * and `irb.20` in VRF-B cannot route between them; saying it can is the failure mode this
 * class exists to avoid. An IRB whose context is unknown (polled before the column existed)
 * is ranked below every known match and labelled, never silently treated as one.
 *
 * Nothing here is type-5 aware. Type-5 / L3VNI prefixes are the other half of the routed
 * story (§12.9 T6b) and are still blocked on a capture no lab here has produced; this class
 * covers the centrally-routed IRB gateway, which is what the fabrics in front of us run.
 */
final class RoutedPath
{
    /**
     * @param  array<string, array<int, array{ifname: string|null, status: string|null, context: string|null, port_id: int|null}>>  $irbs  member address => VNI => IRB
     * @param  list<array<string, mixed>>  $edges  underlay rows for UnderlayPath
     * @return array{gateway: string|null, context: string|null, irb_a: array<string, mixed>|null, irb_b: array<string, mixed>|null, legs: list<array{vni: int, from: string, to: string, paths: list<list<array<string, mixed>>>, path: list<array<string, mixed>>, pivot: string|null}>, candidates: list<array{address: string, context: string|null, hops: int|null, up: bool, reason: string|null}>, reason: string|null}
     */
    public static function through(array $irbs, array $edges, string $leafA, int $vniA, string $leafB, int $vniB): array
    {
        $candidates = [];
        $rejected = [];
        foreach ($irbs as $address => $byVni) {
            $irbA = $byVni[$vniA] ?? null;
            $irbB = $byVni[$vniB] ?? null;
            if ($irbA === null || $irbB === null) {
                continue;
            }
            $context = self::sharedContext($irbA, $irbB);
            if ($context === false) {
                $rejected[] = sprintf(
                    '%s has an IRB in both, but in different L3 contexts (%s / %s)',
                    (string) $address,
                    (string) ($irbA['context'] ?? '?'),
                    (string) ($irbB['context'] ?? '?'),
                );

                continue;
            }

            $hopsA = self::hops($edges, $leafA, (string) $address);
            $hopsB = self::hops($edges, (string) $address, $leafB);
            $candidates[] = [
                'address' => (string) $address,
                'context' => $context,
                'hops' => $hopsA === null || $hopsB === null ? null : $hopsA + $hopsB,
                'up' => self::isUp($irbA) && self::isUp($irbB),
                'reason' => $context === null ? 'the L3 context of these IRBs has not been polled yet' : null,
            ];
        }

        // known context first, then both IRBs up, then the shortest way there; an
        // unreachable gateway is kept as a candidate but ranked last, because "the IRB is
        // there and the underlay is not" is an answer worth printing
        usort($candidates, fn (array $x, array $y) => [
            $x['context'] === null, ! $x['up'], $x['hops'] ?? PHP_INT_MAX, $x['address'],
        ] <=> [
            $y['context'] === null, ! $y['up'], $y['hops'] ?? PHP_INT_MAX, $y['address'],
        ]);

        $best = $candidates[0] ?? null;
        if ($best === null) {
            return [
                'gateway' => null, 'context' => null, 'irb_a' => null, 'irb_b' => null, 'legs' => [], 'candidates' => [],
                'reason' => $rejected === []
                    ? sprintf('no member has an IRB in both VNI %d and VNI %d', $vniA, $vniB)
                    : implode('; ', $rejected),
            ];
        }

        $gateway = $best['address'];
        $irbA = $irbs[$gateway][$vniA];
        $irbB = $irbs[$gateway][$vniB];
        $pathsA = $leafA === $gateway ? [] : UnderlayPath::between($edges, $leafA, $gateway);
        $pathsB = $leafB === $gateway ? [] : UnderlayPath::between($edges, $gateway, $leafB);

        return [
            'gateway' => $gateway,
            'context' => $best['context'],
            'irb_a' => $irbA,
            'irb_b' => $irbB,
            'legs' => [
                ['vni' => $vniA, 'from' => $leafA, 'to' => $gateway, 'paths' => $pathsA, 'path' => $pathsA[0] ?? [], 'pivot' => self::pivot($irbA, $irbB)],
                ['vni' => $vniB, 'from' => $gateway, 'to' => $leafB, 'paths' => $pathsB, 'path' => $pathsB[0] ?? [], 'pivot' => null],
            ],
            'candidates' => $candidates,
            'reason' => null,
        ];
    }

    /**
     * The context two IRBs share: the name when both agree, `null` when either has not been
     * polled for it, and `false` when they are in different instances and therefore cannot
     * route to one another at all.
     *
     * @param  array{ifname: string|null, status: string|null, context: string|null, port_id: int|null}  $a
     * @param  array{ifname: string|null, status: string|null, context: string|null, port_id: int|null}  $b
     */
    public static function sharedContext(array $a, array $b): string|false|null
    {
        if ($a['context'] === null || $b['context'] === null) {
            return null;
        }

        return $a['context'] === $b['context'] ? $a['context'] : false;
    }

    /**
     * The label of the routing step itself: `irb.10 → irb.20`.
     *
     * @param  array{ifname: string|null, status: string|null, context: string|null, port_id: int|null}  $a
     * @param  array{ifname: string|null, status: string|null, context: string|null, port_id: int|null}  $b
     */
    private static function pivot(array $a, array $b): string
    {
        return sprintf('%s → %s', (string) ($a['ifname'] ?? 'irb?'), (string) ($b['ifname'] ?? 'irb?'));
    }

    /**
     * @param  array{ifname: string|null, status: string|null, context: string|null, port_id: int|null}  $irb
     */
    private static function isUp(array $irb): bool
    {
        return $irb['status'] !== null && strtolower($irb['status']) === 'up';
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private static function hops(array $edges, string $from, string $to): ?int
    {
        if ($from === $to) {
            return 0;
        }
        $paths = UnderlayPath::between($edges, $from, $to);

        return $paths === [] ? null : count($paths[0]);
    }
}
