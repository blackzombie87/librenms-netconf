<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

/**
 * The one-line form of a trace, the shape the feature was asked for (plan §12):
 *
 *   IP-A (ge-0/0/38)[LEAF-A](et-0/0/52) <-> (et-0/0/53)[LEAF-B](ge-0/0/28) IP-B
 *
 * One function, so the Blade, the CLI and the tests print the same string.
 */
final class TraceLine
{
    /**
     * @param  list<array<string, mixed>>  $path
     * @param  array<string, string>  $names
     */
    public static function render(Endpoint $a, Endpoint $b, array $path, array $names = [], bool $sameLeaf = false): string
    {
        $labelA = self::endpointLabel($a);
        $labelB = self::endpointLabel($b);
        $name = fn (?string $address) => $address === null ? '?' : ($names[$address] ?? $address);

        if ($path === []) {
            // same leaf, or two leaves with no stored underlay between them: one node in the middle
            $node = $sameLeaf || $a->address === $b->address
                ? sprintf('(%s)[%s](%s)', $a->ifname ?? '?', $name($a->address), $b->ifname ?? '?')
                : sprintf('(%s)[%s] … [%s](%s)', $a->ifname ?? '?', $name($a->address), $name($b->address), $b->ifname ?? '?');

            return trim("$labelA $node $labelB");
        }

        $out = sprintf('%s (%s)[%s]', $labelA, $a->ifname ?? '?', $name($path[0]['a'] ?? $a->address));
        foreach ($path as $i => $hop) {
            $out .= sprintf('(%s) <-> (%s)', (string) ($hop['a_ifname'] ?? '?'), (string) ($hop['b_ifname'] ?? '?'));
            $out .= sprintf('[%s]', $name((string) $hop['b']));
            if ($i < count($path) - 1) {
                continue;
            }
            $out .= sprintf('(%s) %s', $b->ifname ?? '?', $labelB);
        }

        return trim($out);
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
