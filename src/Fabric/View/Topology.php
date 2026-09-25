<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Topology map of a fabric (plan §7.6): a layered layout — L3 gateways and spines on top,
 * leaves below grouped by site — with the underlay links as solid lines coloured by session
 * state, the EVPN overlay neighbour relations as thin dashed arcs (toggle) and the ESI
 * multihoming pairs as brackets under the leaves. Server-rendered inline SVG; the layout
 * itself is pure PHP so it can be unit-tested.
 */
final class Topology
{
    public const NODE_W = 128;

    public const NODE_H = 40;

    public const COL_W = 148;

    public const GROUP_GAP = 36;

    public const MARGIN = 24;

    /**
     * Above this many overlay pairs the arcs are off when the tab opens: a full mesh of n
     * members is n(n-1)/2 arcs — 91 at 14 members — and drawing them all says less than the
     * sentence "full mesh of 14 members" does.
     */
    public const OVERLAY_ARC_LIMIT = 40;

    /**
     * Load, then lay out. Every query lives in `FabricTopologyInput` so the two pictures
     * cannot drift apart; the arrays `layout()` receives are the ones it has always received.
     *
     * @return array<string, mixed> see layout()
     */
    public static function forFabric(int $fabricId, FabricNodes $nodes): array
    {
        $input = FabricTopologyInput::load($fabricId, $nodes);

        return self::layout($input['layout_nodes'], $input['layout_underlay'], $input['overlay'], $input['layout_esi_pairs']);
    }

