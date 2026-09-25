<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Eagle view of a fabric: the same role-coloured cards the static SVG got right, but wrapped
 * into site compounds and stacked in tiers instead of stretched into one row (plan §11 E2). Fourteen members in one row are 1,968 px and 26 are
 * 3,780; the browser then scales that down until the labels are unreadable, and the row cannot
 * grow. Wrapping is what makes 26 members an eagle view; the viewport is a viewBox, not a
 * layout engine.
 *
 * Pure, like `Topology::layout()`, which this does not touch: the vis map keeps seeding from
 * that one until it is removed.
 *
 * Two things the design document did not have and the review added (plan §11 E-F5, E-F6):
 * every edge carries a dash pattern as well as a colour, so state survives a greyscale print
 * or a colour-blind reader the way the cards' chips already do; and `place()` takes an
 * optional highlight set, because a trace (§12) is exactly a list of node and edge ids and
 * the alternative would be a second picture.
 */
final class EagleLayout
{
    public const TARGET_WIDTH = 1120;

    public const CARD_W = 156;

    public const CARD_H = 58;

    public const COL_GAP = 12;

    public const ROW_GAP = 16;

    public const MARGIN = 24;

    public const SITE_PAD = 10;

    public const SITE_HEADER = 20;

    public const SITE_GAP = 16;

    public const TIER_GAP = 96;

    /** Attached devices drawn under one ESI pair before the rest become a "+N" card. */
    public const ATTACHED_PER_ESI = 8;

    /** Vertical room a site box reserves for the outside stubs / attached dots it holds. */
    public const OUTSIDE_BAND = 28;

    public const ATTACHED_BAND = 44;

    /** Cards per row inside a site: roomier while there are one or two sites to place. */
    public const CAP_FEW_SITES = 4;

    public const CAP_MANY_SITES = 2;

    /**
     * The whole picture, in one pass over the three view flags: a collapsed site emits no
     * member cards, so nothing is ever drawn at a coordinate that was not emitted.
     *
     * @param  array<string, mixed>  $shape  FabricShape::classify()
     * @param  list<array<string, mixed>>  $nodes  ip, name, role, device_id, border, site, status, collected, version, version_skew, irbs, esi_degraded
     * @param  list<array<string, mixed>>  $underlay  link_key, a, b, b_label, protocol, state, up, lldp, wan, a_port, b_port, a_port_id, b_port_id, network
     * @param  list<array{kind: string, a: string, b: string, id: string}>  $overlayEdges  already filtered; every one of them is drawn
     * @param  list<string>  $sharedFarEnds  the list classify() echoed, not a fresh scan
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs  gateway segments already removed
     * @param  array{collapse?: list<string>, outside?: bool, attached?: list<array<string, mixed>>|null}  $view
     * @param  array{nodes?: list<string>, edges?: list<string>}  $highlight  a trace's node and edge ids (plan §11 E-F6)
     * @return array{width: int, height: int, nodes: array<string, array<string, mixed>>, groups: list<array<string, mixed>>, edges: list<array<string, mixed>>, sentences: list<string>, counts: array<string, int>}
     */
    public static function place(
        array $shape,
        array $nodes,
        array $underlay,
        array $overlayEdges,
        array $sharedFarEnds,
        array $esiPairs,
        array $view = [],
        array $highlight = [],
    ): array {
        /** @var array<string, string> $tier */
        $tier = $shape['tier'] ?? [];
        $collapsed = array_fill_keys($view['collapse'] ?? [], true);
        $outsideOn = (bool) ($view['outside'] ?? false);
        $attached = $view['attached'] ?? null;
        $hlNodes = array_fill_keys($highlight['nodes'] ?? [], true);
        $hlEdges = array_fill_keys($highlight['edges'] ?? [], true);

        $byIp = [];
        foreach ($nodes as $n) {
            if (isset($tier[$n['ip']])) {
                $byIp[(string) $n['ip']] = $n;
            }
        }

        // ---- site of every leaf-tier member: the location, else the ESI-pair cluster it
        // belongs to, named after its lowest member; the same rule the static SVG uses
        $parent = [];
        $find = function (string $ip) use (&$parent): string {
            $parent[$ip] ??= $ip;
            while ($parent[$ip] !== $ip) {
                $ip = $parent[$ip];
            }

            return $ip;
        };
        foreach ($esiPairs as $p) {
            if (isset($byIp[$p['a']], $byIp[$p['b']])) {
                $ra = $find($p['a']);
                $rb = $find($p['b']);
                if ($ra !== $rb) {
                    $parent[FabricGraph::compare($ra, $rb) <= 0 ? $rb : $ra] = FabricGraph::compare($ra, $rb) <= 0 ? $ra : $rb;
                }
            }
        }
        // Site compounds are built per tier, so the two answers of plan §11 E-F8 do not fight:
        // the tier decides the row a card sits on, and the compound follows the card. On the
        // production fabric that is 5 leaf compounds plus one per gateway, 7 in all, each
        // gateway alone in its own verbose facility string.
        $ipsOf = function (string $wanted) use ($byIp, $tier): array {
            return array_keys(array_filter($byIp, fn ($n) => ($tier[$n['ip']] ?? '') === $wanted));
        };
        $siteOf = [];
        $gatewaySites = self::compounds($ipsOf(FabricShape::TIER_GATEWAY), 'gateway:', $byIp, $esiPairs, $find, $siteOf);
        $leafSites = self::compounds($ipsOf(FabricShape::TIER_LEAF), '', $byIp, $esiPairs, $find, $siteOf);

        // ---- which underlay rows hang under which member, so a site box can reserve the room
        $outsideOf = [];
        $sharedSet = array_fill_keys($sharedFarEnds, true);
        foreach ($underlay as $e) {
            if ($e['b'] === null && ! isset($sharedSet[(string) ($e['b_label'] ?? '')])) {
                $outsideOf[(string) $e['a']][] = $e;
            }
        }
        $attachedOf = [];
        foreach ($attached ?? [] as $a) {
            $attachedOf[(string) ($a['anchor'] ?? '')][] = $a;
        }

        $cap = count($gatewaySites) + count($leafSites) > 2 ? self::CAP_MANY_SITES : self::CAP_FEW_SITES;
        $usable = self::TARGET_WIDTH - 2 * self::MARGIN;

        // ---- tier rows, top to bottom. The gateway tier comes from the routing verdict, the
        // spine tier from the underlay template; neither comes from the stored role alone
        $spineIps = array_keys(array_filter($byIp, fn ($n) => ($tier[$n['ip']] ?? '') === FabricShape::TIER_SPINE));
        usort($spineIps, FabricGraph::compare(...));
        $spineRow = $spineIps;
        if (($shape['underlay'] ?? '') === FabricShape::UNDERLAY_SPINE_LEAF) {
            foreach ($sharedFarEnds as $far) {
                $spineRow[] = 'far:' . $far;
            }
        } else {
            $spineRow = [];
        }
        /** @var array<string, array<string, mixed>> $placed */
        $placed = [];
        $y = self::MARGIN;
        /** @var list<array{width: int, cards: list<string>, groups: list<int>}> $rows */
        $rows = [];
        if ($spineRow !== []) {
            [$y, $tierRows] = self::placeTier($spineRow, $byIp, $tier, $usable, $y, $placed, $hlNodes);
            $rows = array_merge($rows, $tierRows);
            $y += self::TIER_GAP;
        }

        // ---- compound tiers: site boxes, wrapped inside and packed into rows of TARGET_WIDTH
        $groups = [];
        foreach ([$gatewaySites, $leafSites] as $tierSites) {
            if ($tierSites === []) {
                continue;
            }
            [$y, $tierGroups, $tierRows] = self::packTier($tierSites, $collapsed, $cap, $usable, $y, $outsideOn, $outsideOf, $attachedOf, $attached, count($groups));
            $groups = array_merge($groups, $tierGroups);
            $rows = array_merge($rows, $tierRows);
            $y += self::TIER_GAP;
        }
        $bottom = $groups === [] && $rows === [] ? $y : $y - self::TIER_GAP;

        // one width for the picture, then every row centred inside it: centring a row against
        // the target width instead would leave its cards outside a narrower viewBox
        $contentW = $rows === [] ? self::CARD_W : max(array_column($rows, 'width'));
        $width = min(self::TARGET_WIDTH, (int) $contentW + 2 * self::MARGIN);
        foreach ($rows as $row) {
            $shift = (int) round(($contentW - $row['width']) / 2);
            foreach ($row['cards'] as $id) {
                $placed[$id]['x'] += $shift;
            }
            foreach ($row['groups'] as $index) {
                $groups[$index]['x'] += $shift;
                $groups[$index]['header']['x'] += $shift;
            }
        }

        foreach ($groups as &$group) {
            self::placeSiteContents($group, $byIp, $tier, $outsideOn, $outsideOf, $attachedOf, $attached, $placed, $hlNodes);
        }
        unset($group);

        $anchorOf = self::anchors($placed, $groups, $siteOf, $collapsed);
        $edges = self::edges($underlay, $overlayEdges, $esiPairs, $placed, $groups, $anchorOf, $siteOf, $collapsed, $sharedSet, $outsideOn, $hlEdges, $tier);
        self::summarise($groups, $placed, $byIp, $underlay, $siteOf, $collapsed, $esiPairs, $outsideOf, $attachedOf, $attached, $outsideOn, $hlNodes);

        $height = (int) max($bottom + self::MARGIN, self::MARGIN * 2 + self::CARD_H);

        return [
            'width' => $width,
            'height' => $height,
            'nodes' => $placed,
            'groups' => $groups,
            'edges' => $edges,
            'sentences' => self::sentences($shape, $outsideOf, $outsideOn, $attached),
            'counts' => [
                'sites' => count($groups),
                'collapsed' => count(array_filter($groups, fn ($g) => $g['collapsed'])),
                'outside' => array_sum(array_map('count', $outsideOf)),
                'cards' => count(array_filter($placed, fn ($n) => $n['kind'] === 'member')),
            ],
        ];
    }

