<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use SafferIt\LibrenmsNetconf\Fabric\Trace\Endpoint;
use SafferIt\LibrenmsNetconf\Fabric\Trace\TraceLine;
use SafferIt\LibrenmsNetconf\Support\Mac;

/**
 * The trace as a picture instead of a line of text: a sequence of cards (the two endpoints
 * and the devices the path goes through) joined by links that carry the interfaces on both
 * ends, the VNI, the protocol and its state.
 *
 * It reads a finished trace result (what TraceRunner::run() returns, graph or live) and
 * walks every route in it with TraceLine::chain(), the walk the one-liner is printed from,
 * so the picture and the text cannot disagree about the order of the devices. No database
 * access: the caller says what it knows about an address.
 *
 * A trace has more than one route when an endpoint hangs off an Ethernet Segment (one route
 * per pairing of the PEs of A and of B, `branches` in the result) and when the underlay has
 * several paths of the same length (`paths` of a leg). Every combination is one flat
 * sequence of items; the sequences are then merged into blocks: what all of them share stays
 * one run of cards, and where they differ the picture splits into branches that meet again at
 * the next card they have in common. A split inside a branch is a split inside a block.
 *
 * Items alternate `endpoint|station`, `link`, `station|endpoint` and so on, each with a `key`
 * that says whether two items in different sequences are the same card or the same link:
 *
 *   endpoint  the two ends of the trace (an IP or a MAC), with where it was found
 *   station   a device on the way; `pivot` is set where a routed flow changes VNI
 *   link      access link (endpoint ↔ device), underlay hop, or a gap where nothing is stored
 */
final class TracePathDiagram
{
    public const TONE_UP = 'up';

    public const TONE_DOWN = 'down';

    public const TONE_UNKNOWN = 'unknown';

    public const TONE_GAP = 'gap';

    public const TONE_WAN = 'wan';

    /** Combinations of equal-length paths drawn for one route; above it, the first path of each leg. */
    public const MAX_COMBINATIONS = 8;

    /** Where an endpoint was learnt, in words an operator reads. */
    private const SOURCES = [
        Endpoint::SOURCE_EVPN_MAC_IP => 'EVPN IP/MAC table',
        Endpoint::SOURCE_EVPN_LOCAL => 'EVPN MAC database (local)',
        Endpoint::SOURCE_EVPN_ESI => 'EVPN MAC database (ESI)',
        Endpoint::SOURCE_EVPN_ESI_UNKNOWN => 'EVPN, segment owner unknown',
        Endpoint::SOURCE_EVPN_REMOTE => 'EVPN MAC database (remote)',
        Endpoint::SOURCE_FDB => 'core bridge table',
        Endpoint::SOURCE_ARP => 'core ARP table',
    ];

    /**
     * @param  array<string, mixed>  $result  a successful trace result
     * @param  callable(string): array{name?: string, role?: string, border?: bool}  $node  what the caller knows about an address
     * @return array{blocks: list<array<string, mixed>>, overlays: list<array<string, mixed>>, routed: array<string, mixed>|null, routes: int, paths: int}
     */
    public static function build(array $result, callable $node): array
    {
        $routes = [['a' => $result['a'], 'b' => $result['b'], 'legs' => $result['legs'] ?? [], 'routed' => $result['routed'] ?? null]];
        foreach ($result['branches'] ?? [] as $branch) {
            $routes[] = ['a' => $branch['a'], 'b' => $branch['b'], 'legs' => $branch['legs'] ?? [], 'routed' => $branch['routed'] ?? null];
        }

        $sequences = [];
        $overlays = [];
        $names = [];
        foreach ($routes as $route) {
            foreach (self::variants($route['legs']) as $legs) {
                $sequences[] = self::items($route['a'], $route['b'], $legs, $route['routed'], $node, $names);
            }
            foreach (self::overlays($route['legs'], $names) as $overlay) {
                $overlays[$overlay['from_address'] . '>' . $overlay['to_address'] . '|' . $overlay['vni']] = $overlay;
            }
        }

        $blocks = self::factor($sequences);
        self::label($blocks);

        return [
            'blocks' => $blocks,
            'overlays' => array_values($overlays),
            'routed' => $result['routed'] ?? null,
            'routes' => count($routes),
            'paths' => count($sequences),
        ];
    }

