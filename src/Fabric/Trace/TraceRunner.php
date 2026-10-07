<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

use App\Models\Device;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

/**
 * Resolve, find the path, assemble — the one place the page, the CLI and the tests go
 * through, so all three answer the same question the same way (plan §12.5 T5).
 *
 * Graph mode is the default and needs nothing but the stored tables. Live mode asks each
 * device on the way what its own FIB says; it is admin-only and manual on purpose, and it
 * falls back to the stored graph for whatever it could not walk rather than stopping.
 *
 * When the two endpoints land in different VNIs the flow is routed, and `RoutedPath` turns
 * it into two legs through the gateway that owns both IRBs (plan §12.9 T6a). Both legs are
 * walked and both are checked; the only thing that changes here is that the question is
 * asked twice.
 */
final class TraceRunner
{
    /** PEs of one segment drawn per endpoint, and routes drawn per trace. */
    public const MAX_LEGS = 4;

    public const MAX_ROUTES = 4;

    public function __construct(
        private readonly int $fabricId,
        private readonly FabricNodes $nodes,
    ) {
    }

    /**
     * `$vniFrom` restricts the source endpoint and, unless `$vniTo` says otherwise, the
     * destination too — so one `--vni` still means what it always did.
     *
     * @return array<string, mixed>
     */
    public function run(string $from, string $to, ?int $vniFrom = null, ?int $vniTo = null, bool $live = false, ?LiveNextHop $walker = null): array
    {
        $context = TraceContext::forFabric($this->fabricId, $this->nodes);
        $a = EndpointResolver::resolve($from, $this->nodes);
        $b = EndpointResolver::resolve($to, $this->nodes);

        $pickA = self::pick($a['candidates'], $vniFrom);
        $pickB = self::pick($b['candidates'], $vniTo ?? $vniFrom);
        if ($pickA === null || $pickB === null) {
            return [
                'ok' => false,
                'from' => $from,
                'to' => $to,
                'a_sources' => $a,
                'b_sources' => $b,
                'reason' => sprintf('%s could not be resolved to an endpoint.', $pickA === null ? $from : $to),
            ];
        }

        // A multihomed endpoint hangs off every PE of its Ethernet Segment, and a packet to it
        // may arrive on either: each pairing of a leg of A and a leg of B is a route of its own.
        $legsA = self::attachments($a['candidates'], $pickA, $vniFrom);
        $legsB = self::attachments($b['candidates'], $pickB, $vniTo ?? $vniFrom);
        $routes = [];
        foreach ($legsA as $endA) {
            foreach ($legsB as $endB) {
                if (count($routes) < self::MAX_ROUTES) {
                    $routes[] = $this->route($endA, $endB, $context, $live, $walker);
                }
            }
        }

        $first = $routes[0];
        $trace = FabricTrace::build($first['a'], $first['b'], $first['paths'], $context, $first['routed']);
        $branches = [];
        foreach (array_slice($routes, 1) as $route) {
            $other = FabricTrace::build($route['a'], $route['b'], $route['paths'], $context, $route['routed']);
            $branches[] = array_intersect_key($other, array_flip(['a', 'b', 'legs', 'path', 'equal_paths', 'line', 'routed', 'same_leaf', 'local_switching']));
        }

        return $trace + [
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'a_sources' => $a,
            'b_sources' => $b,
            'walk' => $first['walk'],
            'mode' => $live ? 'live' : 'graph',
            'attachments' => ['a' => array_map(fn (Endpoint $e) => $e->toArray(), $legsA), 'b' => array_map(fn (Endpoint $e) => $e->toArray(), $legsB)],
            'branches' => $branches,
        ];
    }

    /**
     * One route: the path between two attachments, from the stored graph or, in live mode,
     * from the FIBs on the way.
     *
     * @param  array<string, mixed>  $context
     * @return array{a: Endpoint, b: Endpoint, paths: list<list<array<string, mixed>>>, routed: array<string, mixed>|null, walk: array{hops: list<array<string, mixed>>, complete: bool, stopped: string|null}|null}
     */
    private function route(Endpoint $a, Endpoint $b, array $context, bool $live, ?LiveNextHop $walker): array
    {
        $routed = $this->routed($a, $b, $context);
        $paths = [];
        $walk = null;
        if ($routed !== null) {
            $walk = $live && $walker !== null ? $this->liveLegs($walker, $routed, $context) : null;
        } elseif ($a->address !== null && $b->address !== null && $a->address !== $b->address) {
            $paths = UnderlayPath::between($context['edges'], $a->address, $b->address);
            if ($live && $walker !== null) {
                $walk = $this->live($walker, $a->address, $b->address, $context);
                if ($walk['hops'] !== []) {
                    $paths = $walk['complete']
                        ? [$walk['hops']]
                        : [array_merge($walk['hops'], self::remainder($context, $walk, $b->address))];
                }
            }
        }

        return ['a' => $a, 'b' => $b, 'paths' => $paths, 'routed' => $routed, 'walk' => $walk];
    }

