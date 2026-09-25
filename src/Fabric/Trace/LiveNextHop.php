<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

use App\Models\Device;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

/**
 * The live walk (plan §12.4): ask each device on the way what its own FIB says about the far
 * VTEP, and step to the next hop. One `show route <address>` per device, inside the same
 * guard and the same transport the run-command form uses.
 *
 * This is the primary engine and the graph is the fallback, for one reason: the FIB is the
 * only thing that knows which of two parallel /30s a packet takes, and the reply carries
 * `protocol-name`, so "why this next hop" comes back with the answer instead of being
 * assumed. Nothing here branches on the underlay protocol (plan §12.4a) — BGP, OSPF, IS-IS,
 * Static and Direct all read the same way.
 *
 * Bounded, because ten rapid sessions once tripped an EX4650's SSH connection rate limit:
 * at most 8 hops and 12 sessions per trace, one session per device, opened serially and
 * closed on every path. It degrades instead of guessing — a device without NETCONF, without
 * credentials or that times out ends the walk *there*, and the caller continues with the
 * stored graph for the remainder and says so. It never writes anything.
 */
final class LiveNextHop
{
    public const MAX_HOPS = 8;

    public const MAX_SESSIONS = 12;

    /**
     * The per-vendor command, kept out of the walk itself: a second vendor supplies its own
     * string and its own parser rather than editing the walk (plan §12.8 (4)).
     *
     * @var array<string, string>
     */
    public const COMMANDS = ['junos' => 'show route %s'];

    /** @var list<string> */
    public array $log = [];

    public function __construct(
        private readonly DeviceCredentials $credentials,
        private readonly TransportFactory $transports,
    ) {
    }

    /**
     * Walk from the device owning $from towards $target, one device at a time.
     *
     * @param  array<string, array{device_id: int|null, device: Device|null}>  $owners  member address => its device
     * @param  array<string, string>  $addressOwner  every interface address of the fabric => the member address that owns it
     * @param  list<array<string, mixed>>  $edges  underlay rows, used to name the two interfaces of a hop
     * @return array{hops: list<array<string, mixed>>, complete: bool, stopped: string|null}
     */
    public function walk(string $from, string $target, array $owners, array $addressOwner, array $edges): array
    {
        $hops = [];
        $sessions = 0;
        $current = $from;
        $seen = [$from => true];

        while ($current !== $target) {
            if (count($hops) >= self::MAX_HOPS || $sessions >= self::MAX_SESSIONS) {
                return ['hops' => $hops, 'complete' => false, 'stopped' => 'the hop or session budget of one trace is spent'];
            }
            $device = $owners[$current]['device'] ?? null;
            if ($device === null) {
                return ['hops' => $hops, 'complete' => false, 'stopped' => sprintf('%s is not a monitored device, so its FIB cannot be read', $current)];
            }
            $command = self::COMMANDS[(string) $device->os] ?? null;
            if ($command === null) {
                return ['hops' => $hops, 'complete' => false, 'stopped' => sprintf('no next-hop command is defined for os "%s"', (string) $device->os)];
            }

            $sessions++;
            try {
                $reply = $this->ask($device, sprintf($command, $target));
            } catch (TransportException $e) {
                return ['hops' => $hops, 'complete' => false, 'stopped' => sprintf('%s: %s', $device->displayName(), $e->getMessage())];
            }

            $nextHops = self::activeNextHops(self::parse($reply));
            if ($nextHops === []) {
                return ['hops' => $hops, 'complete' => false, 'stopped' => sprintf('%s has no active route to %s', $device->displayName(), $target)];
            }
            $chosen = $nextHops[0];
            $next = self::mapNextHop((string) $chosen['to'], $addressOwner);
            if ($next === null) {
                return ['hops' => $hops, 'complete' => false, 'stopped' => sprintf('next hop %s is not an address of a fabric member', (string) $chosen['to'])];
            }
            if (isset($seen[$next])) {
                return ['hops' => $hops, 'complete' => false, 'stopped' => sprintf('the walk returned to %s', $next)];
            }
            $seen[$next] = true;

            $hops[] = self::hop($current, $next, $chosen, $edges, count($nextHops));
            $current = $next;
        }

        return ['hops' => $hops, 'complete' => true, 'stopped' => null];
    }

