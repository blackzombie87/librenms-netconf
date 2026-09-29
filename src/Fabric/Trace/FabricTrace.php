<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

use SafferIt\LibrenmsNetconf\Fabric\View\Topology;

/**
 * The five segments of a trace, assembled and checked (plan §12.2, §12.5, §12.6). Pure: it
 * takes two endpoints, the underlay paths between their leaves and the fabric's own tables as
 * arrays.
 *
 * The overlay is **one** logical hop — a VXLAN tunnel — and the underlay hops are what carry
 * it, so the result has two layers and the renderer draws both. Everything this class adds
 * beyond the path is a question with an answer and a reason: does A have a tunnel to B, is
 * the VNI in both flood lists, is the EVPN session up, is the DF elected for a multihomed
 * attachment, has either MAC moved. A question it cannot answer reads *unknown*, never
 * *no* — an `ip` or `lldp-only` hop has no session state, and a hop that is half up
 * (`bgp,ospf` with the OSPF down) is said out loud rather than folded into one word.
 *
 * A **routed** flow (plan §12.9 T6a) is the same thing twice: `RoutedPath` picks the gateway
 * whose IRBs bridge the two VNIs and the trace becomes two legs with a routing step between
 * them, each leg checked against its own VNI. Every overlay question below is therefore asked
 * per leg, not per trace — a bridged trace is simply the one-leg case.
 *
 * It never writes and never opens an issue. If a trace disagrees with the Checks tab, the
 * Checks tab is right.
 */
final class FabricTrace
{
    /**
     * @param  list<list<array<string, mixed>>>  $paths  UnderlayPath::between(), or a live walk
     * @param  array{names?: array<string, string>, members?: array<string, array<string, mixed>>, tunnels?: array<string, array<string, array<string, mixed>>>, flood?: array<string, array<int, list<string>>>, neighbours?: array<string, list<string>>, irbs?: array<string, array<int, array<string, mixed>>>, esis?: array<string, array<string, mixed>>}  $context
     * @param  array<string, mixed>|null  $routed  RoutedPath::through(), when the two endpoints are in different VNIs
     * @return array<string, mixed>
     */
    public static function build(Endpoint $a, Endpoint $b, array $paths, array $context = [], ?array $routed = null): array
    {
        $names = $context['names'] ?? [];
        $members = $context['members'] ?? [];
        $checks = [];
        $warnings = [];

        $sameLeaf = $a->address !== null && $a->address === $b->address;
        $legs = self::legs($a, $b, $paths, $routed);
        $path = array_merge(...array_column($legs, 'path')) ?: [];

        if (! $a->isAttachment() || ! $b->isAttachment()) {
            $warnings[] = 'At least one endpoint has no local attachment, so the path below is what the fabric would do if it had one.';
        }

        if ($a->vni !== null && $b->vni !== null) {
            $checks[] = $a->vni === $b->vni
                ? ['id' => 'same-vni', 'label' => 'Both endpoints are in the same VNI', 'ok' => true, 'detail' => sprintf('VNI %d', $a->vni)]
                : self::routedCheck($a->vni, $b->vni, $routed, $names);
            if ($routed !== null && ($routed['gateway'] ?? null) !== null) {
                $checks[] = self::irbCheck($routed, $names);
            }
        }

        foreach ($legs as $leg) {
            foreach (self::overlayChecks($leg, $context, $names) as $check) {
                $checks[] = $check;
            }
            if ($leg['from'] !== $leg['to'] && $leg['path'] === []) {
                $warnings[] = sprintf(
                    'No underlay path between %s and %s is stored, so only the overlay hop is shown%s.',
                    $names[$leg['from']] ?? $leg['from'],
                    $names[$leg['to']] ?? $leg['to'],
                    $leg['vni'] === null ? '' : sprintf(' for VNI %d', $leg['vni']),
                );
            }
        }

        foreach ([$a, $b] as $endpoint) {
            if ($endpoint->esi === null) {
                continue;
            }
            $esi = ($context['esis'] ?? [])[$endpoint->esi] ?? null;
            $checks[] = [
                'id' => 'esi-' . $endpoint->esi,
                'label' => sprintf('Multihomed on %s', $endpoint->esi),
                'ok' => $esi === null ? null : ($esi['df_ip'] ?? null) !== null,
                'detail' => $esi === null
                    ? 'the segment is not in the ESI table'
                    : sprintf(
                        '%s, DF %s%s — unicast may use either leg, BUM follows the DF',
                        (string) ($esi['mode'] ?? 'mode unknown'),
                        ($esi['df_ip'] ?? null) === null ? 'not elected' : ($names[(string) $esi['df_ip']] ?? (string) $esi['df_ip']),
                        ($esi['aliasing'] ?? null) === false ? ', aliasing off on one side' : '',
                    ),
            ];
        }

        foreach ([['A', $a], ['B', $b]] as [$side, $endpoint]) {
            if ($endpoint->isDuplicate) {
                $warnings[] = sprintf('%s: %s is suppressed by duplicate-MAC detection.', $side, (string) $endpoint->mac);
            }
            if ($endpoint->moves > 0) {
                $warnings[] = sprintf('%s: %s changed its active source %d time%s.', $side, (string) $endpoint->mac, $endpoint->moves, $endpoint->moves === 1 ? '' : 's');
            }
        }

        foreach ($path as $hop) {
            $components = Topology::sessionComponents((string) $hop['protocol'], $hop['state'] === null ? null : (string) $hop['state']);
            $down = array_values(array_filter($components, fn ($c) => ! $c['up']));
            if ($down !== [] && count($components) > 1) {
                $warnings[] = sprintf(
                    'Hop %s ↔ %s is half up: %s.',
                    $names[(string) $hop['a']] ?? (string) $hop['a'],
                    $names[(string) $hop['b']] ?? (string) $hop['b'],
                    implode(', ', array_map(fn ($c) => trim(((string) ($c['protocol'] ?? '')) . ' ' . $c['state']), $down)),
                );
            }
            foreach ([$hop['a'], $hop['b']] as $address) {
                if (! isset($members[(string) $address])) {
                    $warnings[] = sprintf('Unknown VTEP %s on the path: add it to LibreNMS to see this hop.', (string) $address);
                }
            }
            if ($hop['wan']) {
                $warnings[] = sprintf('Hop %s ↔ %s leaves the fabric: the transport between the two sites is not modelled here.', $names[(string) $hop['a']] ?? (string) $hop['a'], (string) $hop['b']);
            }
        }

        if ($routed !== null && ($routed['gateway'] ?? null) === null) {
            $warnings[] = sprintf('This is a routed flow and no gateway could be found for it: %s.', (string) ($routed['reason'] ?? 'reason unknown'));
        }

        $equal = 1;
        foreach ($legs as $leg) {
            $equal *= max(1, count($leg['paths']));
        }

        return [
            'a' => $a->toArray(),
            'b' => $b->toArray(),
            'same_leaf' => $sameLeaf,
            'local_switching' => $sameLeaf && $routed === null,
            'routed' => $routed === null ? null : [
                'gateway' => $routed['gateway'] ?? null,
                'gateway_name' => ($routed['gateway'] ?? null) === null ? null : ($names[(string) $routed['gateway']] ?? (string) $routed['gateway']),
                'context' => $routed['context'] ?? null,
                'irb_a' => $routed['irb_a'] ?? null,
                'irb_b' => $routed['irb_b'] ?? null,
                'candidates' => $routed['candidates'] ?? [],
                'reason' => $routed['reason'] ?? null,
            ],
            'legs' => $legs,
            'paths' => $paths,
            'path' => $path,
            'equal_paths' => $equal,
            'live' => $path !== [] && ($path[0]['live'] ?? false),
            'checks' => array_values(array_filter($checks)),
            'warnings' => array_values(array_unique($warnings)),
            'line' => TraceLine::render($a, $b, $legs, $names),
        ];
    }