    /**
     * Colour **and** dash per edge, so the state reads without colour (plan §11 E-F5). A down
     * session is dashed, an unknown one dotted, an up one solid; the overlay, fault, ESI and
     * attached layers each have their own pattern as well as their own colour.
     *
     * @return array{stroke: string, dash: string, width: float, state: string}
     */
    public static function edgeStyle(string $kind, ?bool $up): array
    {
        $state = match (true) {
            in_array($kind, ['overlay', 'esi', 'attached'], true) => $kind,
            $kind === 'asymmetric' || $kind === 'missing' => 'fault',
            $up === false => 'down',
            $up === null => 'unknown',
            default => 'up',
        };

        return match ($state) {
            'overlay' => ['stroke' => '#337ab7', 'dash' => '3 3', 'width' => 1.0, 'state' => $state],
            'fault' => ['stroke' => '#d9534f', 'dash' => '6 2 2 2', 'width' => 1.5, 'state' => $state],
            'esi' => ['stroke' => '#f0ad4e', 'dash' => '', 'width' => 2.0, 'state' => $state],
            'attached' => ['stroke' => '#999999', 'dash' => '2 3', 'width' => 1.0, 'state' => $state],
            'down' => ['stroke' => '#d9534f', 'dash' => '5 3', 'width' => 3.0, 'state' => $state],
            'unknown' => ['stroke' => '#999999', 'dash' => '1 4', 'width' => 2.0, 'state' => $state],
            default => [
                'stroke' => $kind === 'wan' ? '#8e6bbf' : ($kind === 'cross-site' ? '#3d5a80' : '#5cb85c'),
                'dash' => $kind === 'wan' ? '7 3' : '',
                'width' => $kind === 'trunk' ? 4.0 : 3.0,
                'state' => $state,
            ],
        };
    }

    /**
     * The routing-protocol set of an edge: the sorted tokens, with the two that are not a
     * session dropped. `bgp` and `bgp,ospf` are different sets, so a leaf that also runs OSPF
     * does not join a BGP-only trunk (plan §12.4a — the underlay protocol is read, not assumed).
     */
    public static function protocolSet(string $protocol): string
    {
        $tokens = array_filter(array_map(trim(...), explode(',', $protocol)), fn ($t) => $t !== '' && $t !== 'lldp-only' && $t !== 'ip');
        sort($tokens);

        return implode(',', $tokens);
    }

