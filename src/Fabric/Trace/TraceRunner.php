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
 */
final class TraceRunner
{
    public function __construct(
        private readonly int $fabricId,
        private readonly FabricNodes $nodes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $from, string $to, ?int $vni = null, bool $live = false, ?LiveNextHop $walker = null): array
    {
        $context = TraceContext::forFabric($this->fabricId, $this->nodes);
        $a = EndpointResolver::resolve($from, $this->nodes);
        $b = EndpointResolver::resolve($to, $this->nodes);

        $pickA = self::pick($a['candidates'], $vni);
        $pickB = self::pick($b['candidates'], $vni);
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

        $paths = [];
        $walk = null;
        if ($pickA->address !== null && $pickB->address !== null && $pickA->address !== $pickB->address) {
            $paths = UnderlayPath::between($context['edges'], $pickA->address, $pickB->address);
            if ($live && $walker !== null) {
                $walk = $this->live($walker, $pickA->address, $pickB->address, $context);
                if ($walk['hops'] !== []) {
                    $paths = $walk['complete']
                        ? [$walk['hops']]
                        : [array_merge($walk['hops'], self::remainder($context, $walk, $pickB->address))];
                }
            }
        }

        $trace = FabricTrace::build($pickA, $pickB, $paths, $context);

        return $trace + [
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'a_sources' => $a,
            'b_sources' => $b,
            'walk' => $walk,
            'mode' => $live ? 'live' : 'graph',
        ];
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