    /**
     * Every way through a route's legs: the equal-length paths of each leg, combined. Capped,
     * because two legs of eight paths each are 64 pictures; past the cap each leg keeps its
     * first path and the count is in the header.
     *
     * @param  list<array<string, mixed>>  $legs
     * @return list<list<array<string, mixed>>>
     */
    private static function variants(array $legs): array
    {
        $options = [];
        $product = 1;
        foreach ($legs as $leg) {
            $paths = ($leg['paths'] ?? []) === [] ? [$leg['path'] ?? []] : $leg['paths'];
            $options[] = $paths;
            $product *= max(1, count($paths));
        }
        if ($product > self::MAX_COMBINATIONS) {
            $options = array_map(fn (array $paths) => [$paths[0]], $options);
        }

        $out = [[]];
        foreach ($options as $i => $paths) {
            $next = [];
            foreach ($out as $chosen) {
                foreach ($paths as $path) {
                    $next[] = [...$chosen, ['path' => $path] + $legs[$i]];
                }
            }
            $out = $next;
        }

        return $legs === [] ? [[]] : $out;
    }

    /**
     * One flat sequence: the endpoint, its access link, every device and link on the way, the
     * access link of the other end and the endpoint.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  list<array<string, mixed>>  $legs
     * @param  array<string, mixed>|null  $routed
     * @param  callable(string): array{name?: string, role?: string, border?: bool}  $node
     * @param  array<string, string>  $names
     *
     * @param-out array<string, string> $names
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $a, array $b, array $legs, ?array $routed, callable $node, array &$names): array
    {
        $source = self::endpoint($a);
        $destination = self::endpoint($b);
        $chain = TraceLine::chain(self::asEndpoint($a), self::asEndpoint($b), $legs);

        $items = [self::endpointItem($source, 'source')];
        $count = count($chain);
        foreach ($chain as $i => $step) {
            $address = (string) $step['address'];
            $at = (string) ($node($address)['name'] ?? $address);
            $items[] = $i === 0
                ? self::accessLink($source, 'source', $address, $at, is_string($step['in']) ? $step['in'] : null, $source['port_id'])
                : self::betweenLink($chain[$i - 1], $address);
            $items[] = self::station($step, $node, $routed, $count === 1);
            $names[$address] = (string) ($node($address)['name'] ?? $address);
            if ($i === $count - 1) {
                $items[] = self::accessLink($destination, 'destination', $address, $at, is_string($step['out']) ? $step['out'] : null, $destination['port_id']);
            }
        }
        $items[] = self::endpointItem($destination, 'destination');

        return $items;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private static function endpoint(array $e): array
    {
        $ips = array_values(array_filter(array_map('strval', (array) ($e['ips'] ?? []))));
        $mac = isset($e['mac']) ? Mac::readable((string) $e['mac']) : null;
        // the address is the headline, the MAC and any further addresses sit under it
        $title = $ips[0] ?? $mac ?? (string) ($e['query'] ?? '?');
        $sub = $ips === [] ? [] : array_values(array_filter(array_merge([$mac], array_slice($ips, 1))));

        return [
            'title' => $title,
            'sub' => $sub,
            'name' => $e['name'] ?? null,
            'vni' => $e['vni'] ?? null,
            'esi' => $e['esi'] ?? null,
            'df' => (bool) ($e['df'] ?? false),
            'source' => self::SOURCES[$e['source'] ?? ''] ?? (string) ($e['source'] ?? ''),
            'duplicate' => (bool) ($e['is_duplicate'] ?? false),
            'moves' => (int) ($e['moves'] ?? 0),
            'ifname' => $e['ifname'] ?? null,
            'port_id' => isset($e['port_id']) ? (int) $e['port_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private static function asEndpoint(array $e): Endpoint
    {
        return new Endpoint(
            query: (string) ($e['query'] ?? ''),
            kind: $e['kind'] ?? null,
            mac: $e['mac'] ?? null,
            ips: array_values((array) ($e['ips'] ?? [])),
            vni: isset($e['vni']) ? (int) $e['vni'] : null,
            deviceId: isset($e['device_id']) ? (int) $e['device_id'] : null,
            address: $e['address'] ?? null,
            name: $e['name'] ?? null,
            ifname: $e['ifname'] ?? null,
            portId: isset($e['port_id']) ? (int) $e['port_id'] : null,
            esi: $e['esi'] ?? null,
            source: (string) ($e['source'] ?? ''),
            evidence: array_values((array) ($e['evidence'] ?? [])),
        );
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private static function endpointItem(array $endpoint, string $role): array
    {
        return ['type' => 'endpoint', 'role' => $role, 'key' => 'e:' . $role . ':' . $endpoint['title']] + $endpoint;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private static function accessLink(array $endpoint, string $role, string $address, string $at, ?string $ifname, ?int $portId): array
    {
        return [
            'type' => 'link', 'kind' => 'access', 'key' => 'x:' . $role . ':' . $address . ':' . $ifname, 'at' => $at,
            'tone' => self::TONE_UP, 'vni' => $endpoint['vni'],
            'left_if' => null, 'right_if' => $ifname, 'protocol' => null, 'state' => null,
            'ecmp' => 1, 'live' => false, 'wan' => false, 'lldp' => false,
            'port_ids' => $portId === null ? [] : [$portId],
            'esi' => $endpoint['esi'], 'df' => $endpoint['df'],
        ];
    }

    /**
     * The link from the previous station to this one: the hop the previous one carries, or a
     * gap when the stored graph has nothing between two known nodes.
     *
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private static function betweenLink(array $previous, string $address): array
    {
        $hop = $previous['hop'] ?? null;
        if ($hop === null) {
            return [
                'type' => 'link', 'kind' => 'gap', 'key' => 'g:' . $previous['address'] . '>' . $address, 'tone' => self::TONE_GAP, 'vni' => $previous['vni'],
                'left_if' => null, 'right_if' => null, 'protocol' => null, 'state' => 'no stored path',
                'ecmp' => 1, 'live' => false, 'wan' => false, 'lldp' => false, 'port_ids' => [], 'esi' => null, 'df' => false,
            ];
        }

        $up = $hop['up'] ?? null;

        return [
            'type' => 'link', 'kind' => 'underlay',
            'key' => 'u:' . $hop['a'] . '>' . $hop['b'] . '|' . ($hop['a_ifname'] ?? '') . '|' . ($hop['b_ifname'] ?? ''),
            'tone' => ($hop['wan'] ?? false) && $up !== false ? self::TONE_WAN : ($up === false ? self::TONE_DOWN : ($up === true ? self::TONE_UP : self::TONE_UNKNOWN)),
            'vni' => $previous['vni'],
            'left_if' => $hop['a_ifname'] ?? null, 'right_if' => $hop['b_ifname'] ?? null,
            'protocol' => $hop['protocol'] ?? null, 'state' => $hop['state'] ?? null,
            'ecmp' => (int) ($hop['ecmp'] ?? 1), 'live' => (bool) ($hop['live'] ?? false),
            'wan' => (bool) ($hop['wan'] ?? false), 'lldp' => (bool) ($hop['lldp'] ?? false),
            'port_ids' => array_values(array_filter([$hop['a_port_id'] ?? null, $hop['b_port_id'] ?? null])),
            'esi' => null, 'df' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  callable(string): array{name?: string, role?: string, border?: bool}  $node
     * @param  array<string, mixed>|null  $routed
     * @param  bool  $only  the path is this one device: it is both ends, so its two interfaces are worth a line
     * @return array<string, mixed>
     */
    private static function station(array $step, callable $node, ?array $routed, bool $only): array
    {
        $address = $step['address'];
        $known = $address === null ? [] : $node($address);
        $pivot = $step['pivot'];

        return [
            'type' => 'station',
            'key' => 's:' . $address,
            'address' => $address,
            'name' => (string) ($known['name'] ?? $address ?? '?'),
            'role' => (string) ($known['role'] ?? 'unknown'),
            'border' => (bool) ($known['border'] ?? false),
            'pivot' => $pivot,
            'context' => $pivot === null ? null : ($routed['context'] ?? null),
            'gap' => (bool) $step['gap'],
            'local' => $only,
            'in' => is_string($step['in']) ? $step['in'] : null,
            'out' => is_string($step['out']) ? $step['out'] : null,
        ];
    }