    /**
     * The same graph as a node/edge list for the interactive map (vis-network, which LibreNMS
     * ships), with the static layout's coordinates as the starting positions so the physics
     * begins from something sensible instead of a random cloud, and the sites stay recognisable.
     *
     * The static SVG puts every member in one row: at 14 members it is 1,968 px wide and the
     * browser scales it down until the labels are unreadable, and the row cannot grow.
     *
     * @param  array<string, mixed>  $layout  the result of layout()
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, sites: list<array{key: string, label: string|null}>, outside: int, overlay_pairs: int, overlay_default: bool, mesh: array{complete: bool, members: int, pairs: int, asymmetric: int}}
     */
    public static function graph(array $layout, FabricNodes $nodes): array
    {
        /** @var array<string, array<string, mixed>> $placed */
        $placed = $layout['nodes'];
        $sites = [];
        $visNodes = [];
        foreach ($placed as $ip => $n) {
            $site = is_string($n['site'] ?? null) ? (string) $n['site'] : null;
            $key = $site ?? ($n['layer'] === 'top' ? '_top' : '_other');
            $sites[$key] ??= ['key' => $key, 'label' => $site];
            $device = $nodes->device((string) $ip);
            $visNodes[] = [
                'id' => (string) $ip,
                'name' => (string) $n['name'],
                'ip' => (string) $ip,
                'role' => (string) $n['role'],
                'border' => (bool) $n['border'],
                'site' => $key,
                'site_label' => $site,
                'monitored' => $n['device_id'] !== null,
                'collected' => $n['device_id'] !== null && $nodes->isCollected((int) $n['device_id']),
                'down' => $n['status'] === false,
                'url' => $device === null ? null : \LibreNMS\Util\Url::deviceUrl($device),
                'x' => (int) $n['x'],
                'y' => (int) $n['y'],
            ];
        }

        $edges = [];
        foreach ($layout['underlay'] as $e) {
            $edges[] = [
                'kind' => $e['protocol'] === 'lldp-only' ? 'lldp' : ($e['wan'] ? 'wan' : 'underlay'),
                'from' => (string) $e['a'],
                'to' => (string) $e['b'],
                'label' => trim(((string) $e['protocol']) . ' ' . ((string) ($e['state'] ?? ''))),
                'up' => $e['up'],
                'title' => sprintf(
                    '%s %s ↔ %s %s: %s %s%s%s',
                    $nodes->name((string) $e['a']), (string) ($e['a_port'] ?? ''),
                    $nodes->name((string) $e['b']), (string) ($e['b_port'] ?? ''),
                    (string) $e['protocol'], (string) ($e['state'] ?? ''),
                    $e['network'] !== null ? ', ' . $e['network'] : '',
                    $e['lldp'] ? ', LLDP confirmed' : '',
                ),
            ];
        }
        // one node per far-end address, not per half link: the SVG has to draw a stub under
        // every member, but here the ten leaves that peer with the same unmonitored spine
        // should meet at one dot, which is what makes it look like the spine it is
        $stub = 0;
        $outside = 0;
        $stubIds = [];
        foreach ($layout['stubs'] as $s) {
            $far = (string) ($s['label'] ?? '');
            $id = $far === '' ? 'stub:' . (++$stub) : 'far:' . $far;
            $isOutside = (bool) ($s['outside'] ?? false);
            $outside += $isOutside ? 1 : 0;
            if (isset($stubIds[$id])) {
                $edges[] = self::stubEdge($s, $id, $isOutside, $nodes);

                continue;
            }
            $stubIds[$id] = true;
            $visNodes[] = [
                'id' => $id, 'name' => (string) ($s['label'] ?? 'unknown'), 'ip' => (string) ($s['label'] ?? ''),
                'role' => $isOutside ? 'outside' : 'stub', 'border' => false, 'site' => null, 'site_label' => null,
                'monitored' => false, 'collected' => false, 'down' => false, 'url' => null,
                'x' => (int) $s['x2'], 'y' => (int) $s['y2'],
            ];
            $edges[] = self::stubEdge($s, $id, $isOutside, $nodes);
        }
        $asymmetric = 0;
        foreach ($layout['overlay'] as $o) {
            $odd = ! $o['symmetric'] && $o['both_monitored'];
            $asymmetric += $odd ? 1 : 0;
            $edges[] = [
                'kind' => 'overlay',
                'from' => (string) $o['a'],
                'to' => (string) $o['b'],
                'label' => '',
                'up' => ! $odd,
                'title' => sprintf('EVPN neighbours %s ↔ %s%s', $nodes->name((string) $o['a']), $nodes->name((string) $o['b']), $odd ? ' — listed by one side only' : ''),
            ];
        }
        foreach ($layout['esi'] as $b) {
            $edges[] = [
                'kind' => 'esi',
                'from' => (string) $b['a'],
                'to' => (string) $b['b'],
                'label' => sprintf('%d ESI%s', (int) $b['esis'], $b['esis'] === 1 ? '' : 's'),
                'up' => true,
                'title' => sprintf('%d shared ESI%s: %s ↔ %s', (int) $b['esis'], $b['esis'] === 1 ? '' : 's', $nodes->name((string) $b['a']), $nodes->name((string) $b['b'])),
            ];
        }

        $members = count($placed);
        $pairs = count($layout['overlay']);
        $full = $members > 1 ? intdiv($members * ($members - 1), 2) : 0;

        return [
            'nodes' => $visNodes,
            'edges' => $edges,
            'sites' => array_values($sites),
            'outside' => $outside,
            'overlay_pairs' => $pairs,
            'overlay_default' => $pairs <= self::OVERLAY_ARC_LIMIT,
            'mesh' => ['complete' => $pairs > 0 && $pairs === $full && $asymmetric === 0, 'members' => $members, 'pairs' => $pairs, 'asymmetric' => $asymmetric],
        ];
    }

    /**
     * Directed overlay pairs (member address, neighbour address) from the EVPN neighbour rows.
     * The far end is canonicalised: a neighbour listed by its router-id is drawn at the
     * member address of that device, otherwise layout() would drop the arc (F3 review Issue 2).
     *
     * @param  iterable<array{0: int, 1: string}>  $neighbours  (device_id, neighbor_ip) rows
     * @return list<array{0: string, 1: string}>
     */
    public static function overlayPairs(FabricNodes $nodes, iterable $neighbours): array
    {
        $overlay = [];
        foreach ($neighbours as [$deviceId, $neighbourIp]) {
            $a = $nodes->addressOf($deviceId);
            if ($a !== null) {
                $overlay[] = [$a, $nodes->canonical($neighbourIp)];
            }
        }

        return $overlay;
    }