    /**
     * The site compounds of one tier. The key is `site:{location}`, else the ESI-pair cluster
     * named after its lowest member; a tier above the leaves prefixes its own name so the two
     * cannot collide when one location has members on both.
     *
     * @param  list<string>  $ips
     * @param  array<string, array<string, mixed>>  $byIp
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs
     * @param  \Closure(string): string  $find
     * @param  array<string, string>  $siteOf
     *
     * @param-out array<string, string> $siteOf
     *
     * @return list<array{key: string, label: string|null, members: list<string>}>
     */
    private static function compounds(array $ips, string $prefix, array $byIp, array $esiPairs, \Closure $find, array &$siteOf): array
    {
        $clusterSite = [];
        foreach ($ips as $ip) {
            if (($byIp[$ip]['site'] ?? null) !== null) {
                $clusterSite[$find($ip)] ??= (string) $byIp[$ip]['site'];
            }
        }
        /** @var array<string, array{key: string, label: string|null, members: list<string>}> $sites */
        $sites = [];
        foreach ($ips as $ip) {
            $label = $byIp[$ip]['site'] ?? $clusterSite[$find($ip)] ?? null;
            $key = $prefix . ($label !== null ? 'site:' . $label : 'pair:' . $find($ip));
            $sites[$key] ??= ['key' => $key, 'label' => $label === null ? null : (string) $label, 'members' => []];
            $sites[$key]['members'][] = $ip;
            $siteOf[$ip] = $key;
        }

        return self::orderSites($sites, $esiPairs);
    }

    /**
     * One tier of site boxes, packed greedily into rows no wider than the target. Centring is
     * a later pass, once the picture's own width is known.
     *
     * @param  list<array{key: string, label: string|null, members: list<string>}>  $sites
     * @param  array<string, true>  $collapsed
     * @param  array<string, list<array<string, mixed>>>  $outsideOf
     * @param  array<string, list<array<string, mixed>>>  $attachedOf
     * @param  list<array<string, mixed>>|null  $attached
     * @param  int  $offset  index of the first of these groups in the caller's list
     * @return array{0: int, 1: list<array<string, mixed>>, 2: list<array{width: int, cards: list<string>, groups: list<int>}>}
     */
    private static function packTier(array $sites, array $collapsed, int $cap, int $usable, int $y, bool $outsideOn, array $outsideOf, array $attachedOf, ?array $attached, int $offset): array
    {
        $groups = [];
        $rows = [];
        $rowIndices = [];
        $rowTop = $y;
        $rowHeight = 0;
        $rowWidth = 0;
        foreach ($sites as $site) {
            $box = self::siteBox($site, isset($collapsed[$site['key']]), $cap, $outsideOn, $outsideOf, $attachedOf, $attached);
            if ($rowWidth > 0 && $rowWidth + self::SITE_GAP + $box['w'] > $usable) {
                $rows[] = ['width' => $rowWidth, 'cards' => [], 'groups' => $rowIndices];
                $rowIndices = [];
                $rowTop += $rowHeight + self::SITE_GAP;
                $rowWidth = 0;
                $rowHeight = 0;
            }
            $x = $rowWidth === 0 ? 0 : $rowWidth + self::SITE_GAP;
            $box['x'] = $x + self::MARGIN;
            $box['y'] = $rowTop;
            $box['header']['x'] = $box['x'];
            $box['header']['y'] = $box['y'];
            $rowIndices[] = $offset + count($groups);
            $groups[] = $box;
            $rowWidth = $x + $box['w'];
            $rowHeight = max($rowHeight, $box['h']);
        }
        if ($rowIndices !== []) {
            $rows[] = ['width' => $rowWidth, 'cards' => [], 'groups' => $rowIndices];
        }

        return [$rowTop + $rowHeight, $groups, $rows];
    }

    /**
     * @param  array<string, array{key: string, label: string|null, members: list<string>}>  $sites
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs
     * @return list<array{key: string, label: string|null, members: list<string>}>
     */
    private static function orderSites(array $sites, array $esiPairs): array
    {
        $partner = [];
        foreach ($esiPairs as $p) {
            $partner[$p['a']][$p['b']] = true;
            $partner[$p['b']][$p['a']] = true;
        }
        foreach ($sites as &$site) {
            $site['members'] = self::orderMembers($site['members'], $partner);
        }
        unset($site);
        uasort($sites, function ($a, $b) {
            if (($a['label'] === null) !== ($b['label'] === null)) {
                return $a['label'] === null ? 1 : -1;
            }

            return FabricGraph::compare($a['members'][0], $b['members'][0]);
        });

        return array_values($sites);
    }

    /**
     * ESI partners next to each other, blocks ordered by their lowest member. The wrap can
     * still separate a pair onto two rows; that pair then gets a segment, not a bracket.
     *
     * @param  list<string>  $members
     * @param  array<string, array<string, true>>  $partner
     * @return list<string>
     */
    private static function orderMembers(array $members, array $partner): array
    {
        $inSite = array_fill_keys($members, true);
        $seen = [];
        $blocks = [];
        $sorted = $members;
        usort($sorted, FabricGraph::compare(...));
        foreach ($sorted as $ip) {
            if (isset($seen[$ip])) {
                continue;
            }
            $block = [];
            $queue = [$ip];
            while ($queue !== []) {
                $current = array_shift($queue);
                if (isset($seen[$current])) {
                    continue;
                }
                $seen[$current] = true;
                $block[] = $current;
                foreach (array_keys($partner[$current] ?? []) as $next) {
                    if (isset($inSite[$next]) && ! isset($seen[$next])) {
                        $queue[] = $next;
                    }
                }
            }
            usort($block, FabricGraph::compare(...));
            $blocks[] = $block;
        }
        usort($blocks, fn ($a, $b) => FabricGraph::compare($a[0], $b[0]));

        return array_merge(...$blocks);
    }

    /**
     * One centred row of cards for the spine or gateway tier.
     *
     * @param  list<string>  $row  member addresses, or far:{address} for an unmonitored far end
     * @param  array<string, array<string, mixed>>  $byIp
     * @param  array<string, string>  $tier
     * @param  array<string, array<string, mixed>>  $placed
     * @param  array<string, true>  $hlNodes
     * @return array{0: int, 1: list<array{width: int, cards: list<string>, groups: list<int>}>} the y below the tier, and its rows
     */
    private static function placeTier(array $row, array $byIp, array $tier, int $usable, int $y, array &$placed, array $hlNodes): array
    {
        $perRow = max(1, min(count($row), intdiv($usable + self::COL_GAP, self::CARD_W + self::COL_GAP)));
        $rows = [];
        foreach (array_chunk($row, $perRow) as $chunk) {
            $x = self::MARGIN;
            foreach ($chunk as $id) {
                $placed[$id] = str_starts_with($id, 'far:')
                    ? self::farNode($id, $x, $y, $hlNodes)
                    : self::memberNode($byIp[$id], $tier[$id] ?? FabricShape::TIER_LEAF, $x, $y, $hlNodes);
                $x += self::CARD_W + self::COL_GAP;
            }
            $rows[] = [
                'width' => count($chunk) * self::CARD_W + (count($chunk) - 1) * self::COL_GAP,
                'cards' => array_values($chunk),
                'groups' => [],
            ];
            $y += self::CARD_H + self::ROW_GAP;
        }

        return [$y - self::ROW_GAP, $rows];
    }