    /**
     * One line per leg: the VXLAN tunnel between the two VTEPs, in the VNI that leg is checked in.
     *
     * @param  list<array<string, mixed>>  $legs
     * @param  array<string, string>  $names
     * @return list<array{vni: int|null, from: string, to: string, from_address: string|null, to_address: string|null}>
     */
    private static function overlays(array $legs, array $names): array
    {
        $out = [];
        foreach ($legs as $leg) {
            $from = $leg['from'] ?? null;
            $to = $leg['to'] ?? null;
            if ($from === null || $to === null || $from === $to) {
                continue;
            }
            $out[] = [
                'vni' => $leg['vni'] ?? null,
                'from' => $names[$from] ?? $from, 'to' => $names[$to] ?? $to,
                'from_address' => $from, 'to_address' => $to,
            ];
        }

        return $out;
    }

    /**
     * Merge flat sequences into blocks. What every sequence shares at the start and at the end
     * is one run; the part between them splits into one branch per way through, branches that
     * begin the same way are grouped (and merged again inside the group), and a branch that
     * ends where another begins is not repeated: identical sequences are one.
     *
     * @param  list<list<array<string, mixed>>>  $sequences
     * @return list<array<string, mixed>>
     */
    public static function factor(array $sequences): array
    {
        $unique = [];
        foreach ($sequences as $sequence) {
            $unique[implode('|', array_column($sequence, 'key'))] = $sequence;
        }
        $sequences = array_values($unique);
        if ($sequences === []) {
            return [];
        }
        if (count($sequences) === 1) {
            return [['type' => 'seq', 'items' => $sequences[0]]];
        }

        $shortest = min(array_map('count', $sequences));
        $prefix = 0;
        while ($prefix < $shortest && self::sameAt($sequences, $prefix, false)) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $shortest - $prefix && self::sameAt($sequences, $suffix, true)) {
            $suffix++;
        }