    /**
     * One half link as an edge towards its far-end node.
     *
     * @param  array<string, mixed>  $stub
     * @return array<string, mixed>
     */
    private static function stubEdge(array $stub, string $id, bool $outside, FabricNodes $nodes): array
    {
        return [
            'kind' => $outside ? 'outside' : ($stub['protocol'] === 'lldp-only' ? 'lldp' : ($stub['wan'] ? 'wan' : 'underlay')),
            'from' => (string) $stub['a'],
            'to' => $id,
            'label' => trim(((string) $stub['protocol']) . ' ' . ((string) ($stub['state'] ?? ''))),
            'up' => $stub['up'],
            'title' => sprintf(
                '%s %s → %s: %s %s — %s',
                $nodes->name((string) $stub['a']), (string) ($stub['a_port'] ?? ''), (string) ($stub['label'] ?? 'unknown'),
                (string) $stub['protocol'], (string) ($stub['state'] ?? ''),
                $outside
                    ? 'a session out of the fabric: no other member peers with this address'
                    : 'far end not resolved to a fabric member, although other members peer with it too',
            ),
        ];
    }

    /** Height of an arc between two nodes of the same row: grows with the distance, flattening out. */
    public static function arcLift(float $dx, bool $underlay = false): int
    {
        return (int) round($underlay ? 20 + sqrt($dx) * 2.5 : 30 + sqrt($dx) * 3);
    }

