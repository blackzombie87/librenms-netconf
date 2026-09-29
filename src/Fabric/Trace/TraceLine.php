<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

/**
 * The one-line form of a trace, the shape the feature was asked for (plan §12):
 *
 *   IP-A (ge-0/0/38)[LEAF-A](et-0/0/52) <-> (et-0/0/53)[LEAF-B](ge-0/0/28) IP-B
 *
 * A routed flow (plan §12.9 T6a) is the same line with the routing step named on the gateway
 * it happens on, because that is the node the two legs share:
 *
 *   IP-A (ge-0/0/38)[LEAF-A](et-0/0/52) <-> (et-0/0/53)[GW1: irb.10 → irb.20](et-0/0/1) <-> …
 *
 * One function, so the Blade, the CLI and the tests print the same string.
 */
final class TraceLine
{
    /**
     * @param  list<array{vni: int|null, from: string|null, to: string|null, path: list<array<string, mixed>>, pivot: string|null}>  $legs
     * @param  array<string, string>  $names
     */
    public static function render(Endpoint $a, Endpoint $b, array $legs, array $names = []): string
    {
        $name = fn (?string $address) => $address === null ? '?' : ($names[$address] ?? $address);
        $out = self::endpointLabel($a);

        foreach (self::chain($a, $b, $legs) as $i => $node) {
            if ($i > 0) {
                $out .= $node['gap'] ? ' … ' : ' <-> ';
            } else {
                $out .= ' ';
            }
            $out .= ($node['in'] === false ? '' : sprintf('(%s)', $node['in'] ?? '?'))
                . sprintf('[%s%s]', $name($node['address']), $node['pivot'] === null ? '' : ': ' . $node['pivot'])
                . ($node['out'] === false ? '' : sprintf('(%s)', $node['out'] ?? '?'));
        }

        return trim($out . ' ' . self::endpointLabel($b));
    }

    /**
     * The nodes of a trace in the order they are traversed, each with the interface the
     * packet comes in on and the one it leaves by.
     *
     * A gateway is one node, not two: it ends the first leg and begins the second, so the
     * legs are walked into a single chain and the routing step becomes that node's pivot.
     * `false` for an interface means *do not print one* (the two sides of a gap where no
     * path is stored); `null` means *unknown*, which prints as `?`.
     *
     * @param  list<array{vni: int|null, from: string|null, to: string|null, path: list<array<string, mixed>>, pivot: string|null}>  $legs
     * @return list<array{address: string|null, in: string|false|null, out: string|false|null, pivot: string|null, gap: bool}>
     */
    private static function chain(Endpoint $a, Endpoint $b, array $legs): array
    {
        $chain = [];
        $current = ['address' => $legs[0]['from'] ?? $a->address, 'in' => $a->ifname, 'out' => null, 'pivot' => null, 'gap' => false];

        foreach ($legs as $i => $leg) {
            foreach ($leg['path'] as $hop) {
                $current['out'] = $hop['a_ifname'] ?? null;
                $chain[] = $current;
                $current = ['address' => (string) $hop['b'], 'in' => $hop['b_ifname'] ?? null, 'out' => null, 'pivot' => null, 'gap' => false];
            }
            if ($leg['path'] === [] && $leg['from'] !== $leg['to']) {
                // two known nodes with nothing stored between them: say so rather than
                // drawing a hop that was never seen
                $current['out'] = false;
                $chain[] = $current;
                $current = ['address' => $leg['to'], 'in' => false, 'out' => null, 'pivot' => null, 'gap' => true];
            }
            if ($i < count($legs) - 1) {
                $current['pivot'] = $leg['pivot'];
            }
        }

        $current['out'] = $b->ifname;
        $chain[] = $current;

        return $chain;
    }

    /**
     * The underlay leg on its own, the arrow form the fabric page prints per hop.
     *
     * @param  list<array<string, mixed>>  $path
     * @param  array<string, string>  $names
     */
    public static function underlay(array $path, array $names = []): string
    {
        if ($path === []) {
            return '';
        }
        $name = fn (string $address) => $names[$address] ?? $address;
        $out = $name((string) $path[0]['a']);
        foreach ($path as $hop) {
            $out .= sprintf(
                ' (%s) ↔ (%s) %s',
                (string) ($hop['a_ifname'] ?? '?'),
                (string) ($hop['b_ifname'] ?? '?'),
                $name((string) $hop['b']),
            );
        }

        return $out;
    }

    private static function endpointLabel(Endpoint $endpoint): string
    {
        if ($endpoint->ips !== []) {
            return $endpoint->ips[0];
        }

        return $endpoint->mac === null ? $endpoint->query : \SafferIt\LibrenmsNetconf\Support\Mac::readable($endpoint->mac);
    }
}