        $blocks = [];
        if ($prefix > 0) {
            $blocks[] = ['type' => 'seq', 'items' => array_slice($sequences[0], 0, $prefix)];
        }

        $groups = [];
        foreach ($sequences as $sequence) {
            $middle = array_slice($sequence, $prefix, count($sequence) - $prefix - $suffix);
            if ($middle !== []) {
                $groups[(string) $middle[0]['key']][] = $middle;
            }
        }
        if (count($groups) === 1) {
            // one way through the middle, whatever the others do there: nothing to split
            foreach (self::factor(array_values($groups)[0]) as $block) {
                $blocks[] = $block;
            }
        } elseif ($groups !== []) {
            $branches = [];
            foreach ($groups as $group) {
                $branches[] = ['label' => '', 'blocks' => self::factor($group)];
            }
            $blocks[] = ['type' => 'split', 'branches' => $branches];
        }

        if ($suffix > 0) {
            $blocks[] = ['type' => 'seq', 'items' => array_slice($sequences[0], count($sequences[0]) - $suffix)];
        }

        return self::join($blocks);
    }

    /**
     * Whether every sequence has the same item at one place, counted from the start or the end.
     *
     * @param  list<list<array<string, mixed>>>  $sequences
     */
    private static function sameAt(array $sequences, int $offset, bool $fromEnd): bool
    {
        $key = null;
        foreach ($sequences as $sequence) {
            $item = $sequence[$fromEnd ? count($sequence) - 1 - $offset : $offset] ?? null;
            if ($item === null || ($key !== null && $item['key'] !== $key)) {
                return false;
            }
            $key = $item['key'];
        }

        return true;
    }

    /**
     * Neighbouring runs are one run.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function join(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $last = count($out) - 1;
            if ($last >= 0 && $block['type'] === 'seq' && $out[$last]['type'] === 'seq') {
                $out[$last]['items'] = [...$out[$last]['items'], ...$block['items']];
            } else {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * Name each branch after what is only on it: its devices, and whether it is the segment's
     * DF; a branch that is only a link (a parallel one between the same two devices) is named
     * after the interfaces.
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @param-out list<array<string, mixed>> $blocks
     */
    private static function label(array &$blocks): void
    {
        foreach ($blocks as &$block) {
            if ($block['type'] !== 'split') {
                continue;
            }
            foreach ($block['branches'] as &$branch) {
                self::label($branch['blocks']);
                $items = [];
                foreach ($branch['blocks'] as $inner) {
                    if ($inner['type'] === 'seq') {
                        $items = [...$items, ...$inner['items']];
                    }
                }
                $first = $items[0] ?? null;
                $names = array_values(array_unique(array_filter([
                    $first !== null && $first['type'] === 'link' && $first['kind'] === 'access' ? $first['at'] : null,
                    ...array_column(array_filter($items, fn (array $i) => $i['type'] === 'station'), 'name'),
                ])));
                $branch['label'] = match (true) {
                    $names !== [] => 'via ' . implode(' › ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' …' : '')
                        . ($first !== null && ($first['df'] ?? false) ? ' · DF' : ''),
                    $first !== null && $first['type'] === 'link' && $first['kind'] === 'underlay' => trim(($first['left_if'] ?? '?') . ' ↔ ' . ($first['right_if'] ?? '?')),
                    default => '',
                };
            }
            unset($branch);
        }
        unset($block);
    }
}