    /**
     * Whether every routing session of one underlay edge is up.
     *
     * An edge carries joined strings rather than one session: `UnderlayResolver` writes the
     * protocols as `implode(',', …)` and the states as `implode('/', array_unique(…))`, so a
     * link that runs BGP and OSPF reads `bgp,ospf` / `Established/Down` while its OSPF is
     * down. Each state component is therefore judged on its own and one component that is
     * not up makes the whole edge down, in either order of the joined string (plan §12.7 T9).
     *
     * `null` means "no session state at all" — an `ip` or `lldp-only` edge, which a renderer
     * draws as unknown and never as down (plan §12.4a).
     */
    public static function sessionUp(string $protocol, ?string $state): ?bool
    {
        $components = self::sessionComponents($protocol, $state);
        if ($components === []) {
            return null;
        }
        foreach ($components as $component) {
            if (! $component['up']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The edge's sessions one by one, so a trace hop can name the protocol that is down
     * instead of folding `bgp,ospf` into one word (plan §12.6).
     *
     * The protocol is paired with the state only when the two joined lists have the same
     * length — `UnderlayResolver` builds them in the same order, but `array_unique()` on the
     * states can collapse or keep components independently of the protocol list. A component
     * whose protocol cannot be named that way carries `null`, never a guess.
     *
     * @return list<array{protocol: string|null, state: string, up: bool}>
     */
    public static function sessionComponents(string $protocol, ?string $state): array
    {
        if ($state === null || $protocol === 'lldp-only' || $protocol === 'ip') {
            return [];
        }
        $states = array_values(array_filter(array_map(trim(...), explode('/', $state)), fn ($s) => $s !== ''));
        $protocols = array_values(array_filter(array_map(trim(...), explode(',', $protocol)), fn ($s) => $s !== '' && $s !== 'lldp-only' && $s !== 'ip'));
        $paired = count($protocols) === count($states);

        $components = [];
        foreach ($states as $i => $s) {
            $components[] = ['protocol' => $paired ? $protocols[$i] : null, 'state' => $s, 'up' => self::stateUp($s)];
        }

        return $components;
    }

    /**
     * One session state, protocol-neutral by design (plan §12.4a): BGP's `Established`,
     * OSPF's `Full` and `2Way`, or a plain `up` from any other source.
     */
    public static function stateUp(string $state): bool
    {
        $s = strtolower($state);

        return str_contains($s, 'full') || str_contains($s, 'established') || str_contains($s, '2way') || $s === 'up';
    }

    /**
     * Pure layout.
     *
     * @param  list<array{ip: string, name: string, role: string, device_id: int|null, border: bool, site: string|null, status: bool|null}>  $nodes
     * @param  list<array<string, mixed>>  $underlay  a, b (node ip or null), b_label, protocol, state, up, lldp, wan, a_port, b_port, network
     * @param  list<array{0: string, 1: string}>  $overlay  directed neighbour pairs (member address, neighbour address)
     * @param  list<array{a: string, b: string, esis: int}>  $esiPairs
     * @return array{width: int, height: int, nodes: array<string, array<string, mixed>>, groups: list<array<string, mixed>>, underlay: list<array<string, mixed>>, overlay: list<array<string, mixed>>, esi: list<array<string, mixed>>, stubs: list<array<string, mixed>>}
     */
    public static function layout(array $nodes, array $underlay, array $overlay, array $esiPairs): array
    {
        $byIp = [];
        foreach ($nodes as $n) {
            $byIp[$n['ip']] = $n;
        }

        // ESI pairing as a fallback site: union-find over the pairs
        $pairParent = [];
        $find = function (string $ip) use (&$pairParent) {
            $pairParent[$ip] ??= $ip;
            while ($pairParent[$ip] !== $ip) {
                $ip = $pairParent[$ip];
            }

            return $ip;
        };
        foreach ($esiPairs as $p) {
            if (isset($byIp[$p['a']], $byIp[$p['b']])) {
                $ra = $find($p['a']);
                $rb = $find($p['b']);
                if ($ra !== $rb) {
                    $pairParent[FabricGraph::compare($ra, $rb) <= 0 ? $rb : $ra] = FabricGraph::compare($ra, $rb) <= 0 ? $ra : $rb;
                }
            }
        }

        $top = [];
        $leaves = [];
        foreach ($byIp as $ip => $n) {
            if ($n['role'] === FabricGraph::ROLE_GATEWAY || $n['role'] === FabricGraph::ROLE_SPINE) {
                $top[] = $ip;
            } else {
                $leaves[] = $ip;
            }
        }
        usort($top, fn ($a, $b) => FabricMembers::roleRank($byIp[$a]['role']) <=> FabricMembers::roleRank($byIp[$b]['role']) ?: FabricGraph::compare($a, $b));

        // group leaves: explicit site, else the site of an ESI partner, else the ESI pair cluster
        // (named after its lowest member), else alone
        $clusterSite = [];
        foreach ($leaves as $ip) {
            if ($byIp[$ip]['site'] !== null) {
                $clusterSite[$find($ip)] ??= $byIp[$ip]['site'];
            }
        }
        /** @var array<string, array{label: string|null, members: list<string>, key: string}> $groups */
        $groups = [];
        foreach ($leaves as $ip) {
            $site = $byIp[$ip]['site'] ?? $clusterSite[$find($ip)] ?? null;
            $key = $site !== null ? 'site:' . $site : 'pair:' . $find($ip);
            if (! isset($groups[$key])) {
                $groups[$key] = ['label' => $site, 'members' => [], 'key' => $key];
            }
            $groups[$key]['members'][] = $ip;
        }
        foreach ($groups as &$g) {
            usort($g['members'], FabricGraph::compare(...));
        }
        unset($g);
        uasort($groups, function ($a, $b) {
            if (($a['label'] === null) !== ($b['label'] === null)) {
                return $a['label'] === null ? 1 : -1;
            }

            return FabricGraph::compare($a['members'][0], $b['members'][0]);
        });

        $leafCount = count($leaves);
        $groupCount = count($groups);
        $contentW = max(count($top), $leafCount) * self::COL_W + max(0, $groupCount - 1) * self::GROUP_GAP;
        $width = max(720, $contentW + 2 * self::MARGIN);
        $hasTop = $top !== [];

        // x positions first: the headroom the arcs need depends on the horizontal distances
        $placed = [];
        $x = ($width - count($top) * self::COL_W) / 2 + (self::COL_W - self::NODE_W) / 2;
        foreach ($top as $ip) {
            $placed[$ip] = $byIp[$ip] + ['x' => (int) round($x), 'y' => 0, 'layer' => 'top'];
            $x += self::COL_W;
        }
        $x = ($width - $contentW) / 2 + (self::COL_W - self::NODE_W) / 2;
        $groupBoxes = [];
        foreach ($groups as $g) {
            $startX = $x - (self::COL_W - self::NODE_W) / 2;
            foreach ($g['members'] as $ip) {
                $placed[$ip] = $byIp[$ip] + ['x' => (int) round($x), 'y' => 0, 'layer' => 'leaf', 'group' => $g['key']];
                $x += self::COL_W;
            }
            $groupBoxes[] = ['key' => $g['key'], 'label' => $g['label'], 'x' => (int) round($startX), 'y' => 0, 'w' => (int) round(count($g['members']) * self::COL_W), 'h' => self::NODE_H + 44, 'members' => $g['members']];
            $x += self::GROUP_GAP;
        }

        $headroom = 0;
        foreach ($overlay as [$a, $b]) {
            if (isset($placed[$a], $placed[$b]) && $placed[$a]['layer'] === $placed[$b]['layer']) {
                $headroom = max($headroom, self::arcLift(abs($placed[$a]['x'] - $placed[$b]['x'])));
            }
        }
        foreach ($underlay as $e) {
            if ($e['b'] !== null && isset($placed[$e['a']], $placed[$e['b']]) && $placed[$e['a']]['layer'] === $placed[$e['b']]['layer']) {
                $headroom = max($headroom, self::arcLift(abs($placed[$e['a']]['x'] - $placed[$e['b']]['x']), true));
            }
        }
        $topY = self::MARGIN + 10 + ($hasTop ? $headroom : 0);
        $leafY = $hasTop ? $topY + self::NODE_H + 150 : self::MARGIN + 30 + $headroom;
        foreach ($placed as &$n) {
            $n['y'] = $n['layer'] === 'top' ? $topY : $leafY;
        }
        unset($n);
        foreach ($groupBoxes as &$g) {
            $g['y'] = $leafY - 22;
        }
        unset($g);

        $centre = fn (string $ip, string $side = 'top') => [
            'x' => $placed[$ip]['x'] + self::NODE_W / 2,
            'y' => $side === 'top' ? $placed[$ip]['y'] : $placed[$ip]['y'] + self::NODE_H,
        ];

        // how many members have a session to the same unresolved far end. One is an outside
        // session — a transit or IX peer of a border router, which is not fabric underlay;
        // two or more is a node that talks to the fabric like a spine and is not monitored yet,
        // which is what the stubs were meant to show (plan §10.12)
        $farMembers = [];
        foreach ($underlay as $e) {
            if ($e['b'] === null && $e['b_label'] !== null) {
                $farMembers[(string) $e['b_label']][(string) $e['a']] = true;
            }
        }

        // underlay edges; half edges (far end unknown) become short stubs below/above the node
        $edges = [];
        $stubs = [];
        $stubCount = [];
        foreach ($underlay as $e) {
            if (! isset($placed[$e['a']])) {
                continue;
            }
            if ($e['b'] !== null && isset($placed[$e['b']])) {
                $a = $placed[$e['a']];
                $b = $placed[$e['b']];
                $sameLayer = $a['layer'] === $b['layer'];
                $pa = $centre($e['a'], $sameLayer || $a['layer'] === 'leaf' ? 'top' : 'bottom');
                $pb = $centre($e['b'], $sameLayer || $b['layer'] === 'leaf' ? 'top' : 'bottom');
                if ($sameLayer) {
                    // arc above the row so leaf-leaf underlay links do not cross the boxes
                    $mid = ['x' => ($pa['x'] + $pb['x']) / 2, 'y' => min($pa['y'], $pb['y']) - self::arcLift(abs($pa['x'] - $pb['x']), true)];
                    $edges[] = $e + ['path' => sprintf('M%d %d Q%d %d %d %d', $pa['x'], $pa['y'], $mid['x'], $mid['y'], $pb['x'], $pb['y']), 'lx' => $mid['x'], 'ly' => ($pa['y'] + $mid['y']) / 2];
                } else {
                    $edges[] = $e + ['path' => sprintf('M%d %d L%d %d', $pa['x'], $pa['y'], $pb['x'], $pb['y']), 'lx' => ($pa['x'] + $pb['x']) / 2, 'ly' => ($pa['y'] + $pb['y']) / 2];
                }
            } else {
                $i = $stubCount[$e['a']] = ($stubCount[$e['a']] ?? 0) + 1;
                $p = $centre($e['a'], $placed[$e['a']]['layer'] === 'leaf' ? 'bottom' : 'top');
                $dir = $placed[$e['a']]['layer'] === 'leaf' ? 1 : -1;
                $sx = $p['x'] - 24 + ($i - 1) * 16;
                $shared = count($farMembers[(string) ($e['b_label'] ?? '')] ?? []);
                $stubs[] = $e + ['x1' => $sx, 'y1' => $p['y'], 'x2' => $sx, 'y2' => $p['y'] + $dir * 26, 'label' => $e['b_label'], 'outside' => $shared < 2];
            }
        }

        // overlay: one undirected arc per pair, flagged when only one side lists the other
        $pairs = [];
        foreach ($overlay as [$a, $b]) {
            if (! isset($placed[$a], $placed[$b]) || $a === $b) {
                continue;
            }
            $key = FabricGraph::compare($a, $b) <= 0 ? "$a|$b" : "$b|$a";
            $pairs[$key]['a'] = FabricGraph::compare($a, $b) <= 0 ? $a : $b;
            $pairs[$key]['b'] = FabricGraph::compare($a, $b) <= 0 ? $b : $a;
            $pairs[$key]['dirs'][$a] = true;
        }
        $overlayEdges = [];
        foreach ($pairs as $p) {
            $pa = $centre($p['a'], 'top');
            $pb = $centre($p['b'], 'top');
            $both = ($placed[$p['a']]['device_id'] ?? null) !== null && ($placed[$p['b']]['device_id'] ?? null) !== null;
            $lift = self::arcLift(abs($pa['x'] - $pb['x']));
            $overlayEdges[] = [
                'a' => $p['a'], 'b' => $p['b'],
                'symmetric' => count($p['dirs']) === 2,
                'both_monitored' => $both,
                'path' => $placed[$p['a']]['layer'] === $placed[$p['b']]['layer']
                    ? sprintf('M%d %d Q%d %d %d %d', $pa['x'], $pa['y'], ($pa['x'] + $pb['x']) / 2, min($pa['y'], $pb['y']) - $lift, $pb['x'], $pb['y'])
                    : sprintf('M%d %d L%d %d', $pa['x'], $placed[$p['a']]['layer'] === 'leaf' ? $pa['y'] : $pa['y'] + self::NODE_H, $pb['x'], $placed[$p['b']]['layer'] === 'leaf' ? $pb['y'] : $pb['y'] + self::NODE_H),
            ];
        }

        // ESI pairs: brackets under the leaves, stacked per distinct pair so they do not overlap
        $brackets = [];
        $level = [];
        foreach ($esiPairs as $p) {
            if (! isset($placed[$p['a']], $placed[$p['b']])) {
                continue;
            }
            $pa = $centre($p['a'], 'bottom');
            $pb = $centre($p['b'], 'bottom');
            $lo = min($pa['x'], $pb['x']);
            $hi = max($pa['x'], $pb['x']);
            $depth = 0;
            foreach ($level as [$l, $h, $d]) {
                if ($lo < $h && $hi > $l && $d === $depth) {
                    $depth++;
                }
            }
            $level[] = [$lo, $hi, $depth];
            $y = $pa['y'] + 14 + $depth * 12;
            $brackets[] = $p + ['path' => sprintf('M%d %d L%d %d L%d %d L%d %d', $pa['x'], $pa['y'], $pa['x'], $y, $pb['x'], $y, $pb['x'], $pb['y']), 'lx' => ($pa['x'] + $pb['x']) / 2, 'ly' => $y - 3];
        }
        $maxBracket = 0;
        foreach ($brackets as $b) {
            $maxBracket = max($maxBracket, (int) $b['ly'] + 3);
        }
        $height = max($leafY + self::NODE_H + 60, $maxBracket + 30, count($stubs) > 0 ? $leafY + self::NODE_H + 70 : 0);

        return [
            'width' => (int) $width,
            'height' => (int) $height,
            'nodes' => $placed,
            'groups' => $groupBoxes,
            'underlay' => $edges,
            'overlay' => $overlayEdges,
            'esi' => $brackets,
            'stubs' => $stubs,
        ];
    }
}