    /**
     * @param  array{key: string, label: string|null, members: list<string>}  $site
     * @param  array<string, list<array<string, mixed>>>  $outsideOf
     * @param  array<string, list<array<string, mixed>>>  $attachedOf
     * @param  list<array<string, mixed>>|null  $attached
     * @return array<string, mixed>
     */
    private static function siteBox(array $site, bool $collapsed, int $cap, bool $outsideOn, array $outsideOf, array $attachedOf, ?array $attached): array
    {
        $count = count($site['members']);
        $cols = $collapsed ? 1 : max(1, min($cap, $count));
        $rows = $collapsed ? 1 : (int) ceil($count / $cols);
        $innerW = $cols * self::CARD_W + ($cols - 1) * self::COL_GAP;
        $innerH = $rows * self::CARD_H + ($rows - 1) * self::ROW_GAP;

        $hasOutside = false;
        $hasAttached = false;
        if (! $collapsed) {
            foreach ($site['members'] as $ip) {
                $hasOutside = $hasOutside || ($outsideOn && ($outsideOf[$ip] ?? []) !== []);
                $hasAttached = $hasAttached || ($attached !== null && ($attachedOf[$ip] ?? []) !== []);
            }
        }
        $extra = ($hasOutside ? self::OUTSIDE_BAND : 0) + ($hasAttached ? self::ATTACHED_BAND : 0);

        return [
            'key' => $site['key'],
            'label' => $site['label'],
            'members' => $site['members'],
            'collapsed' => $collapsed,
            'cols' => $cols,
            'x' => 0,
            'y' => 0,
            'w' => $innerW + 2 * self::SITE_PAD,
            'h' => self::SITE_HEADER + $innerH + 2 * self::SITE_PAD + $extra,
            'header' => ['x' => 0, 'y' => 0, 'w' => $innerW + 2 * self::SITE_PAD, 'h' => self::SITE_HEADER],
            'summary' => null,
            'state' => 'ok',
            'outside' => 0,
            'attached' => null,
            'degraded' => 0,
            'highlight' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, array<string, mixed>>  $byIp
     * @param  array<string, string>  $tier
     * @param  array<string, list<array<string, mixed>>>  $outsideOf
     * @param  array<string, list<array<string, mixed>>>  $attachedOf
     * @param  list<array<string, mixed>>|null  $attached
     * @param  array<string, array<string, mixed>>  $placed
     * @param  array<string, true>  $hlNodes
     *
     * @param-out array<string, array<string, mixed>> $placed
     */
    private static function placeSiteContents(array &$group, array $byIp, array $tier, bool $outsideOn, array $outsideOf, array $attachedOf, ?array $attached, array &$placed, array $hlNodes): void
    {
        $group['header']['x'] = $group['x'];
        $group['header']['y'] = $group['y'];
        if ($group['collapsed']) {
            // no member card is emitted, so no edge can terminate on a coordinate that is gone
            return;
        }

        $top = $group['y'] + self::SITE_HEADER + self::SITE_PAD;
        $index = 0;
        /** @var list<string> $siteMembers */
        $siteMembers = $group['members'];
        foreach ($siteMembers as $ip) {
            $col = $index % $group['cols'];
            $row = intdiv($index, $group['cols']);
            $x = $group['x'] + self::SITE_PAD + $col * (self::CARD_W + self::COL_GAP);
            $y = $top + $row * (self::CARD_H + self::ROW_GAP);
            $placed[$ip] = self::memberNode($byIp[$ip], $tier[$ip] ?? FabricShape::TIER_LEAF, $x, $y, $hlNodes) + ['site' => $group['key'], 'row' => $row, 'col' => $col];
            $index++;
        }

        $bandY = $top + (int) ceil(count($siteMembers) / (int) $group['cols']) * (self::CARD_H + self::ROW_GAP);
        foreach ($siteMembers as $ip) {
            if ($outsideOn) {
                foreach (array_values($outsideOf[$ip] ?? []) as $i => $stub) {
                    $id = 'outside:' . $ip . ':' . $i;
                    $placed[$id] = [
                        'id' => $id, 'kind' => 'outside', 'ip' => (string) ($stub['b_label'] ?? ''), 'name' => (string) ($stub['b_label'] ?? 'unknown'),
                        'x' => $placed[$ip]['x'] + 16 * $i + 8, 'y' => $bandY + 8, 'w' => 6, 'h' => 6,
                        'role' => 'outside', 'tier' => FabricShape::TIER_LEAF, 'device_id' => null, 'border' => false,
                        'status' => null, 'collected' => false, 'version' => null, 'chips' => [], 'site' => $group['key'],
                        'fill' => '#f7f7f7', 'stroke' => '#8e6bbf', 'dashed' => true, 'highlight' => false,
                        'title' => sprintf('%s → %s: a session out of the fabric', (string) $stub['a'], (string) ($stub['b_label'] ?? 'unknown')),
                    ];
                }
            }
            if ($attached !== null) {
                $band = $bandY + ($outsideOn ? self::OUTSIDE_BAND : 0);
                foreach (array_values($attachedOf[$ip] ?? []) as $i => $record) {
                    if ($i >= self::ATTACHED_PER_ESI) {
                        $id = 'attached:' . $ip . ':more';
                        $placed[$id] = self::attachedNode($id, sprintf('+%d more', count($attachedOf[$ip]) - self::ATTACHED_PER_ESI), null, $placed[$ip]['x'] + 44 * self::ATTACHED_PER_ESI, $band + 10, $ip, $group['key']);
                        break;
                    }
                    $id = 'attached:' . (string) ($record['key'] ?? ($ip . ':' . $i));
                    $placed[$id] = self::attachedNode($id, (string) ($record['label'] ?? '?'), $record['device_id'] ?? null, $placed[$ip]['x'] + 44 * $i, $band + 10, $ip, $group['key']);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $hlNodes
     * @return array<string, mixed>
     */
    private static function memberNode(array $node, string $tier, int $x, int $y, array $hlNodes): array
    {
        $ip = (string) $node['ip'];
        $monitored = ($node['device_id'] ?? null) !== null;
        $collected = (bool) ($node['collected'] ?? false);
        $down = ($node['status'] ?? null) === false;

        // stroke priority: not monitored, then down, then collected-but-empty (plan §10.4 is
        // the record of why those three are different states and not one)
        $stroke = match (true) {
            ! $monitored => '#999999',
            $down => '#d9534f',
            ! $collected => '#f0ad4e',
            default => '#5a5a5a',
        };

        $chips = [];
        if ((int) ($node['esi_degraded'] ?? 0) > 0) {
            $chips[] = ['text' => sprintf('%d ESI', (int) $node['esi_degraded']), 'class' => 'danger'];
        }
        if (! $collected && $monitored) {
            $chips[] = ['text' => 'no EVPN data', 'class' => 'warning'];
        }
        if (($node['version_skew'] ?? false) && ($node['version'] ?? null) !== null) {
            $chips[] = ['text' => \Illuminate\Support\Str::limit((string) $node['version'], 14, '…'), 'class' => 'muted'];
        }
        if ($tier === FabricShape::TIER_LEAF && (int) ($node['irbs'] ?? 0) > 0) {
            $chips[] = ['text' => 'IRB', 'class' => 'info'];
        }

        return [
            'id' => $ip,
            'kind' => 'member',
            'ip' => $ip,
            'name' => (string) ($node['name'] ?? $ip),
            'role' => (string) ($node['role'] ?? FabricGraph::ROLE_UNKNOWN),
            'tier' => $tier,
            'device_id' => $node['device_id'] ?? null,
            'border' => (bool) ($node['border'] ?? false),
            'status' => $node['status'] ?? null,
            'collected' => $collected,
            'version' => $node['version'] ?? null,
            'site' => null,
            'x' => $x,
            'y' => $y,
            'w' => self::CARD_W,
            'h' => self::CARD_H,
            'fill' => match ($node['role'] ?? '') {
                FabricGraph::ROLE_GATEWAY => '#dbe9f6',
                FabricGraph::ROLE_SPINE => '#e3f1fa',
                FabricGraph::ROLE_LEAF => '#e6f4e6',
                default => '#f2f2f2',
            },
            'stroke' => $stroke,
            'dashed' => ! $monitored,
            'chips' => array_slice($chips, 0, 2),
            'chips_all' => $chips,
            'highlight' => isset($hlNodes[$ip]),
            'title' => self::memberTitle($node, $tier, $chips),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array{text: string, class: string}>  $chips
     */
    private static function memberTitle(array $node, string $tier, array $chips): string
    {
        $parts = [sprintf('%s (%s) — %s', (string) ($node['name'] ?? ''), (string) $node['ip'], (string) ($node['role'] ?? ''))];
        if ($tier !== ($node['role'] ?? '')) {
            $parts[] = 'drawn on the ' . $tier . ' tier';
        }
        if ($node['border'] ?? false) {
            $parts[] = 'border';
        }
        if (($node['device_id'] ?? null) === null) {
            $parts[] = 'not monitored';
        } elseif (($node['status'] ?? null) === false) {
            $parts[] = 'device down';
        } elseif (! ($node['collected'] ?? false)) {
            $parts[] = 'no EVPN data collected';
        }
        if (($node['version'] ?? null) !== null) {
            $parts[] = 'version ' . (string) $node['version'];
        }
        foreach ($chips as $chip) {
            $parts[] = $chip['text'];
        }

        return implode(' · ', array_unique($parts));
    }

    /**
     * @param  array<string, true>  $hlNodes
     * @return array<string, mixed>
     */
    private static function farNode(string $id, int $x, int $y, array $hlNodes): array
    {
        $address = substr($id, 4);

        return [
            'id' => $id, 'kind' => 'far', 'ip' => $address, 'name' => $address, 'role' => 'unknown',
            'tier' => FabricShape::TIER_SPINE, 'device_id' => null, 'border' => false, 'status' => null,
            'collected' => false, 'version' => null, 'site' => null,
            'x' => $x, 'y' => $y, 'w' => self::CARD_W, 'h' => self::CARD_H,
            'fill' => '#f7f7f7', 'stroke' => '#999999', 'dashed' => true,
            'chips' => [['text' => 'unmonitored', 'class' => 'muted']], 'chips_all' => [],
            'highlight' => isset($hlNodes[$id]),
            'title' => $address . ' — several members peer with this address and it is not a monitored device',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attachedNode(string $id, string $label, ?int $deviceId, int $x, int $y, string $anchor, string $site): array
    {
        return [
            'id' => $id, 'kind' => 'attached', 'ip' => '', 'name' => $label, 'role' => 'attached',
            'tier' => FabricShape::TIER_LEAF, 'device_id' => $deviceId, 'border' => false, 'status' => null,
            'collected' => false, 'version' => null, 'site' => $site, 'anchor' => $anchor,
            'x' => $x, 'y' => $y, 'w' => 40, 'h' => 16,
            'fill' => '#ffffff', 'stroke' => '#999999', 'dashed' => false,
            'chips' => [], 'chips_all' => [], 'highlight' => false,
            'title' => $label . ' — attached to this ESI-LAG',
        ];
    }

    /**
     * Where an edge that touches a member actually lands: its own card, or the header of the
     * site it was collapsed into.
     *
     * @param  array<string, array<string, mixed>>  $placed
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, string>  $siteOf
     * @param  array<string, true>  $collapsed
     * @return array<string, array{x: int, y: int, bottom: int, id: string, collapsed: bool}>
     */
    private static function anchors(array $placed, array $groups, array $siteOf, array $collapsed): array
    {
        $headers = [];
        foreach ($groups as $g) {
            $headers[$g['key']] = $g;
        }
        $out = [];
        foreach ($siteOf as $ip => $key) {
            if (isset($collapsed[$key]) && isset($headers[$key])) {
                $h = $headers[$key]['header'];
                $out[$ip] = ['x' => (int) ($h['x'] + $h['w'] / 2), 'y' => (int) $h['y'], 'bottom' => (int) ($h['y'] + $headers[$key]['h']), 'id' => $key, 'collapsed' => true];
            }
        }
        foreach ($placed as $id => $n) {
            if ($n['kind'] === 'member' || $n['kind'] === 'far') {
                $out[$id] = ['x' => (int) ($n['x'] + $n['w'] / 2), 'y' => (int) $n['y'], 'bottom' => (int) ($n['y'] + $n['h']), 'id' => (string) $id, 'collapsed' => false];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $underlay
     * @param  list<array{kind: string, a: string, b: string, id: string}>  $overlayEdges
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs
     * @param  array<string, array<string, mixed>>  $placed
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, array{x: int, y: int, bottom: int, id: string, collapsed: bool}>  $anchorOf
     * @param  array<string, string>  $siteOf
     * @param  array<string, true>  $collapsed
     * @param  array<string, true>  $sharedSet
     * @param  array<string, true>  $hlEdges
     * @param  array<string, string>  $tier
     * @return list<array<string, mixed>>
     */
    private static function edges(array $underlay, array $overlayEdges, array $esiPairs, array $placed, array $groups, array $anchorOf, array $siteOf, array $collapsed, array $sharedSet, bool $outsideOn, array $hlEdges, array $tier): array
    {
        $edges = [];
        $trunked = self::trunks($underlay, $groups, $siteOf, $tier, $anchorOf, $hlEdges, $edges);

        foreach ($underlay as $e) {
            $a = (string) $e['a'];
            $key = (string) ($e['link_key'] ?? '');
            if (isset($trunked[$key])) {
                continue;
            }
            if ($e['b'] === null) {
                $far = (string) ($e['b_label'] ?? '');
                if (isset($sharedSet[$far]) && isset($anchorOf['far:' . $far], $anchorOf[$a])) {
                    $edges[] = self::lineEdge('underlay', 'edge:underlay:' . $key, $anchorOf[$a], $anchorOf['far:' . $far], $e, $hlEdges);
                } elseif ($outsideOn && isset($anchorOf[$a])) {
                    $edges[] = self::lineEdge('wan', 'edge:underlay:' . $key, $anchorOf[$a], ['x' => $anchorOf[$a]['x'], 'y' => $anchorOf[$a]['bottom'] + 20, 'bottom' => $anchorOf[$a]['bottom'] + 20, 'id' => 'outside', 'collapsed' => false], $e, $hlEdges);
                }

                continue;
            }
            $b = (string) $e['b'];
            if (! isset($anchorOf[$a], $anchorOf[$b])) {
                continue;
            }
            // both ends inside the same collapsed site: nothing to draw, the summary carries it
            if ($anchorOf[$a]['id'] === $anchorOf[$b]['id']) {
                continue;
            }
            $kind = match (true) {
                (bool) ($e['wan'] ?? false) => 'wan',
                ($siteOf[$a] ?? null) !== ($siteOf[$b] ?? null) => 'cross-site',
                default => 'underlay',
            };
            $edges[] = self::lineEdge($kind, 'edge:underlay:' . $key, $anchorOf[$a], $anchorOf[$b], $e, $hlEdges);
        }

        foreach ($overlayEdges as $o) {
            if (! isset($anchorOf[$o['a']], $anchorOf[$o['b']]) || $anchorOf[$o['a']]['id'] === $anchorOf[$o['b']]['id']) {
                continue;
            }
            $style = self::edgeStyle($o['kind'], null);
            $id = 'edge:' . ($o['kind'] === 'missing' ? 'missing' : ($o['kind'] === 'asymmetric' ? 'overlay' : 'overlay')) . ':' . $o['id'];
            $edges[] = [
                'kind' => $o['kind'] === 'symmetric' ? 'overlay' : $o['kind'],
                'layer' => 'overlay',
                'id' => $id,
                'a' => $o['a'],
                'b' => $o['b'],
                'path' => self::arc($anchorOf[$o['a']], $anchorOf[$o['b']]),
                'label' => $o['kind'] === 'symmetric' ? '' : $o['kind'],
                'lx' => (int) (($anchorOf[$o['a']]['x'] + $anchorOf[$o['b']]['x']) / 2),
                'ly' => (int) (min($anchorOf[$o['a']]['y'], $anchorOf[$o['b']]['y']) - 8),
                'title' => match ($o['kind']) {
                    'missing' => 'a session every other member has and this one lacks',
                    'asymmetric' => 'EVPN neighbours listed by one side only',
                    default => 'EVPN neighbours',
                },
                'highlight' => isset($hlEdges[$id]),
            ] + $style;
        }

        foreach ($esiPairs as $p) {
            if (! isset($anchorOf[$p['a']], $anchorOf[$p['b']]) || $anchorOf[$p['a']]['id'] === $anchorOf[$p['b']]['id']) {
                continue;
            }
            $pa = $anchorOf[$p['a']];
            $pb = $anchorOf[$p['b']];
            $na = $placed[$p['a']] ?? null;
            $nb = $placed[$p['b']] ?? null;
            $bracket = ! $pa['collapsed'] && ! $pb['collapsed'] && $na !== null && $nb !== null
                && ($na['row'] ?? -1) === ($nb['row'] ?? -2) && abs((int) ($na['col'] ?? 0) - (int) ($nb['col'] ?? 0)) === 1;
            $style = self::edgeStyle('esi', null);
            $id = 'edge:esi:' . $p['id'];
            $y = max($pa['bottom'], $pb['bottom']) + 12;
            $edges[] = [
                'kind' => 'esi',
                'layer' => 'esi',
                'shape' => $bracket ? 'bracket' : 'segment',
                'id' => $id,
                'a' => $p['a'],
                'b' => $p['b'],
                'esis' => $p['esis'],
                'degraded' => $p['degraded'],
                'path' => $bracket
                    ? sprintf('M%d %d L%d %d L%d %d L%d %d', $pa['x'], $pa['bottom'], $pa['x'], $y, $pb['x'], $y, $pb['x'], $pb['bottom'])
                    : sprintf('M%d %d L%d %d', $pa['x'], $pa['bottom'], $pb['x'], $pb['bottom']),
                'label' => sprintf('%d ESI%s', $p['esis'], $p['esis'] === 1 ? '' : 's') . ($p['degraded'] > 0 ? sprintf(', %d degraded', $p['degraded']) : ''),
                'lx' => (int) (($pa['x'] + $pb['x']) / 2),
                'ly' => $y - 3,
                'title' => sprintf('%d shared ESI-LAG segment%s', $p['esis'], $p['esis'] === 1 ? '' : 's'),
                'highlight' => isset($hlEdges[$id]),
            ] + $style;
        }

        foreach ($placed as $id => $n) {
            if ($n['kind'] !== 'attached' || ! isset($anchorOf[(string) ($n['anchor'] ?? '')])) {
                continue;
            }
            $anchor = $anchorOf[(string) $n['anchor']];
            $edges[] = [
                'kind' => 'attached', 'layer' => 'attached', 'id' => 'edge:attached:' . $id,
                'a' => (string) $n['anchor'], 'b' => (string) $id,
                'path' => sprintf('M%d %d L%d %d', $anchor['x'], $anchor['bottom'], (int) ($n['x'] + $n['w'] / 2), (int) $n['y']),
                'label' => '', 'lx' => 0, 'ly' => 0, 'title' => (string) $n['title'], 'highlight' => false,
            ] + self::edgeStyle('attached', null);
        }

        return $edges;
    }

    /**
     * One line from a spine-tier card to a site header, but only for the uniform case: every
     * leaf of the site has an **up** session of the **same** routing-protocol set. Four up
     * sessions and one down session are five lines and no trunk, and so are four `bgp`
     * sessions beside one `bgp,ospf` — the odd session has to stay visible.
     *
     * @param  list<array<string, mixed>>  $underlay
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, string>  $siteOf
     * @param  array<string, string>  $tier
     * @param  array<string, array{x: int, y: int, bottom: int, id: string, collapsed: bool}>  $anchorOf
     * @param  array<string, true>  $hlEdges
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, true> link_keys the trunk swallowed
     */
    private static function trunks(array $underlay, array $groups, array $siteOf, array $tier, array $anchorOf, array $hlEdges, array &$edges): array
    {
        $bySpineSite = [];
        foreach ($underlay as $e) {
            if ($e['b'] === null) {
                continue;
            }
            $a = (string) $e['a'];
            $b = (string) $e['b'];
            foreach ([[$a, $b], [$b, $a]] as [$spine, $leaf]) {
                if (($tier[$spine] ?? '') === FabricShape::TIER_SPINE && isset($siteOf[$leaf])) {
                    $bySpineSite[$spine][$siteOf[$leaf]][$leaf][] = $e;
                }
            }
        }

        $members = [];
        foreach ($groups as $g) {
            $members[$g['key']] = $g;
        }

        $taken = [];
        foreach ($bySpineSite as $spine => $sites) {
            foreach ($sites as $siteKey => $byLeaf) {
                $group = $members[$siteKey] ?? null;
                if ($group === null || count($group['members']) < 2 || count($byLeaf) !== count($group['members'])) {
                    continue;
                }
                $sets = [];
                $allUp = true;
                $sessions = [];
                foreach ($byLeaf as $rows) {
                    foreach ($rows as $row) {
                        $allUp = $allUp && ($row['up'] ?? null) === true;
                        $sets[self::protocolSet((string) $row['protocol'])] = true;
                        $sessions[] = $row;
                    }
                }
                if (! $allUp || count($sets) !== 1) {
                    continue;
                }
                $id = 'edge:trunk:' . $spine . '|' . $siteKey;
                $edges[] = [
                    'kind' => 'trunk', 'layer' => 'underlay', 'id' => $id,
                    'a' => $spine, 'b' => $siteKey,
                    'path' => sprintf('M%d %d L%d %d', $anchorOf[$spine]['x'], $anchorOf[$spine]['bottom'], (int) ($group['header']['x'] + $group['header']['w'] / 2), (int) $group['header']['y']),
                    'label' => sprintf('%d up', count($sessions)),
                    'lx' => (int) (($anchorOf[$spine]['x'] + $group['header']['x'] + $group['header']['w'] / 2) / 2),
                    'ly' => (int) (($anchorOf[$spine]['bottom'] + $group['header']['y']) / 2),
                    'title' => sprintf('%d up %s sessions from %s to every member of this site', count($sessions), array_key_first($sets), $spine),
                    'highlight' => isset($hlEdges[$id]),
                ] + self::edgeStyle('trunk', true);
                foreach ($sessions as $row) {
                    $taken[(string) ($row['link_key'] ?? '')] = true;
                }
            }
        }

        return $taken;
    }

    /**
     * @param  array{x: int, y: int, bottom: int, id: string, collapsed: bool}  $pa
     * @param  array{x: int, y: int, bottom: int, id: string, collapsed: bool}  $pb
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $hlEdges
     * @return array<string, mixed>
     */
    private static function lineEdge(string $kind, string $id, array $pa, array $pb, array $row, array $hlEdges): array
    {
        $up = $row['up'] ?? null;
        $sameRow = $pa['y'] === $pb['y'];
        $path = $sameRow
            ? self::arcUnder($pa, $pb)
            : sprintf('M%d %d L%d %d', $pa['x'], $pa['y'] < $pb['y'] ? $pa['bottom'] : $pa['y'], $pb['x'], $pa['y'] < $pb['y'] ? $pb['y'] : $pb['bottom']);

        return [
            'kind' => $kind,
            'layer' => 'underlay',
            'id' => $id,
            'a' => (string) $row['a'],
            'b' => $row['b'] === null ? (string) ($row['b_label'] ?? '') : (string) $row['b'],
            'protocol' => (string) $row['protocol'],
            'state' => $row['state'] ?? null,
            'up' => $up,
            'lldp' => (bool) ($row['lldp'] ?? false),
            'a_port' => $row['a_port'] ?? null,
            'b_port' => $row['b_port'] ?? null,
            'path' => $path,
            'label' => trim((string) $row['protocol'] . ' ' . (string) ($row['state'] ?? '')),
            'lx' => (int) (($pa['x'] + $pb['x']) / 2),
            'ly' => (int) ($sameRow ? min($pa['y'], $pb['y']) - Topology::arcLift(abs($pa['x'] - $pb['x']), true) / 2 : ($pa['y'] + $pb['y']) / 2),
            'title' => sprintf(
                '%s %s ↔ %s %s: %s %s%s%s',
                (string) $row['a'], (string) ($row['a_port'] ?? ''),
                $row['b'] === null ? (string) ($row['b_label'] ?? 'unknown') : (string) $row['b'], (string) ($row['b_port'] ?? ''),
                (string) $row['protocol'], (string) ($row['state'] ?? ''),
                ($row['network'] ?? null) !== null ? ', ' . (string) $row['network'] : '',
                ($row['lldp'] ?? false) ? ', LLDP confirmed' : '',
            ),
            'highlight' => isset($hlEdges[$id]),
        ] + self::edgeStyle($kind, $up);
    }

    /**
     * @param  array{x: int, y: int, bottom: int, id: string, collapsed: bool}  $pa
     * @param  array{x: int, y: int, bottom: int, id: string, collapsed: bool}  $pb
     */
    private static function arcUnder(array $pa, array $pb): string
    {
        $lift = Topology::arcLift(abs($pa['x'] - $pb['x']), true);

        return sprintf('M%d %d Q%d %d %d %d', $pa['x'], $pa['y'], (int) (($pa['x'] + $pb['x']) / 2), $pa['y'] - $lift, $pb['x'], $pb['y']);
    }

    /**
     * @param  array{x: int, y: int, bottom: int, id: string, collapsed: bool}  $pa
     * @param  array{x: int, y: int, bottom: int, id: string, collapsed: bool}  $pb
     */
    private static function arc(array $pa, array $pb): string
    {
        if ($pa['y'] === $pb['y']) {
            $lift = Topology::arcLift(abs($pa['x'] - $pb['x']));

            return sprintf('M%d %d Q%d %d %d %d', $pa['x'], $pa['y'], (int) (($pa['x'] + $pb['x']) / 2), $pa['y'] - $lift, $pb['x'], $pb['y']);
        }

        return sprintf('M%d %d L%d %d', $pa['x'], $pa['y'] < $pb['y'] ? $pa['bottom'] : $pa['y'], $pb['x'], $pa['y'] < $pb['y'] ? $pb['y'] : $pb['bottom']);
    }

    /**
     * A collapsed site takes the worst state of the members and of the edges that were not
     * emitted, so a down session cannot hide inside a green card.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, array<string, mixed>>  $placed
     * @param  array<string, array<string, mixed>>  $byIp
     * @param  list<array<string, mixed>>  $underlay
     * @param  array<string, string>  $siteOf
     * @param  array<string, true>  $collapsed
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs
     * @param  array<string, list<array<string, mixed>>>  $outsideOf
     * @param  array<string, list<array<string, mixed>>>  $attachedOf
     * @param  list<array<string, mixed>>|null  $attached
     * @param  array<string, true>  $hlNodes
     *
     * @param-out array<string, array<string, mixed>> $placed
     */
    private static function summarise(array &$groups, array &$placed, array $byIp, array $underlay, array $siteOf, array $collapsed, array $esiPairs, array $outsideOf, array $attachedOf, ?array $attached, bool $outsideOn, array $hlNodes): void
    {
        foreach ($groups as &$group) {
            $outside = 0;
            $attachedCount = 0;
            $degraded = 0;
            $state = 'ok';
            /** @var list<string> $siteMembers */
            $siteMembers = $group['members'];
            foreach ($siteMembers as $ip) {
                $node = $byIp[$ip] ?? [];
                $outside += count($outsideOf[$ip] ?? []);
                $attachedCount += count($attachedOf[$ip] ?? []);
                $degraded += (int) ($node['esi_degraded'] ?? 0);
                if (($node['device_id'] ?? null) === null || ($node['status'] ?? null) === false) {
                    $state = 'down';
                } elseif ($state === 'ok' && ! ($node['collected'] ?? false)) {
                    $state = 'warning';
                }
            }
            if ($group['collapsed']) {
                foreach ($underlay as $e) {
                    $a = (string) $e['a'];
                    $b = $e['b'] === null ? null : (string) $e['b'];
                    if ($b !== null && ($siteOf[$a] ?? null) === $group['key'] && ($siteOf[$b] ?? null) === $group['key'] && ($e['up'] ?? null) === false) {
                        $state = 'down';
                    }
                }
            }
            $group['state'] = $state;
            $group['outside'] = $outside;
            $group['degraded'] = $degraded;
            // null means the two neighbour queries did not run; 0 would claim we looked
            $group['attached'] = $attached === null ? null : $attachedCount;
            $group['highlight'] = false;
            foreach ($siteMembers as $ip) {
                $group['highlight'] = $group['highlight'] || isset($hlNodes[$ip]);
            }

            if (! $group['collapsed']) {
                continue;
            }
            $id = (string) $group['key'];
            $labels = array_values(array_filter([
                sprintf('%d member%s', count($siteMembers), count($siteMembers) === 1 ? '' : 's'),
                $degraded > 0 ? sprintf('%d degraded ESI', $degraded) : null,
                $outside > 0 ? sprintf('%d outside', $outside) : null,
                $attached === null ? null : sprintf('%d attached', $attachedCount),
            ]));
            $placed[$id] = [
                'id' => $id, 'kind' => 'summary', 'ip' => '', 'name' => $group['label'] ?? 'no location',
                'role' => 'site', 'tier' => FabricShape::TIER_LEAF, 'device_id' => null, 'border' => false,
                'status' => $state === 'down' ? false : null, 'collected' => true, 'version' => null, 'site' => $group['key'],
                'x' => $group['x'] + self::SITE_PAD, 'y' => $group['y'] + self::SITE_HEADER + self::SITE_PAD,
                'w' => self::CARD_W, 'h' => self::CARD_H,
                'fill' => '#f2f2f2',
                'stroke' => match ($state) { 'down' => '#d9534f', 'warning' => '#f0ad4e', default => '#5a5a5a' },
                'dashed' => false,
                'chips' => [], 'chips_all' => [],
                'summary' => $labels,
                'highlight' => $group['highlight'],
                'title' => implode(' · ', [$group['label'] ?? 'no location', ...$labels]),
            ];
            $group['summary'] = $labels;
        }
        unset($group);
    }

    /**
     * @param  array<string, mixed>  $shape
     * @param  array<string, list<array<string, mixed>>>  $outsideOf
     * @param  list<array<string, mixed>>|null  $attached
     * @return list<string>
     */
    private static function sentences(array $shape, array $outsideOf, bool $outsideOn, ?array $attached): array
    {
        $sentences = [(string) ($shape['evidence'] ?? '')];
        $outside = array_sum(array_map('count', $outsideOf));
        if ($outside > 0) {
            $sentences[] = sprintf(
                '%d session%s out of the fabric (transit or IX peers of a border router)%s.',
                $outside, $outside === 1 ? '' : 's', $outsideOn ? '' : ', not drawn',
            );
        }
        if ($attached !== null) {
            $sentences[] = sprintf('%d attached device%s from LLDP on the ESI-LAGs.', count($attached), count($attached) === 1 ? '' : 's');
        }

        return array_values(array_filter($sentences, fn ($s) => $s !== ''));
    }
}
