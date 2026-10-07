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
 * It reads a finished trace result (what FabricTrace::build() returns, graph or live) and
 * walks it with TraceLine::chain(), the walk the one-liner is printed from, so the picture
 * and the text cannot disagree about the order of the devices. No database access: the
 * caller says what it knows about an address.
 *
 * Items alternate `endpoint|station`, `link`, `station|endpoint` and so on:
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
     * @return array{items: list<array<string, mixed>>, overlays: list<array<string, mixed>>, alternatives: list<array<string, mixed>>, routed: array<string, mixed>|null}
     */
    public static function build(array $result, callable $node): array
    {
        $a = self::endpoint($result['a']);
        $b = self::endpoint($result['b']);
        $legs = $result['legs'] ?? [];
        $chain = TraceLine::chain(self::asEndpoint($result['a']), self::asEndpoint($result['b']), $legs);

        $items = [self::endpointItem($a, 'source')];
        $count = count($chain);
        foreach ($chain as $i => $step) {
            $items[] = $i === 0
                ? self::accessLink($a, is_string($step['in']) ? $step['in'] : null, $a['port_id'])
                : self::betweenLink($chain[$i - 1]);
            $items[] = self::station($step, $node, $result['routed'] ?? null, $count === 1);
            if ($i === $count - 1) {
                $items[] = self::accessLink($b, is_string($step['out']) ? $step['out'] : null, $b['port_id']);
            }
        }
        $items[] = self::endpointItem($b, 'destination');

        $names = [];
        foreach ($chain as $step) {
            if ($step['address'] !== null) {
                $names[$step['address']] = (string) ($node($step['address'])['name'] ?? $step['address']);
            }
        }

        return [
            'items' => $items,
            'overlays' => self::overlays($legs, $names),
            'alternatives' => self::alternatives($legs, $names),
            'routed' => $result['routed'] ?? null,
        ];
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
        return ['type' => 'endpoint', 'role' => $role] + $endpoint;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private static function accessLink(array $endpoint, ?string $ifname, ?int $portId): array
    {
        return [
            'type' => 'link', 'kind' => 'access', 'tone' => self::TONE_UP, 'vni' => $endpoint['vni'],
            'left_if' => null, 'right_if' => $ifname, 'protocol' => null, 'state' => null,
            'ecmp' => 1, 'live' => false, 'wan' => false, 'lldp' => false,
            'port_ids' => $portId === null ? [] : [$portId],
            'esi' => $endpoint['esi'], 'df' => $endpoint['df'],
        ];
    }

    /**
     * The link from the previous station to this one: the hop the previous one carries, or a
     * gap when the stored graph has nothing between two known nodes. The access link on the
     * far side is added by the caller; `out` of the last node is the destination's port.
     *
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private static function betweenLink(array $previous): array
    {
        $hop = $previous['hop'] ?? null;
        if ($hop === null) {
            return [
                'type' => 'link', 'kind' => 'gap', 'tone' => self::TONE_GAP, 'vni' => $previous['vni'],
                'left_if' => null, 'right_if' => null, 'protocol' => null, 'state' => 'no stored path',
                'ecmp' => 1, 'live' => false, 'wan' => false, 'lldp' => false, 'port_ids' => [], 'esi' => null, 'df' => false,
            ];
        }

        $up = $hop['up'] ?? null;

        return [
            'type' => 'link', 'kind' => 'underlay',
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
     * The equal-cost alternatives of every leg that has more than one, as text lines.
     *
     * @param  list<array<string, mixed>>  $legs
     * @param  array<string, string>  $names
     * @return list<array{vni: int|null, paths: list<string>}>
     */
    private static function alternatives(array $legs, array $names): array
    {
        $out = [];
        foreach ($legs as $leg) {
            $paths = $leg['paths'] ?? [];
            if (count($paths) < 2) {
                continue;
            }
            $out[] = ['vni' => $leg['vni'] ?? null, 'paths' => array_map(fn (array $path) => TraceLine::underlay($path, $names), $paths)];
        }

        return $out;
    }
}