    /**
     * The trace as legs: one for a bridged flow, two for a routed one with the routing step
     * in between. Everything downstream — the checks, the one-liner, the hop table — reads
     * this and not the flat path, so the two cases are one code path.
     *
     * @param  list<list<array<string, mixed>>>  $paths
     * @param  array<string, mixed>|null  $routed
     * @return list<array{vni: int|null, from: string|null, to: string|null, paths: list<list<array<string, mixed>>>, path: list<array<string, mixed>>, pivot: string|null}>
     */
    private static function legs(Endpoint $a, Endpoint $b, array $paths, ?array $routed): array
    {
        if ($routed !== null && ($routed['gateway'] ?? null) !== null) {
            /** @var list<array{vni: int|null, from: string|null, to: string|null, paths: list<list<array<string, mixed>>>, path: list<array<string, mixed>>, pivot: string|null}> $legs */
            $legs = $routed['legs'];

            return $legs;
        }

        return [[
            'vni' => $a->vni ?? $b->vni,
            'from' => $a->address,
            'to' => $b->address,
            'paths' => $paths,
            'path' => $paths[0] ?? [],
            'pivot' => null,
        ]];
    }

    /**
     * The overlay questions for one leg: a tunnel each way, the leg's VNI in both flood
     * lists, and the EVPN session. Asked with the leg's own VNI, which is the whole point of
     * splitting a routed flow in two — VNI A is never in the gateway's flood list for VNI B.
     *
     * @param  array{vni: int|null, from: string|null, to: string|null, paths: list<list<array<string, mixed>>>, path: list<array<string, mixed>>, pivot: string|null}  $leg
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private static function overlayChecks(array $leg, array $context, array $names): array
    {
        $from = $leg['from'];
        $to = $leg['to'];
        if ($from === null || $to === null || $from === $to) {
            return [];
        }

        $checks = [
            self::tunnelCheck($context['tunnels'] ?? [], $from, $to, $names),
            self::tunnelCheck($context['tunnels'] ?? [], $to, $from, $names),
        ];
        if ($leg['vni'] !== null) {
            $checks[] = self::floodCheck($context['flood'] ?? [], $from, $to, $leg['vni'], $names);
            $checks[] = self::floodCheck($context['flood'] ?? [], $to, $from, $leg['vni'], $names);
        }
        $checks[] = self::sessionCheck($context['neighbours'] ?? [], $from, $to, $names);

        return $checks;
    }

    /**
     * The routed verdict: which gateway bridges the two VNIs, in which L3 context, or why
     * none does. `ok` is false only when the fabric answers *no* — an unpolled context is
     * unknown, and unknown is not a failure.
     *
     * @param  array<string, mixed>|null  $routed
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function routedCheck(int $vniA, int $vniB, ?array $routed, array $names): array
    {
        $label = sprintf('Routed between VNI %d and VNI %d', $vniA, $vniB);
        $gateway = $routed === null ? null : ($routed['gateway'] ?? null);
        if ($gateway === null) {
            return [
                'id' => 'routed',
                'label' => $label,
                'ok' => false,
                'detail' => sprintf(
                    'this is a routed flow, not a bridged one, and %s.',
                    $routed === null ? 'no IRB data was loaded for this fabric' : (string) ($routed['reason'] ?? 'no gateway was found'),
                ),
            ];
        }

        $context = $routed['context'] ?? null;
        $name = $names[(string) $gateway] ?? (string) $gateway;

        return [
            'id' => 'routed',
            'label' => $label,
            'ok' => $context === null ? null : true,
            'detail' => $context === null
                ? sprintf('%s has an IRB in both VNIs, but their L3 context has not been polled yet — re-poll it to confirm the two can reach each other.', $name)
                : sprintf('%s routes between them in L3 context %s.', $name, (string) $context),
        ];
    }

    /**
     * @param  array<string, mixed>  $routed
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function irbCheck(array $routed, array $names): array
    {
        $a = $routed['irb_a'] ?? [];
        $b = $routed['irb_b'] ?? [];
        $status = fn (array $irb) => $irb['status'] === null ? null : strtolower((string) $irb['status']) === 'up';
        $both = [$status($a), $status($b)];

        return [
            'id' => 'irb-up',
            'label' => sprintf('Both IRBs on %s are up', $names[(string) $routed['gateway']] ?? (string) $routed['gateway']),
            'ok' => in_array(null, $both, true) ? null : ! in_array(false, $both, true),
            'detail' => sprintf(
                '%s %s, %s %s',
                (string) ($a['ifname'] ?? 'irb?'), (string) ($a['status'] ?? 'status unknown'),
                (string) ($b['ifname'] ?? 'irb?'), (string) ($b['status'] ?? 'status unknown'),
            ),
            'port_ids' => array_values(array_filter([$a['port_id'] ?? null, $b['port_id'] ?? null])),
        ];
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $tunnels
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function tunnelCheck(array $tunnels, string $from, string $to, array $names): array
    {
        $tunnel = $tunnels[$from][$to] ?? null;

        return [
            'id' => "tunnel:$from:$to",
            'label' => sprintf('%s has a VXLAN tunnel to %s', $names[$from] ?? $from, $names[$to] ?? $to),
            'ok' => $tunnel !== null,
            'detail' => $tunnel === null
                ? 'no vtep interface towards that VTEP'
                : sprintf('%s, %s remote MACs', (string) ($tunnel['ifname'] ?? 'vtep'), (string) ($tunnel['mac_count'] ?? '?')),
            'port_id' => $tunnel['port_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, array<int, list<string>>>  $flood
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function floodCheck(array $flood, string $from, string $to, int $vni, array $names): array
    {
        $list = $flood[$from][$vni] ?? null;

        return [
            'id' => "flood:$from:$to:$vni",
            'label' => sprintf('%s floods VNI %d to %s', $names[$from] ?? $from, $vni, $names[$to] ?? $to),
            'ok' => $list === null ? null : in_array($to, $list, true),
            'detail' => $list === null
                ? 'no flood list stored for that VNI on this member'
                : sprintf('%d VTEP%s in the flood list', count($list), count($list) === 1 ? '' : 's'),
        ];
    }

    /**
     * @param  array<string, list<string>>  $neighbours
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function sessionCheck(array $neighbours, string $a, string $b, array $names): array
    {
        $ab = in_array($b, $neighbours[$a] ?? [], true);
        $ba = in_array($a, $neighbours[$b] ?? [], true);

        return [
            'id' => "session:$a:$b",
            'label' => sprintf('EVPN session %s ↔ %s', $names[$a] ?? $a, $names[$b] ?? $b),
            'ok' => $ab && $ba,
            'detail' => match (true) {
                $ab && $ba => 'listed by both sides',
                $ab || $ba => 'listed by one side only',
                default => 'neither side lists the other',
            },
        ];
    }
}