    /**
     * One route reply, namespaces stripped, as route entries with their next hops. Pure.
     *
     * @return list<array{destination: string, entries: list<array{protocol: string, active: bool, next_hops: list<array{to: string, via: string, selected: bool}>}>}>
     */
    public static function parse(string $xml): array
    {
        $document = new XmlDocument($xml);
        $out = [];
        foreach ($document->rows('//route-table/rt') as $rt) {
            $entries = [];
            foreach ($document->rows('rt-entry', $rt) as $entry) {
                $nextHops = [];
                foreach ($document->rows('nh', $entry) as $nh) {
                    $nextHops[] = [
                        'to' => (string) $document->scalar('string(to)', $nh),
                        'via' => (string) $document->scalar('string(via)', $nh),
                        'selected' => $document->rows('selected-next-hop', $nh) !== [],
                    ];
                }
                $entries[] = [
                    'protocol' => (string) $document->scalar('string(protocol-name)', $entry),
                    'active' => $document->rows('active-tag', $entry) !== [],
                    'next_hops' => $nextHops,
                ];
            }
            $out[] = ['destination' => (string) $document->scalar('string(rt-destination)', $rt), 'entries' => $entries];
        }

        return $out;
    }

    /**
     * The next hops of the active entry: several of them is ECMP, and the trace says so
     * rather than pretending the first one is the path.
     *
     * @param  list<array{destination: string, entries: list<array{protocol: string, active: bool, next_hops: list<array{to: string, via: string, selected: bool}>}>}>  $routes
     * @return list<array{to: string, via: string, selected: bool, protocol: string}>
     */
    public static function activeNextHops(array $routes): array
    {
        foreach ($routes as $route) {
            foreach ($route['entries'] as $entry) {
                if (! $entry['active'] || $entry['next_hops'] === []) {
                    continue;
                }
                $out = [];
                foreach ($entry['next_hops'] as $nh) {
                    $out[] = $nh + ['protocol' => $entry['protocol']];
                }

                return $out;
            }
        }

        return [];
    }

    /**
     * A next-hop address to the member that owns it. Nothing is invented: an address no
     * member carries ends the walk instead of becoming an unnamed hop.
     *
     * @param  array<string, string>  $addressOwner
     */
    public static function mapNextHop(string $to, array $addressOwner): ?string
    {
        return $addressOwner[$to] ?? null;
    }

    /**
     * @param  array{to: string, via: string, selected: bool, protocol: string}  $nextHop
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, mixed>
     */
    private static function hop(string $from, string $to, array $nextHop, array $edges, int $branches): array
    {
        foreach ($edges as $edge) {
            $pair = [(string) $edge['a'], (string) ($edge['b'] ?? '')];
            if ($pair === [$from, $to] || $pair === [$to, $from]) {
                return UnderlayPath::hop($edge, $from, $to) + ['live' => true, 'next_hop' => $nextHop, 'ecmp' => $branches];
            }
        }

        // the FIB knows a hop the stored graph does not: print what the device said
        return [
            'a' => $from, 'a_ifname' => $nextHop['via'], 'a_port_id' => null, 'b' => $to, 'b_ifname' => null, 'b_port_id' => null,
            'protocol' => strtolower($nextHop['protocol']), 'state' => null, 'up' => null,
            'lldp' => false, 'wan' => false, 'link_key' => '', 'live' => true,
            'next_hop' => $nextHop, 'ecmp' => $branches,
        ];
    }

    private function ask(Device $device, string $command): string
    {
        $transport = $this->transports->make($this->credentials->forDevice($device));
        try {
            $reply = $transport->run($command);
            $this->log[] = sprintf('%s: %s (%.2fs)', $device->displayName(), $command, $reply->duration);

            return $reply->raw;
        } finally {
            // a timeout or an rpc-error must not leave the session open on the device
            $transport->close();
        }
    }
}