    /**
     * The attachments of an endpoint: the one a trace picked, and the other PEs of its
     * Ethernet Segment when it is a multihomed one. The segment is known from the EVPN MAC
     * database (a MAC whose active source is `esi`), so a pick that came from the device's
     * own IP/MAC table, which names the interface but not the segment, learns it here, and
     * is only taken for multihomed when its own PE is one of the segment's.
     *
     * Two PEs that both claim the same MAC locally are not this: that is a duplicate or a
     * move, and the trace says so rather than drawing two routes.
     *
     * @param  list<Endpoint>  $candidates
     * @return list<Endpoint> the pick first, at most MAX_LEGS
     */
    public static function attachments(array $candidates, Endpoint $pick, ?int $vni = null): array
    {
        if ($pick->mac === null || $pick->address === null) {
            return [$pick];
        }
        $legs = [];
        foreach ($candidates as $candidate) {
            if ($candidate->source !== Endpoint::SOURCE_EVPN_ESI || $candidate->mac !== $pick->mac || $candidate->address === null || $candidate->esi === null) {
                continue;
            }
            if ($pick->vni !== null && $candidate->vni !== null && $candidate->vni !== $pick->vni) {
                continue;
            }
            if ($vni !== null && $candidate->vni !== null && $candidate->vni !== $vni) {
                continue;
            }
            $legs[$candidate->address] ??= $candidate;
        }
        // the pick's own PE has to be a leg of the segment, or the two are unrelated
        if (! isset($legs[$pick->address])) {
            return [$pick];
        }

        $first = $pick->esi === null ? $pick->withSegment($legs[$pick->address]->esi, $legs[$pick->address]->df) : $pick;
        $out = [$first];
        foreach ($legs as $address => $leg) {
            if ($address !== $pick->address && count($out) < self::MAX_LEGS) {
                $out[] = $leg;
            }
        }

        return $out;
    }

    /**
     * The routed decision, or null for a bridged flow. Two endpoints in different VNIs are
     * always a routed flow, even when no gateway can be found for them — the trace then says
     * so instead of drawing a bridged path that would never carry the traffic.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function routed(Endpoint $a, Endpoint $b, array $context): ?array
    {
        if ($a->vni === null || $b->vni === null || $a->vni === $b->vni || $a->address === null || $b->address === null) {
            return null;
        }

        return RoutedPath::through($context['irbs'], $context['edges'], $a->address, $a->vni, $b->address, $b->vni);
    }

    /**
     * Live mode for a routed flow: walk each leg, and fill whatever a leg could not walk
     * from the stored graph. The legs share one session budget, because the device's own
     * connection limit does not care which leg a session belongs to.
     *
     * @param  array<string, mixed>  $routed
     * @param  array<string, mixed>  $context
     * @return array{hops: list<array<string, mixed>>, complete: bool, stopped: string|null}
     */
    private function liveLegs(LiveNextHop $walker, array &$routed, array $context): array
    {
        $hops = [];
        $complete = true;
        $stopped = null;
        foreach ($routed['legs'] as $i => $leg) {
            if ($leg['from'] === $leg['to']) {
                continue;
            }
            $walk = $this->live($walker, (string) $leg['from'], (string) $leg['to'], $context);
            if ($walk['hops'] !== []) {
                $routed['legs'][$i]['path'] = $walk['complete']
                    ? $walk['hops']
                    : array_merge($walk['hops'], self::remainder($context, $walk, (string) $leg['to']));
                $routed['legs'][$i]['paths'] = [$routed['legs'][$i]['path']];
            }
            $hops = array_merge($hops, $walk['hops']);
            $complete = $complete && $walk['complete'];
            $stopped ??= $walk['stopped'];
        }

        return ['hops' => $hops, 'complete' => $complete, 'stopped' => $stopped];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{hops: list<array<string, mixed>>, complete: bool, stopped: string|null}
     */
    private function live(LiveNextHop $walker, string $from, string $to, array $context): array
    {
        $owners = [];
        foreach (array_keys($context['members']) as $address) {
            $deviceId = $this->nodes->deviceId((string) $address);
            $owners[(string) $address] = ['device_id' => $deviceId, 'device' => $deviceId === null ? null : Device::query()->find($deviceId)];
        }

        return $walker->walk($from, $to, $owners, $context['addresses'], $context['edges']);
    }

    /**
     * The stored graph for the part of the path the live walk could not reach: it degrades,
     * it does not guess.
     *
     * @param  array<string, mixed>  $context
     * @param  array{hops: list<array<string, mixed>>, complete: bool, stopped: string|null}  $walk
     * @return list<array<string, mixed>>
     */
    private static function remainder(array $context, array $walk, string $to): array
    {
        $last = $walk['hops'] === [] ? null : (string) $walk['hops'][count($walk['hops']) - 1]['b'];
        if ($last === null || $last === $to) {
            return [];
        }

        return UnderlayPath::between($context['edges'], $last, $to)[0] ?? [];
    }

    /**
     * The candidate a trace uses: the best-ranked attachment, narrowed to a VNI when one was
     * given. A row that only proves the MAC exists is never chosen over one that says where.
     *
     * @param  list<Endpoint>  $candidates
     */
    public static function pick(array $candidates, ?int $vni = null): ?Endpoint
    {
        $matching = $vni === null ? $candidates : array_values(array_filter($candidates, fn (Endpoint $e) => $e->vni === null || $e->vni === $vni));
        foreach ($matching as $candidate) {
            if ($candidate->isAttachment()) {
                return $candidate;
            }
        }

        return $matching[0] ?? null;
    }

    public static function walker(): LiveNextHop
    {
        return new LiveNextHop(app(DeviceCredentials::class), app(TransportFactory::class));
    }
}
