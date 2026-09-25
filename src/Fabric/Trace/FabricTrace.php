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
 * It never writes and never opens an issue. If a trace disagrees with the Checks tab, the
 * Checks tab is right.
 */
final class FabricTrace
{
    /**
     * @param  list<list<array<string, mixed>>>  $paths  UnderlayPath::between(), or a live walk
     * @param  array{names?: array<string, string>, members?: array<string, array<string, mixed>>, tunnels?: array<string, array<string, array<string, mixed>>>, flood?: array<string, array<int, list<string>>>, neighbours?: array<string, list<string>>, irb_vnis?: array<string, list<int>>, esis?: array<string, array<string, mixed>>}  $context
     * @return array<string, mixed>
     */
    public static function build(Endpoint $a, Endpoint $b, array $paths, array $context = []): array
    {
        $names = $context['names'] ?? [];
        $members = $context['members'] ?? [];
        $checks = [];
        $warnings = [];

        $sameLeaf = $a->address !== null && $a->address === $b->address;
        $path = $paths[0] ?? [];

        if (! $a->isAttachment() || ! $b->isAttachment()) {
            $warnings[] = 'At least one endpoint has no local attachment, so the path below is what the fabric would do if it had one.';
        }

        if ($a->vni !== null && $b->vni !== null) {
            $sameVni = $a->vni === $b->vni;
            $gateways = $sameVni ? [] : self::gatewaysFor($context['irb_vnis'] ?? [], [$a->vni, $b->vni], $names);
            $checks[] = [
                'id' => 'same-vni',
                'label' => 'Both endpoints are in the same VNI',
                'ok' => $sameVni,
                'detail' => $sameVni
                    ? sprintf('VNI %d', $a->vni)
                    : sprintf(
                        'VNI %d and VNI %d: this is a routed flow, not a bridged one.%s Inter-VNI tracing is not implemented (plan §12 T6).',
                        $a->vni, $b->vni,
                        $gateways === [] ? ' No member has an IRB in both VNIs.' : ' Both VNIs have an IRB on ' . implode(', ', $gateways) . '.',
                    ),
            ];
        }

        if (! $sameLeaf && $a->address !== null && $b->address !== null) {
            $checks[] = self::tunnelCheck($context['tunnels'] ?? [], $a->address, $b->address, $names);
            $checks[] = self::tunnelCheck($context['tunnels'] ?? [], $b->address, $a->address, $names);
            $vni = $a->vni ?? $b->vni;
            if ($vni !== null) {
                $checks[] = self::floodCheck($context['flood'] ?? [], $a->address, $b->address, $vni, $names);
                $checks[] = self::floodCheck($context['flood'] ?? [], $b->address, $a->address, $vni, $names);
            }
            $checks[] = self::sessionCheck($context['neighbours'] ?? [], $a->address, $b->address, $names);
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

        if (! $sameLeaf && $a->address !== null && $b->address !== null && $paths === []) {
            $warnings[] = 'No underlay path between the two leaves is stored, so only the overlay hop is shown.';
        }

        return [
            'a' => $a->toArray(),
            'b' => $b->toArray(),
            'same_leaf' => $sameLeaf,
            'local_switching' => $sameLeaf,
            'paths' => $paths,
            'path' => $path,
            'equal_paths' => count($paths),
            'live' => $path !== [] && ($path[0]['live'] ?? false),
            'checks' => array_values(array_filter($checks)),
            'warnings' => array_values(array_unique($warnings)),
            'line' => TraceLine::render($a, $b, $path, $names, $sameLeaf),
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

    /**
     * @param  array<string, list<int>>  $irbVnis
     * @param  list<int>  $vnis
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private static function gatewaysFor(array $irbVnis, array $vnis, array $names): array
    {
        $out = [];
        foreach ($irbVnis as $address => $carried) {
            if (array_diff($vnis, $carried) === []) {
                $out[] = $names[(string) $address] ?? (string) $address;
            }
        }

        return $out;
    }
}
