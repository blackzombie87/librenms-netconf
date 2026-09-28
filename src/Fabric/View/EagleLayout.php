<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Eagle view of a fabric: the same role-coloured cards the static SVG got right, but wrapped
 * into site compounds and stacked in tiers instead of stretched into one row (plan §11 E2).
 * Fourteen members in one row are 1,968 px and 26 are 3,780; the browser then scales that down
 * until the labels are unreadable, and the row cannot grow. Wrapping is what makes 26 members an eagle view; the viewport is a viewBox, not a
 * layout engine.
 *
 * Pure, so the geometry is unit-testable without a browser: `place()` is the whole picture and
 * the only overview picture there is.
 *
 * Two things the design document did not have and the review added (plan §11 E-F5, E-F6):
 * every edge carries a dash pattern as well as a class, so state survives a greyscale print
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

    /**
     * Between two compounds, on both axes. Wide enough to be a routing channel: `SITE_GAP` was
     * 16 and nothing could pass through it, so a cross-site line went over the boxes instead.
     */
    public const GUTTER = 28;

    /** A lane sits at least this far outside a foreign rect, and a gap is inset by it first. */
    public const CLEARANCE = 4;

    /** Between the centre-lines of two parallel lanes in one gap. */
    public const LANE_PITCH = 6;

    /** How far the fallback ring is inset from the routing bounds. */
    public const OUTER = 8;

    /** How far apart a routed segment is sampled when testing it against the obstacles. */
    public const SAMPLE_PX = 1;

    /** Penetration into an obstacle, in px, that a routed sample is still allowed. */
    public const INSIDE_TOL = 1;

    public const TIER_GAP = 96;

    /** Attached devices drawn under one ESI pair before the rest become a "+N" card. */
    public const ATTACHED_PER_ESI = 8;

    /** Vertical room a site box reserves for the outside stubs / attached dots it holds. */
    public const OUTSIDE_BAND = 28;

    /** … and for the ESI bracket drawn under a pair whose two ends are both inside it. */
    public const ESI_BAND = 22;

    /** Roughly one character of the 11 px label font, for fitting a caption to its box. */
    public const LABEL_CHAR_W = 5.9;

    /** … and of the 9 px edge-label font, for fitting an ESI label between two anchors. */
    public const EDGE_CHAR_W = 4.8;

    /**
     * How wide a one-member gateway compound may grow to hold its caption. Two of these plus a
     * gutter and the outer margins still fit TARGET_WIDTH, so the two production gateways stay
     * on one row instead of ellipsizing `IPB/CarrierColo Rechenzentrum Berlin - RZ BER2`.
     */
    public const GATEWAY_CAPTION_CAP = 480;

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
        foreach ([[$gatewaySites, true], [$leafSites, false]] as [$tierSites, $gatewayTier]) {
            if ($tierSites === []) {
                continue;
            }
            [$y, $tierGroups, $tierRows] = self::packTier($tierSites, $collapsed, $cap, $usable, $y, $outsideOn, $outsideOf, $attachedOf, $attached, count($groups), $esiPairs, $gatewayTier);
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
        $height = (int) max($bottom + self::MARGIN, self::MARGIN * 2 + self::CARD_H);
        $ctx = self::routeContext($groups, $placed, $width, $height);
        $edges = self::edges($underlay, $overlayEdges, $esiPairs, $placed, $groups, $anchorOf, $siteOf, $collapsed, $sharedSet, $outsideOn, $hlEdges, $tier, $ctx);
        self::summarise($groups, $placed, $byIp, $underlay, $siteOf, $collapsed, $esiPairs, $outsideOf, $attachedOf, $attached, $outsideOn, $hlNodes);

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
     * A compound's caption, cut to what its box can hold. A location is free text and the
     * ones that exist are long — `IPB/CarrierColo Rechenzentrum Berlin - RZ BER2` is 304 px
     * of 11 px type in a 176 px box — and an un-cut caption runs straight across the
     * neighbouring compound. The full string stays in the box's `<title>`.
     */
    public static function fitLabel(?string $label, int $members, int $boxWidth): string
    {
        $caption = $label ?? 'no location';
        $suffix = ' (' . $members . ')';
        $budget = (int) floor(($boxWidth - 2 * self::SITE_PAD - 10) / self::LABEL_CHAR_W) - mb_strlen($suffix);

        return ($budget >= 4 && mb_strlen($caption) > $budget
            ? mb_substr($caption, 0, $budget - 1) . '…'
            : $caption) . $suffix;
    }

    /**
     * Colour **and** dash per edge, so the state reads without colour (plan §11 E-F5). A down
     * session is dashed, an unknown one dotted, an up one solid; the overlay, fault, ESI and
     * attached layers each have their own pattern as well as their own colour.
     *
     * The colour is a class, not a hex: LibreNMS sets `class="dark"` on `<html>` and a
     * presentation attribute written here cannot follow it. The dash and the width are not
     * theme, so they stay attributes.
     *
     * @return array{strokeClass: string, dash: string, width: float, state: string}
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
            'overlay' => ['strokeClass' => 'eg-stroke-overlay', 'dash' => '3 3', 'width' => 1.0, 'state' => $state],
            'fault' => ['strokeClass' => 'eg-stroke-fault', 'dash' => '6 2 2 2', 'width' => 1.5, 'state' => $state],
            'esi' => ['strokeClass' => 'eg-stroke-esi', 'dash' => '', 'width' => 2.0, 'state' => $state],
            'attached' => ['strokeClass' => 'eg-stroke-attached', 'dash' => '2 3', 'width' => 1.0, 'state' => $state],
            'down' => ['strokeClass' => 'eg-stroke-down', 'dash' => '5 3', 'width' => 3.0, 'state' => $state],
            'unknown' => ['strokeClass' => 'eg-stroke-unknown', 'dash' => '1 4', 'width' => 2.0, 'state' => $state],
            default => [
                'strokeClass' => $kind === 'wan' ? 'eg-stroke-wan' : ($kind === 'cross-site' ? 'eg-stroke-cross' : 'eg-stroke-up'),
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
            // every member of this tier whose location is still null shares one compound: a
            // half-collected fabric was a field of thirteen dashed boxes, which is the same
            // fact repeated as geometry. A member that does have a location is not pulled in,
            // and one that inherits a location from an ESI partner joins that location above.
            $key = $prefix . ($label !== null ? 'site:' . $label : 'noloc');
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
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs
     * @return array{0: int, 1: list<array<string, mixed>>, 2: list<array{width: int, cards: list<string>, groups: list<int>}>}
     */
    private static function packTier(array $sites, array $collapsed, int $cap, int $usable, int $y, bool $outsideOn, array $outsideOf, array $attachedOf, ?array $attached, int $offset, array $esiPairs, bool $gatewayTier = false): array
    {
        $groups = [];
        $rows = [];
        $rowIndices = [];
        $rowTop = $y;
        $rowHeight = 0;
        $rowWidth = 0;
        foreach ($sites as $site) {
            $box = self::siteBox($site, isset($collapsed[$site['key']]), $cap, $outsideOn, $outsideOf, $attachedOf, $attached, $esiPairs, $gatewayTier);
            if ($rowWidth > 0 && $rowWidth + self::GUTTER + $box['w'] > $usable) {
                $rows[] = ['width' => $rowWidth, 'cards' => [], 'groups' => $rowIndices];
                $rowIndices = [];
                $rowTop += $rowHeight + self::GUTTER;
                $rowWidth = 0;
                $rowHeight = 0;
            }
            $x = $rowWidth === 0 ? 0 : $rowWidth + self::GUTTER;
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
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs
     * @return array<string, mixed>
     */
    private static function siteBox(array $site, bool $collapsed, int $cap, bool $outsideOn, array $outsideOf, array $attachedOf, ?array $attached, array $esiPairs = [], bool $gatewayTier = false): array
    {
        $count = count($site['members']);
        $cols = $collapsed ? 1 : max(1, min($cap, $count));
        $rows = $collapsed ? 1 : (int) ceil($count / $cols);
        $innerW = $cols * self::CARD_W + ($cols - 1) * self::COL_GAP;

        [$gaps, $bottomBand] = self::esiBands($site['members'], $collapsed ? 1 : $cols, $rows, $collapsed ? [] : $esiPairs);
        $innerH = $rows * self::CARD_H + array_sum($gaps);

        $hasOutside = false;
        $hasAttached = false;
        if (! $collapsed) {
            foreach ($site['members'] as $ip) {
                $hasOutside = $hasOutside || ($outsideOn && ($outsideOf[$ip] ?? []) !== []);
                $hasAttached = $hasAttached || ($attached !== null && ($attachedOf[$ip] ?? []) !== []);
            }
        }
        $hasBracket = $bottomBand > 0 || in_array(self::ESI_BAND, $gaps, true);
        $extra = $bottomBand + ($hasOutside ? self::OUTSIDE_BAND : 0) + ($hasAttached ? self::ATTACHED_BAND : 0);

        // A gateway alone in its own facility string is a 176 px box under a 304 px caption, and
        // the ellipsis then hides which datacentre it is. Let that one box grow to its caption,
        // capped so two of them still share a row. Leaf compounds stay on the card grid: growing
        // every singleton would fight the packing rule for no operator benefit.
        $boxW = $innerW + 2 * self::SITE_PAD;
        if ($gatewayTier && $count === 1 && ! $collapsed) {
            $captionPx = (int) ceil(mb_strlen(($site['label'] ?? 'no location') . ' (1)') * self::LABEL_CHAR_W) + 2 * self::SITE_PAD + 16;
            $boxW = max($boxW, min($captionPx, self::GATEWAY_CAPTION_CAP));
        }

        return [
            'key' => $site['key'],
            'label' => $site['label'],
            'caption' => self::fitLabel($site['label'], $count, $boxW),
            'members' => $site['members'],
            'collapsed' => $collapsed,
            'cols' => $cols,
            'x' => 0,
            'y' => 0,
            'w' => $boxW,
            'h' => self::SITE_HEADER + $innerH + 2 * self::SITE_PAD + $extra,
            'header' => ['x' => 0, 'y' => 0, 'w' => $boxW, 'h' => self::SITE_HEADER],
            'inner_w' => $innerW,
            'gaps' => $gaps,
            'band' => $bottomBand,
            'bracket' => $hasBracket,
            'summary' => null,
            'state' => 'ok',
            'outside' => 0,
            'attached' => null,
            'degraded' => 0,
            'highlight' => false,
        ];
    }

    /**
     * The vertical gaps inside one compound, so an ESI mark has a strip of its own instead of
     * landing on the next row of cards.
     *
     * A bracket is drawn 12 px under its row and a cross-row segment runs from the upper card's
     * bottom to the lower card's top, so the band a pair needs is **between** its two rows and
     * not only under the last one. `ESI_BAND` (22) leaves 10 px between the horizontal leg and
     * the next row; `ROW_GAP` (16) did not, which is why a four-member site's upper "2 ESIs"
     * sat on the cards below it.
     *
     * `edges()` must stay a subset of this: every bracket it draws is a pair this reserved for.
     *
     * @param  list<string>  $members
     * @param  list<array{a: string, b: string, esis: int, degraded: int, id: string}>  $esiPairs
     * @return array{0: list<int>, 1: int} the gap under each row but the last, and the bottom band
     */
    private static function esiBands(array $members, int $cols, int $rows, array $esiPairs): array
    {
        $rowOf = [];
        foreach (array_values($members) as $i => $ip) {
            $rowOf[$ip] = intdiv($i, max(1, $cols));
        }
        $band = array_fill(0, max(1, $rows), false);
        foreach ($esiPairs as $pair) {
            if (! isset($rowOf[$pair['a']], $rowOf[$pair['b']])) {
                continue;
            }
            $upper = min($rowOf[$pair['a']], $rowOf[$pair['b']]);
            $lower = max($rowOf[$pair['a']], $rowOf[$pair['b']]);
            for ($r = 0; $r < $rows; $r++) {
                // a bracket under the row both ends share, else a band under every row the pair
                // has to cross -- which is one band for the usual neighbouring rows
                $band[$r] = $band[$r] || ($upper === $lower ? $upper === $r : ($upper <= $r && $r < $lower));
            }
        }

        $gaps = [];
        for ($r = 0; $r < $rows - 1; $r++) {
            $gaps[] = $band[$r] ? self::ESI_BAND : self::ROW_GAP;
        }

        return [$gaps, $band[$rows - 1] ? self::ESI_BAND : 0];
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
        // the rows sit on the gaps siteBox() sized the box with, so a reserved ESI band is the
        // same strip in both passes; a grown gateway box centres its one card
        /** @var list<int> $gaps */
        $gaps = $group['gaps'];
        $rowTop = [];
        $y = $top;
        for ($r = 0; $r <= count($gaps); $r++) {
            $rowTop[$r] = $y;
            $y += self::CARD_H + ($gaps[$r] ?? 0);
        }
        $left = $group['x'] + (int) max(self::SITE_PAD, ($group['w'] - (int) $group['inner_w']) / 2);

        $index = 0;
        /** @var list<string> $siteMembers */
        $siteMembers = $group['members'];
        foreach ($siteMembers as $ip) {
            $col = $index % $group['cols'];
            $row = intdiv($index, $group['cols']);
            $x = $left + $col * (self::CARD_W + self::COL_GAP);
            $placed[$ip] = self::memberNode($byIp[$ip], $tier[$ip] ?? FabricShape::TIER_LEAF, $x, $rowTop[$row] ?? $top, $hlNodes) + ['site' => $group['key'], 'row' => $row, 'col' => $col];
            $index++;
        }

        $bandY = $rowTop[count($gaps)] + self::CARD_H + (int) $group['band'];
        foreach ($siteMembers as $ip) {
            if ($outsideOn) {
                foreach (array_values($outsideOf[$ip] ?? []) as $i => $stub) {
                    $id = 'outside:' . $ip . ':' . $i;
                    $placed[$id] = [
                        'id' => $id, 'kind' => 'outside', 'ip' => (string) ($stub['b_label'] ?? ''), 'name' => (string) ($stub['b_label'] ?? 'unknown'),
                        'x' => $placed[$ip]['x'] + 16 * $i + 8, 'y' => $bandY + 8, 'w' => 6, 'h' => 6,
                        'role' => 'outside', 'tier' => FabricShape::TIER_LEAF, 'device_id' => null, 'border' => false,
                        'status' => null, 'collected' => false, 'version' => null, 'chips' => [], 'site' => $group['key'],
                        'fillClass' => 'eg-outside', 'strokeClass' => 'eg-stroke-wan', 'dashed' => true, 'highlight' => false,
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
        // the record of why those three are different states and not one). A class, so the
        // dark theme can lighten all four without a second hex in here
        $strokeClass = match (true) {
            ! $monitored => 'eg-card-unmonitored',
            $down => 'eg-card-down',
            ! $collected => 'eg-card-stale',
            default => 'eg-card-ok',
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
            // the fill follows the **stored** role, not the tier, so the picture and the
            // Members tab agree: an ERB leaf stored as gateway keeps the gateway blue and
            // still sits in its site
            'fillClass' => match ($node['role'] ?? '') {
                FabricGraph::ROLE_GATEWAY => 'eg-gateway',
                FabricGraph::ROLE_SPINE => 'eg-spine',
                FabricGraph::ROLE_LEAF => 'eg-leaf',
                default => 'eg-unknown',
            },
            'strokeClass' => $strokeClass,
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
            'fillClass' => 'eg-far', 'strokeClass' => 'eg-card-unmonitored', 'dashed' => true,
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
            'fillClass' => 'eg-attached', 'strokeClass' => 'eg-card-unmonitored', 'dashed' => false,
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
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    private static function edges(array $underlay, array $overlayEdges, array $esiPairs, array $placed, array $groups, array $anchorOf, array $siteOf, array $collapsed, array $sharedSet, bool $outsideOn, array $hlEdges, array $tier, array &$ctx): array
    {
        $edges = [];
        $trunked = self::trunks($underlay, $groups, $siteOf, $tier, $anchorOf, $sharedSet, $hlEdges, $edges, $ctx);

        foreach ($underlay as $e) {
            $a = (string) $e['a'];
            $key = (string) ($e['link_key'] ?? '');
            if (isset($trunked[$key])) {
                continue;
            }
            if ($e['b'] === null) {
                $far = (string) ($e['b_label'] ?? '');
                if (isset($sharedSet[$far]) && isset($anchorOf['far:' . $far], $anchorOf[$a])) {
                    $edge = self::lineEdge('underlay', 'edge:underlay:' . $key, $anchorOf[$a], $anchorOf['far:' . $far], $e, $hlEdges, 'far:' . $far);
                    $edges[] = $edge + ['_route' => self::routeRequest($a, 'far:' . $far, $placed, $groups, $siteOf, $collapsed)];
                } elseif ($outsideOn && isset($anchorOf[$a])) {
                    // a drop under the card it hangs from, which is exempt: there is no far card
                    $edges[] = self::lineEdge('wan', 'edge:underlay:' . $key, $anchorOf[$a], ['x' => $anchorOf[$a]['x'], 'y' => $anchorOf[$a]['bottom'] + 20, 'bottom' => $anchorOf[$a]['bottom'] + 20, 'id' => 'outside', 'collapsed' => false], $e, $hlEdges, shape: 'stub');
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
            // cross-site is a relation between two leaf or gateway compounds. A spine has no
            // compound at all, so deciding from $siteOf alone painted every Clos spine-to-leaf
            // link blue and called a fabric's own underlay an inter-site link.
            $spineEnd = ($tier[$a] ?? '') === FabricShape::TIER_SPINE || ($tier[$b] ?? '') === FabricShape::TIER_SPINE;
            $kind = match (true) {
                (bool) ($e['wan'] ?? false) => 'wan',
                $spineEnd => 'underlay',
                ($siteOf[$a] ?? null) !== ($siteOf[$b] ?? null) => 'cross-site',
                default => 'underlay',
            };
            // inside one compound a short arc over the cards is the point of the drawing, and it
            // is exempt -- but only while the two cards really do sit side by side
            $sameSite = ($siteOf[$a] ?? null) !== null && ($siteOf[$a] ?? null) === ($siteOf[$b] ?? null);
            $overlap = self::overlapY($placed[$a] ?? null, $placed[$b] ?? null);
            if ($sameSite && $overlap >= 8) {
                $edges[] = self::lineEdge($kind, 'edge:underlay:' . $key, $anchorOf[$a], $anchorOf[$b], $e, $hlEdges, shape: 'arc');

                continue;
            }
            $edge = self::lineEdge($kind, 'edge:underlay:' . $key, $anchorOf[$a], $anchorOf[$b], $e, $hlEdges);
            $edges[] = $edge + ['_route' => self::routeRequest($a, $b, $placed, $groups, $siteOf, $collapsed)];
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
                // exempt from the gutter: a fault arc or a partial mesh is a mark that
                // something is wrong, and burying it in an OSPF channel would hide it
                'shape' => $anchorOf[$o['a']]['y'] === $anchorOf[$o['b']]['y'] ? 'arc' : 'line',
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
            // `row` and `col` restart in every compound, so "next to each other" only means that
            // once both cards are known to sit in the same one. Without the site test a pair from
            // one site's right-hand card to the next site's left-hand card compares row 0 = row 0
            // and cols 1 and 0, draws a bracket across the gap between the two boxes, and hangs it
            // below both — neither reserved a strip, because siteBox() asks for the same site.
            // This predicate has to stay a subset of the one that reserves ESI_BAND there.
            $sameSite = isset($siteOf[$p['a']], $siteOf[$p['b']]) && $siteOf[$p['a']] === $siteOf[$p['b']];
            $bracket = $sameSite && ! $pa['collapsed'] && ! $pb['collapsed'] && $na !== null && $nb !== null
                && ($na['row'] ?? -1) === ($nb['row'] ?? -2) && abs((int) ($na['col'] ?? 0) - (int) ($nb['col'] ?? 0)) === 1;
            $style = self::edgeStyle('esi', null);
            $id = 'edge:esi:' . $p['id'];
            $request = null;

            // the horizontal leg sits 12 px under the card it hangs from, inside the 22 px band
            // siteBox() reserved: 10 px still separate it from the row below
            $y = max($pa['bottom'], $pb['bottom']) + 12;
            if ($bracket) {
                $path = sprintf('M%d %d L%d %d L%d %d L%d %d', $pa['x'], $pa['bottom'], $pa['x'], $y, $pb['x'], $y, $pb['x'], $pb['bottom']);
                $labelY = $y - 3;
            } elseif ($sameSite && $na !== null && $nb !== null && abs((int) ($na['row'] ?? 0) - (int) ($nb['row'] ?? 0)) === 1) {
                // consecutive rows of one compound: down from the upper card, across the band
                // between the rows, and into the **top** of the lower card. The old straight
                // segment ran from card bottom to card bottom, straight through the lower card.
                [$upper, $lower] = ($na['y'] <= $nb['y']) ? [$pa, $pb] : [$pb, $pa];
                $y = $upper['bottom'] + 12;
                $path = sprintf('M%d %d L%d %d L%d %d L%d %d', $upper['x'], $upper['bottom'], $upper['x'], $y, $lower['x'], $y, $lower['x'], $lower['y']);
                $labelY = $y - 3;
            } else {
                // a cross-site pair takes the gutter, and a same-site pair more than one row
                // apart takes the pad route: no single band reaches the far card
                $path = sprintf('M%d %d L%d %d', $pa['x'], $pa['bottom'], $pb['x'], $pb['bottom']);
                $labelY = $y - 3;
                $request = self::routeRequest($p['a'], $p['b'], $placed, $groups, $siteOf, $collapsed);
            }

            // the label has to fit between the two anchors or it runs across the cards it names.
            // RLG1's "22 ESIs, 5 degraded" fits between its pair; a long one on a short span is
            // the bare count, and the whole string stays in the title
            $long = sprintf('%d ESI%s', $p['esis'], $p['esis'] === 1 ? '' : 's') . ($p['degraded'] > 0 ? sprintf(', %d degraded', $p['degraded']) : '');
            $fits = mb_strlen($long) * self::EDGE_CHAR_W <= abs($pa['x'] - $pb['x']) - 8;

            $edges[] = ($request === null ? [] : ['_route' => $request]) + [
                'kind' => 'esi',
                'layer' => 'esi',
                'shape' => $bracket ? 'bracket' : 'segment',
                'id' => $id,
                'a' => $p['a'],
                'b' => $p['b'],
                'esis' => $p['esis'],
                'degraded' => $p['degraded'],
                'path' => $path,
                'label' => $fits ? $long : (string) $p['esis'],
                'lx' => (int) (($pa['x'] + $pb['x']) / 2),
                'ly' => $labelY,
                'title' => sprintf('%d shared ESI-LAG segment%s', $p['esis'], $p['esis'] === 1 ? '' : 's')
                    . ($p['degraded'] > 0 ? sprintf(', %d degraded', $p['degraded']) : ''),
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
                'shape' => 'hanger',
                'a' => (string) $n['anchor'], 'b' => (string) $id,
                'path' => sprintf('M%d %d L%d %d', $anchor['x'], $anchor['bottom'], (int) ($n['x'] + $n['w'] / 2), (int) $n['y']),
                'label' => '', 'lx' => 0, 'ly' => 0, 'title' => (string) $n['title'], 'highlight' => false,
            ] + self::edgeStyle('attached', null);
        }

        self::applyRoutes($edges, $ctx);

        return self::annotate($edges, $placed, $siteOf, $tier);
    }

    /**
     * The vertical overlap of two placed cards, in px; negative when one is clear of the other.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    private static function overlapY(?array $a, ?array $b): int
    {
        if ($a === null || $b === null) {
            return 0;
        }

        return (int) (min($a['y'] + $a['h'], $b['y'] + $b['h']) - max($a['y'], $b['y']));
    }

    /**
     * What the router needs about one edge's two ends: the rects its ports sit on, the
     * compounds it may pass through because it terminates in them, and whether the two ends
     * share a compound (which makes it a pad route rather than a gutter one).
     *
     * @param  array<string, array<string, mixed>>  $placed
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, string>  $siteOf
     * @param  array<string, true>  $collapsed
     * @return array<string, mixed>|null
     */
    private static function routeRequest(string $a, string $b, array $placed, array $groups, array $siteOf, array $collapsed): ?array
    {
        $boxes = [];
        foreach ($groups as $g) {
            $boxes[(string) $g['key']] = ['x' => (int) $g['x'], 'y' => (int) $g['y'], 'w' => (int) $g['w'], 'h' => (int) $g['h']];
        }
        $end = function (string $id) use ($placed, $siteOf, $collapsed, $boxes): ?array {
            $site = $siteOf[$id] ?? null;
            if ($site !== null && isset($collapsed[$site], $boxes[$site])) {
                return ['rect' => $boxes[$site], 'site' => $site, 'card' => null];
            }
            if (! isset($placed[$id])) {
                return null;
            }
            $n = $placed[$id];

            return ['rect' => ['x' => (int) $n['x'], 'y' => (int) $n['y'], 'w' => (int) $n['w'], 'h' => (int) $n['h']], 'site' => $site, 'card' => $id];
        };
        $from = $end($a);
        $to = $end($b);
        if ($from === null || $to === null) {
            return null;
        }

        return [
            'mode' => $from['site'] !== null && $from['site'] === $to['site'] ? 'pad' : 'global',
            'from' => $from,
            'to' => $to,
            'group' => $from['site'] !== null ? ($boxes[$from['site']] ?? null) : null,
            'siblings' => $from['site'] === null ? [] : array_values(array_filter(
                array_map(
                    fn (array $n) => ['x' => (int) $n['x'], 'y' => (int) $n['y'], 'w' => (int) $n['w'], 'h' => (int) $n['h']],
                    array_filter($placed, fn ($n, $id) => ($n['site'] ?? null) === $from['site'] && $n['kind'] === 'member' && $id !== $a && $id !== $b, ARRAY_FILTER_USE_BOTH),
                ),
            )),
        ];
    }

    /**
     * Route every edge that asked for it, in `strcmp` order of id so a lane index is the same
     * number in PHP and in the browser, then place its label.
     *
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $ctx
     *
     * @param-out list<array<string, mixed>> $edges
     */
    private static function applyRoutes(array &$edges, array &$ctx): void
    {
        $order = [];
        foreach ($edges as $i => $edge) {
            if (isset($edge['_route'])) {
                $order[$i] = (string) $edge['id'];
            }
        }
        asort($order, SORT_STRING);

        foreach (array_keys($order) as $i) {
            /** @var array<string, mixed> $request */
            $request = $edges[$i]['_route'];
            unset($edges[$i]['_route']);
            $from = $request['from'];
            $to = $request['to'];

            if ($request['mode'] === 'pad' && $request['group'] !== null) {
                $points = self::padRoute($request['group'], $from['rect'], $to['rect'], $request['siblings']);
                $gap = null;
                $k = 0;
            } else {
                $obstacles = self::obstacles($ctx, [$from['site'], $to['site']], [$from['card'], $to['card']]);
                $routed = self::route($from['rect'], $to['rect'], $obstacles, $ctx, (string) $edges[$i]['id'], $request['ports'] ?? null);
                $points = $routed['points'];
                $gap = $routed['gap'];
                $k = $routed['k'];
            }

            $edges[$i]['path'] = self::polyline($points);
            [$lx, $ly, $fits] = self::labelOn($points, (string) ($edges[$i]['label'] ?? ''));
            $edges[$i]['lx'] = $lx;
            $edges[$i]['ly'] = $ly;
            // in a shared channel only the first edge labels the lane, or five OSPF lines print
            // five "ospf full" on top of each other
            if (! $fits || ($gap !== null && $k > 0)) {
                $edges[$i]['label'] = '';
            }
        }

        $edges = array_values($edges);
    }

    /**
     * Everything one edge has to miss: every compound it does not terminate in, and every card
     * that has no compound and is not one of its own ends.
     *
     * @param  array<string, mixed>  $ctx
     * @param  list<string|null>  $sites
     * @param  list<string|null>  $cards
     * @return list<array{x: int, y: int, w: int, h: int}>
     */
    private static function obstacles(array $ctx, array $sites, array $cards): array
    {
        $out = [];
        foreach ($ctx['group'] as $key => $rect) {
            if (! in_array($key, $sites, true)) {
                $out[] = $rect;
            }
        }
        foreach ($ctx['loose'] as $id => $rect) {
            if (! in_array($id, $cards, true)) {
                $out[] = $rect;
            }
        }

        return $out;
    }

    /**
     * The tier and compound of each end, on the edge itself, so the browser can look up a
     * neighbour without re-deriving the layout.
     *
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, array<string, mixed>>  $placed
     * @param  array<string, string>  $siteOf
     * @param  array<string, string>  $tier
     * @return list<array<string, mixed>>
     */
    private static function annotate(array $edges, array $placed, array $siteOf, array $tier): array
    {
        return array_map(function (array $e) use ($placed, $siteOf, $tier): array {
            $a = (string) $e['a'];
            $b = (string) $e['b'];

            return $e + [
                'shape' => 'line',
                'tier_a' => (string) ($placed[$a]['tier'] ?? $tier[$a] ?? ''),
                // a trunk lands on a site header, so its far end is a compound and not a card
                'tier_b' => $e['kind'] === 'trunk' ? '' : (string) ($placed[$b]['tier'] ?? $tier[$b] ?? ''),
                'site_a' => (string) ($siteOf[$a] ?? ''),
                'site_b' => $e['kind'] === 'trunk' ? $b : (string) ($siteOf[$b] ?? ''),
            ];
        }, $edges);
    }

    /**
     * One stroke from a spine-tier card to a site header, for the sessions of that site that
     * are healthy and agree on a routing-protocol set. The ones that do not are drawn beside
     * it, one line each.
     *
     * A trunk used to need every session of the site to be up and of one set, so a single down
     * session exploded the pod into a fan that crossed the trunks that did draw — which is the
     * opposite of what an operator needs to see. The odd session stays visible as its own line;
     * the healthy ones stay one stroke.
     *
     * The spine may be an unmonitored address several members peer with (`far:{address}`).
     * Those rows carry `b === null`, and skipping them is what drew twelve separate lines from
     * one fake spine straight through the gateway cards.
     *
     * @param  list<array<string, mixed>>  $underlay
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, string>  $siteOf
     * @param  array<string, string>  $tier
     * @param  array<string, array{x: int, y: int, bottom: int, id: string, collapsed: bool}>  $anchorOf
     * @param  array<string, true>  $sharedSet
     * @param  array<string, true>  $hlEdges
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $ctx
     * @return array<string, true> link_keys the trunk swallowed
     */
    private static function trunks(array $underlay, array $groups, array $siteOf, array $tier, array $anchorOf, array $sharedSet, array $hlEdges, array &$edges, array $ctx): array
    {
        $bySpineSite = [];
        foreach ($underlay as $e) {
            $a = (string) $e['a'];
            if ($e['b'] === null) {
                // an unmonitored far end that got a card on the spine tier is a spine here
                $far = 'far:' . (string) ($e['b_label'] ?? '');
                if (isset($sharedSet[(string) ($e['b_label'] ?? '')], $anchorOf[$far], $siteOf[$a])) {
                    $bySpineSite[$far][$siteOf[$a]][$a][] = $e;
                }

                continue;
            }
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
                // a member of the site with no session to this spine has to stay a visible gap,
                // so the whole group is drawn session by session
                if ($group === null || count($group['members']) < 2 || count($byLeaf) !== count($group['members']) || ! isset($anchorOf[$spine])) {
                    continue;
                }
                $sessions = [];
                foreach ($byLeaf as $rows) {
                    foreach ($rows as $row) {
                        $sessions[] = $row;
                    }
                }
                // the largest set of up sessions that agree on a protocol set; `bgp` is not
                // `bgp,ospf`, so a leaf that also runs OSPF does not join a BGP-only trunk
                $upSets = [];
                foreach ($sessions as $row) {
                    if (($row['up'] ?? null) === true) {
                        $upSets[self::protocolSet((string) $row['protocol'])][] = $row;
                    }
                }
                $best = '';
                foreach ($upSets as $set => $rows) {
                    if (count($rows) > count($upSets[$best] ?? [])) {
                        $best = (string) $set;
                    }
                }
                $trunked = $upSets[$best] ?? [];
                if (count($trunked) < 2) {
                    continue;
                }

                $id = 'edge:trunk:' . $spine . '|' . $siteKey;
                // one stroke from the spine's bottom to the site header's top. It stays that one
                // segment while nothing is in the way -- a Clos spine row over its pods -- and
                // takes the gutter when a tier sits between the two, which is what a far-end
                // spine above the gateway tier does. Either way it is one path.
                $spineCard = ['x' => (int) ($anchorOf[$spine]['x'] - self::CARD_W / 2), 'y' => (int) $anchorOf[$spine]['y'], 'w' => self::CARD_W, 'h' => (int) ($anchorOf[$spine]['bottom'] - $anchorOf[$spine]['y'])];
                $box = ['x' => (int) $group['x'], 'y' => (int) $group['y'], 'w' => (int) $group['w'], 'h' => (int) $group['h']];
                $ports = [
                    [(int) $anchorOf[$spine]['x'], (int) $anchorOf[$spine]['bottom']],
                    [(int) ($group['header']['x'] + $group['header']['w'] / 2), (int) $group['header']['y']],
                ];
                $straight = self::pathClean($ports, self::obstacles($ctx, [$siteOf[array_key_first($byLeaf)] ?? null, $siteKey], [$spine]));

                $edges[] = ($straight ? [] : ['_route' => ['mode' => 'global', 'from' => ['rect' => $spineCard, 'site' => null, 'card' => (string) $spine], 'to' => ['rect' => $box, 'site' => $siteKey, 'card' => null], 'group' => null, 'siblings' => [], 'ports' => $ports]]) + [
                    'kind' => 'trunk', 'layer' => 'underlay', 'id' => $id,
                    'shape' => 'trunk',
                    'a' => (string) $spine, 'b' => $siteKey,
                    'path' => self::polyline($ports),
                    'label' => sprintf('%d up', count($trunked)),
                    'lx' => (int) (($anchorOf[$spine]['x'] + $group['header']['x'] + $group['header']['w'] / 2) / 2),
                    'ly' => (int) (($anchorOf[$spine]['bottom'] + $group['header']['y']) / 2),
                    // a trunk is never one session, so the plural is not a choice
                    'title' => sprintf('%d up %s sessions from %s to every member of this site', count($trunked), $best, str_starts_with((string) $spine, 'far:') ? substr((string) $spine, 4) : $spine),
                    'highlight' => isset($hlEdges[$id]),
                ] + self::edgeStyle('trunk', true);
                foreach ($trunked as $row) {
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
    private static function lineEdge(string $kind, string $id, array $pa, array $pb, array $row, array $hlEdges, ?string $bId = null, string $shape = 'line'): array
    {
        $up = $row['up'] ?? null;
        $sameRow = $pa['y'] === $pb['y'];
        $path = $sameRow
            ? self::arcUnder($pa, $pb)
            : sprintf('M%d %d L%d %d', $pa['x'], $pa['y'] < $pb['y'] ? $pa['bottom'] : $pa['y'], $pb['x'], $pa['y'] < $pb['y'] ? $pb['y'] : $pb['bottom']);

        return [
            'shape' => $shape,
            'kind' => $kind,
            'layer' => 'underlay',
            'id' => $id,
            'a' => (string) $row['a'],
            // a placed node id on both ends, so the inspector and the client name one node: a
            // shared far end is `far:{address}`. A degree-1 outside session has no card and
            // keeps its label
            'b' => $bId ?? ($row['b'] === null ? (string) ($row['b_label'] ?? '') : (string) $row['b']),
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
                'x' => $group['x'] + (int) max(self::SITE_PAD, ($group['w'] - (int) $group['inner_w']) / 2),
                'y' => $group['y'] + self::SITE_HEADER + self::SITE_PAD,
                'w' => self::CARD_W, 'h' => self::CARD_H,
                'fillClass' => 'eg-unknown',
                'strokeClass' => match ($state) { 'down' => 'eg-card-down', 'warning' => 'eg-card-stale', default => 'eg-card-ok' },
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

    /**
     * The routing context of one picture: the rects an edge has to miss, the channels between
     * them, and the ring that is always clean.
     *
     * A rect is a compound, or a card that has no compound (a spine, an unmonitored far end).
     * The cards inside a compound are not separate obstacles — the compound covers them — which
     * is what lets a line pass a site without knowing how its members are packed.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, array<string, mixed>>  $placed
     * @return array<string, mixed>
     */
    public static function routeContext(array $groups, array $placed, int $width, int $height): array
    {
        $group = [];
        foreach ($groups as $g) {
            $group[(string) $g['key']] = ['x' => (int) $g['x'], 'y' => (int) $g['y'], 'w' => (int) $g['w'], 'h' => (int) $g['h']];
        }
        $loose = [];
        foreach ($placed as $id => $n) {
            if (($n['kind'] === 'member' || $n['kind'] === 'far') && ($n['site'] ?? null) === null) {
                $loose[(string) $id] = ['x' => (int) $n['x'], 'y' => (int) $n['y'], 'w' => (int) $n['w'], 'h' => (int) $n['h']];
            }
        }

        $all = array_merge(array_values($group), array_values($loose));
        $left = $all === [] ? 0 : min(array_column($all, 'x'));
        $top = $all === [] ? 0 : min(array_column($all, 'y'));
        $right = $all === [] ? $width : max(array_map(fn ($r) => $r['x'] + $r['w'], $all));
        $bottom = $all === [] ? $height : max(array_map(fn ($r) => $r['y'] + $r['h'], $all));
        // every obstacle is inside the bounds by at least MARGIN, so every point of the ring
        // below is at least MARGIN - OUTER outside every one of them. The picture's own rect is
        // in the union too, so a camera fitted to the drawing still sees the whole ring.
        $bounds = ['x' => $left - self::MARGIN, 'y' => $top - self::MARGIN];
        $bounds['w'] = $right + self::MARGIN - $bounds['x'];
        $bounds['h'] = $bottom + self::MARGIN - $bounds['y'];
        if ($width > 0 && $height > 0) {
            $bounds['w'] = max($width, $bounds['x'] + $bounds['w']) - min(0, $bounds['x']);
            $bounds['h'] = max($height, $bounds['y'] + $bounds['h']) - min(0, $bounds['y']);
            $bounds['x'] = min(0, $bounds['x']);
            $bounds['y'] = min(0, $bounds['y']);
        }

        $ctx = [
            'group' => $group,
            'loose' => $loose,
            'bounds' => $bounds,
            'ring' => self::inset($bounds, self::OUTER),
            'lanes' => [],
        ];

        return $ctx + self::channels($group, array_merge($group, $loose));
    }

    /**
     * The gaps a routed edge may run in: one between two compounds that are neighbours in the
     * same band, one between two consecutive bands. `TIER_GAP` is not a special channel — it is
     * simply the tall horizontal gap between two bands.
     *
     * @param  array<string, array{x: int, y: int, w: int, h: int}>  $group
     * @param  array<string, array{x: int, y: int, w: int, h: int}>  $obstacles
     * @return array{bands: list<array{keys: list<string>, top: int, bottom: int}>, vgaps: array<string, array{start: int, end: int, a: string, b: string}>, hgaps: array<string, array{start: int, end: int, upper: int, lower: int}>}
     */
    private static function channels(array $group, array $obstacles): array
    {
        $keys = array_keys($group);
        usort($keys, function (string $a, string $b) use ($group) {
            $ca = $group[$a]['y'] + $group[$a]['h'] / 2;
            $cb = $group[$b]['y'] + $group[$b]['h'] / 2;

            return $ca <=> $cb ?: ($group[$a]['x'] <=> $group[$b]['x'] ?: strcmp($a, $b));
        });

        $bands = [];
        foreach ($keys as $key) {
            $r = $group[$key];
            $last = count($bands) - 1;
            if ($last >= 0 && $r['y'] < $bands[$last]['bottom'] && $bands[$last]['top'] < $r['y'] + $r['h']) {
                $bands[$last]['keys'][] = $key;
                $bands[$last]['top'] = min($bands[$last]['top'], $r['y']);
                $bands[$last]['bottom'] = max($bands[$last]['bottom'], $r['y'] + $r['h']);

                continue;
            }
            $bands[] = ['keys' => [$key], 'top' => $r['y'], 'bottom' => $r['y'] + $r['h']];
        }
        foreach ($bands as &$band) {
            usort($band['keys'], fn (string $a, string $b) => $group[$a]['x'] <=> $group[$b]['x'] ?: strcmp($a, $b));
        }
        unset($band);

        $vgaps = [];
        foreach ($bands as $i => $band) {
            for ($j = 0; $j + 1 < count($band['keys']); $j++) {
                $a = $group[$band['keys'][$j]];
                $b = $group[$band['keys'][$j + 1]];
                $start = $a['x'] + $a['w'] + self::CLEARANCE;
                $end = $b['x'] - self::CLEARANCE;
                if ($end - $start < 2 || self::blockedX($obstacles, $a['x'] + $a['w'], $b['x'])) {
                    continue;
                }
                $vgaps['v:' . $i . ':' . $band['keys'][$j]] = ['start' => $start, 'end' => $end, 'a' => $band['keys'][$j], 'b' => $band['keys'][$j + 1]];
            }
        }

        $hgaps = [];
        for ($i = 0; $i + 1 < count($bands); $i++) {
            $start = $bands[$i]['bottom'] + self::CLEARANCE;
            $end = $bands[$i + 1]['top'] - self::CLEARANCE;
            if ($end - $start < 2 || self::blockedY($obstacles, $bands[$i]['bottom'], $bands[$i + 1]['top'])) {
                continue;
            }
            $hgaps['h:' . $i] = ['start' => $start, 'end' => $end, 'upper' => $i, 'lower' => $i + 1];
        }

        return ['bands' => $bands, 'vgaps' => $vgaps, 'hgaps' => $hgaps];
    }

    /** @param array<string, array{x: int, y: int, w: int, h: int}> $obstacles */
    private static function blockedX(array $obstacles, int $from, int $to): bool
    {
        foreach ($obstacles as $r) {
            $c = $r['x'] + $r['w'] / 2;
            if ($c > $from && $c < $to) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array{x: int, y: int, w: int, h: int}> $obstacles */
    private static function blockedY(array $obstacles, int $from, int $to): bool
    {
        foreach ($obstacles as $r) {
            $c = $r['y'] + $r['h'] / 2;
            if ($c > $from && $c < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * An orthogonal path from one rect to another that does not pass through a third.
     *
     * The candidates are tried in order and the first clean one wins: the channel between two
     * neighbouring compounds, the channel between two bands, then the four sides of the margin
     * ring, which is outside every obstacle by construction. A diagonal is never a candidate,
     * and neither is the straight chord — a line that crosses a compound it does not terminate
     * on is the bug this exists to prevent.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $from  the rect the edge leaves
     * @param  array{x: int, y: int, w: int, h: int}  $to
     * @param  list<array{x: int, y: int, w: int, h: int}>  $obstacles  everything it has to miss
     * @param  array<string, mixed>  $ctx  routeContext(), whose lane lists this call may extend
     * @param  array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null  $ports  overrides the side midpoints (a trunk leaves the spine's bottom and lands on a header's top)
     * @return array{points: list<array{0: int, 1: int}>, gap: string|null, k: int}
     */
    public static function route(array $from, array $to, array $obstacles, array &$ctx, string $id, ?array $ports = null): array
    {
        [$ps, $pd] = $ports ?? self::ports($from, $to);

        $overlapY = min($from['y'] + $from['h'], $to['y'] + $to['h']) - max($from['y'], $to['y']) > 0;
        $candidates = [];
        foreach ($ctx['vgaps'] as $key => $gap) {
            if ($overlapY && self::gapJoins($gap, $from, $to, true)) {
                $candidates[] = ['gap' => $key, 'axis' => 'x', 'spec' => $gap];
            }
        }
        foreach ($ctx['hgaps'] as $key => $gap) {
            if (! $overlapY && self::gapJoins($gap, $from, $to, false)) {
                $candidates[] = ['gap' => $key, 'axis' => 'y', 'spec' => $gap];
            }
        }

        foreach ($candidates as $candidate) {
            $k = count($ctx['lanes'][$candidate['gap']] ?? []);
            $lane = self::lane($candidate['spec']['start'], $candidate['spec']['end'], $k);
            $points = $candidate['axis'] === 'x'
                ? [$ps, [$lane, $ps[1]], [$lane, $pd[1]], $pd]
                : [$ps, [$ps[0], $lane], [$pd[0], $lane], $pd];
            if (self::pathClean($points, $obstacles)) {
                $ctx['lanes'][$candidate['gap']][] = $id;

                return ['points' => $points, 'gap' => $candidate['gap'], 'k' => $k];
            }
        }

        foreach ([$ctx['ring'], self::inset(self::grow($ctx['bounds'], self::GUTTER), self::OUTER)] as $ring) {
            foreach (self::ringCandidates($ps, $pd, $ring) as $points) {
                if (self::pathClean($points, $obstacles)) {
                    return ['points' => $points, 'gap' => null, 'k' => 0];
                }
            }
        }

        $escape = self::cornerEscape($ps, $pd, $from, $to, $ctx['ring'], $obstacles);

        // total by construction: a caller always gets an orthogonal path, and a unit test that
        // expected a clean one fails loudly instead of the picture drawing a diagonal
        return ['points' => $escape ?? self::ringCandidates($ps, $pd, $ctx['ring'])[0], 'gap' => null, 'k' => 0];
    }

    /**
     * @param  array{start: int, end: int}  $gap
     * @param  array{x: int, y: int, w: int, h: int}  $from
     * @param  array{x: int, y: int, w: int, h: int}  $to
     */
    private static function gapJoins(array $gap, array $from, array $to, bool $vertical): bool
    {
        $a = $vertical ? $from['x'] + $from['w'] / 2 : $from['y'] + $from['h'] / 2;
        $b = $vertical ? $to['x'] + $to['w'] / 2 : $to['y'] + $to['h'] / 2;

        return min($a, $b) < $gap['start'] && $gap['end'] < max($a, $b);
    }

    /** One lane inside a gap. A gap that is already full reuses a lane rather than leaving it. */
    private static function lane(int $start, int $end, int $k): int
    {
        $n = max(1, intdiv($end - $start, self::LANE_PITCH));

        return $n === 1
            ? (int) round(($start + $end) / 2)
            : $start + intdiv(self::LANE_PITCH, 2) + ($k % $n) * self::LANE_PITCH;
    }

    /**
     * @param  array{0: int, 1: int}  $ps
     * @param  array{0: int, 1: int}  $pd
     * @param  array{x: int, y: int, w: int, h: int}  $ring
     * @return list<list<array{0: int, 1: int}>>
     */
    private static function ringCandidates(array $ps, array $pd, array $ring): array
    {
        $top = $ring['y'];
        $bottom = $ring['y'] + $ring['h'];
        $left = $ring['x'];
        $right = $ring['x'] + $ring['w'];

        return [
            [$ps, [$ps[0], $top], [$pd[0], $top], $pd],
            [$ps, [$ps[0], $bottom], [$pd[0], $bottom], $pd],
            [$ps, [$left, $ps[1]], [$left, $pd[1]], $pd],
            [$ps, [$right, $ps[1]], [$right, $pd[1]], $pd],
        ];
    }

    /**
     * The last resort: leave each rect at one of its own corners, meet on the ring, and walk it.
     *
     * @param  array{0: int, 1: int}  $ps
     * @param  array{0: int, 1: int}  $pd
     * @param  array{x: int, y: int, w: int, h: int}  $from
     * @param  array{x: int, y: int, w: int, h: int}  $to
     * @param  array{x: int, y: int, w: int, h: int}  $ring
     * @param  list<array{x: int, y: int, w: int, h: int}>  $obstacles
     * @return list<array{0: int, 1: int}>|null
     */
    private static function cornerEscape(array $ps, array $pd, array $from, array $to, array $ring, array $obstacles): ?array
    {
        $source = self::escape($ps, $from, $ring, $obstacles);
        $target = self::escape($pd, $to, $ring, $obstacles);
        if ($source === null || $target === null) {
            return null;
        }
        $walk = self::walk($ring, end($source), end($target));

        return array_merge($source, $walk, array_reverse($target));
    }

    /**
     * From a port, round its own rect to the first corner with a clean ray out to the ring.
     *
     * The walk follows the boundary corner by corner, so every segment lies on an edge of the
     * rect and stays orthogonal: jumping straight to the far corner would draw the diagonal
     * this router exists to avoid.
     *
     * @param  array{0: int, 1: int}  $port
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @param  array{x: int, y: int, w: int, h: int}  $ring
     * @param  list<array{x: int, y: int, w: int, h: int}>  $obstacles
     * @return list<array{0: int, 1: int}>|null
     */
    private static function escape(array $port, array $rect, array $ring, array $obstacles): ?array
    {
        $l = $rect['x'];
        $t = $rect['y'];
        $r = $rect['x'] + $rect['w'];
        $b = $rect['y'] + $rect['h'];
        $rt = 'rt';
        $rb = 'rb';
        $lb = 'lb';
        $lt = 'lt';
        $point = [$rt => [$r, $t], $rb => [$r, $b], $lb => [$l, $b], $lt => [$l, $t]];
        // the two outward rays of each corner, in the order the corner faces
        $rays = [
            $rt => [[$r, $ring['y']], [$ring['x'] + $ring['w'], $t]],
            $rb => [[$ring['x'] + $ring['w'], $b], [$r, $ring['y'] + $ring['h']]],
            $lb => [[$l, $ring['y'] + $ring['h']], [$ring['x'], $b]],
            $lt => [[$ring['x'], $t], [$l, $ring['y']]],
        ];
        // clockwise with y down, starting at the corner the port's own side runs into
        $order = match (self::sideOf($rect, $port)) {
            'right' => [$rb, $lb, $lt, $rt],
            'bottom' => [$lb, $lt, $rt, $rb],
            'left' => [$lt, $rt, $rb, $lb],
            default => [$rt, $rb, $lb, $lt],
        };

        $path = [$port];
        foreach ($order as $corner) {
            $path[] = $point[$corner];
            if (! self::pathClean($path, $obstacles)) {
                return null;
            }
            foreach ($rays[$corner] as $ray) {
                if (self::pathClean([$point[$corner], $ray], $obstacles)) {
                    return [...$path, $ray];
                }
            }
        }

        return null;
    }

    /**
     * The corners of `$rect` passed walking from one boundary point to another, the short way
     * round; clockwise on a tie.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @param  array{0: int, 1: int}  $from
     * @param  array{0: int, 1: int}  $to
     * @return list<array{0: int, 1: int}>
     */
    private static function walk(array $rect, array $from, array $to): array
    {
        $perimeter = 2 * $rect['w'] + 2 * $rect['h'];
        $a = self::onPerimeter($rect, $from);
        $b = self::onPerimeter($rect, $to);
        $clockwise = ($b - $a + $perimeter) % $perimeter;
        $corners = [
            0 => [$rect['x'], $rect['y']],
            $rect['w'] => [$rect['x'] + $rect['w'], $rect['y']],
            $rect['w'] + $rect['h'] => [$rect['x'] + $rect['w'], $rect['y'] + $rect['h']],
            2 * $rect['w'] + $rect['h'] => [$rect['x'], $rect['y'] + $rect['h']],
        ];

        $out = [];
        $order = $clockwise <= $perimeter - $clockwise ? 1 : -1;
        $steps = $order === 1 ? $clockwise : $perimeter - $clockwise;
        for ($i = 1; $i < $steps; $i++) {
            $at = (($a + $order * $i) % $perimeter + $perimeter) % $perimeter;
            if (isset($corners[$at])) {
                $out[] = $corners[$at];
            }
        }

        return $out;
    }

    /**
     * Distance of a boundary point from the rect's top-left corner, clockwise with y down.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @param  array{0: int, 1: int}  $p
     */
    private static function onPerimeter(array $rect, array $p): int
    {
        if ($p[1] <= $rect['y']) {
            return (int) ($p[0] - $rect['x']);
        }
        if ($p[0] >= $rect['x'] + $rect['w']) {
            return (int) ($rect['w'] + $p[1] - $rect['y']);
        }
        if ($p[1] >= $rect['y'] + $rect['h']) {
            return (int) ($rect['w'] + $rect['h'] + $rect['x'] + $rect['w'] - $p[0]);
        }

        return (int) (2 * $rect['w'] + $rect['h'] + $rect['y'] + $rect['h'] - $p[1]);
    }

    /**
     * Inside one compound: out of the port along its own normal, then round the inset rect.
     *
     * Not the global router's candidates, and not "the nearest point on the ring" either: the
     * port is inside the inset, because SITE_PAD is 10 and CLEARANCE is 4, so those two are
     * different polylines. Sibling cards are obstacles even though their compound is an
     * endpoint; the two endpoint cards are not.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $rect  the compound
     * @param  array{x: int, y: int, w: int, h: int}  $from
     * @param  array{x: int, y: int, w: int, h: int}  $to
     * @param  list<array{x: int, y: int, w: int, h: int}>  $siblings
     * @return list<array{0: int, 1: int}>
     */
    public static function padRoute(array $rect, array $from, array $to, array $siblings = []): array
    {
        $inset = self::inset($rect, self::CLEARANCE);
        [$ps, $pd] = self::ports($from, $to);
        [$ps, $hitS] = self::exit($ps, $from, $inset, $siblings);
        [$pd, $hitD] = self::exit($pd, $to, $inset, $siblings);

        return array_merge([$ps, $hitS], self::walk($inset, $hitS, $hitD), [$hitD, $pd]);
    }

    /**
     * The port and where its outward normal meets the inset, trying each side clockwise until
     * the ray misses every sibling.
     *
     * @param  array{0: int, 1: int}  $port
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @param  array{x: int, y: int, w: int, h: int}  $inset
     * @param  list<array{x: int, y: int, w: int, h: int}>  $siblings
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    private static function exit(array $port, array $rect, array $inset, array $siblings): array
    {
        $order = ['right', 'bottom', 'left', 'top'];
        $start = array_search(self::sideOf($rect, $port), $order, true);
        for ($i = 0; $i < 4; $i++) {
            $side = $order[((int) $start + $i) % 4];
            $p = self::side($rect, $side);
            $hit = match ($side) {
                'right' => [$inset['x'] + $inset['w'], $p[1]],
                'left' => [$inset['x'], $p[1]],
                'bottom' => [$p[0], $inset['y'] + $inset['h']],
                default => [$p[0], $inset['y']],
            };
            if (self::pathClean([$p, $hit], $siblings)) {
                return [$p, $hit];
            }
        }

        $p = self::side($rect, $order[(int) $start]);

        return [$p, match ($order[(int) $start]) {
            'right' => [$inset['x'] + $inset['w'], $p[1]],
            'left' => [$inset['x'], $p[1]],
            'bottom' => [$p[0], $inset['y'] + $inset['h']],
            default => [$p[0], $inset['y']],
        }];
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @param  array{0: int, 1: int}  $port
     */
    private static function sideOf(array $rect, array $port): string
    {
        return match (true) {
            $port[0] >= $rect['x'] + $rect['w'] => 'right',
            $port[0] <= $rect['x'] => 'left',
            $port[1] >= $rect['y'] + $rect['h'] => 'bottom',
            default => 'top',
        };
    }

    /**
     * The two side midpoints an edge leaves and lands on: the facing pair on whichever axis the
     * two centres are further apart.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $from
     * @param  array{x: int, y: int, w: int, h: int}  $to
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    private static function ports(array $from, array $to): array
    {
        $dx = ($to['x'] + $to['w'] / 2) - ($from['x'] + $from['w'] / 2);
        $dy = ($to['y'] + $to['h'] / 2) - ($from['y'] + $from['h'] / 2);
        if (abs($dx) >= abs($dy)) {
            return $dx >= 0
                ? [self::side($from, 'right'), self::side($to, 'left')]
                : [self::side($from, 'left'), self::side($to, 'right')];
        }

        return $dy >= 0
            ? [self::side($from, 'bottom'), self::side($to, 'top')]
            : [self::side($from, 'top'), self::side($to, 'bottom')];
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @return array{0: int, 1: int}
     */
    private static function side(array $rect, string $which): array
    {
        return match ($which) {
            'right' => [$rect['x'] + $rect['w'], (int) round($rect['y'] + $rect['h'] / 2)],
            'left' => [$rect['x'], (int) round($rect['y'] + $rect['h'] / 2)],
            'bottom' => [(int) round($rect['x'] + $rect['w'] / 2), $rect['y'] + $rect['h']],
            default => [(int) round($rect['x'] + $rect['w'] / 2), $rect['y']],
        };
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @return array{x: int, y: int, w: int, h: int}
     */
    private static function inset(array $rect, int $by): array
    {
        return ['x' => $rect['x'] + $by, 'y' => $rect['y'] + $by, 'w' => max(1, $rect['w'] - 2 * $by), 'h' => max(1, $rect['h'] - 2 * $by)];
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     * @return array{x: int, y: int, w: int, h: int}
     */
    private static function grow(array $rect, int $by): array
    {
        return ['x' => $rect['x'] - $by, 'y' => $rect['y'] - $by, 'w' => $rect['w'] + 2 * $by, 'h' => $rect['h'] + 2 * $by];
    }

    /**
     * Whether a polyline stays out of every obstacle. Vertex-only checks are not the test: a
     * segment that cuts a corner has clean vertices and is still drawn through the box.
     *
     * @param  list<array{0: int, 1: int}>  $points
     * @param  list<array{x: int, y: int, w: int, h: int}>  $obstacles
     */
    public static function pathClean(array $points, array $obstacles): bool
    {
        for ($i = 1; $i < count($points); $i++) {
            if ($points[$i] === $points[$i - 1]) {
                continue;
            }
            if (! self::segmentClean($points[$i - 1], $points[$i], $obstacles)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{0: int, 1: int}  $a
     * @param  array{0: int, 1: int}  $b
     * @param  list<array{x: int, y: int, w: int, h: int}>  $obstacles
     */
    private static function segmentClean(array $a, array $b, array $obstacles): bool
    {
        $length = sqrt((($b[0] - $a[0]) ** 2) + (($b[1] - $a[1]) ** 2));
        $samples = [];
        for ($d = 0; $d <= $length; $d += self::SAMPLE_PX) {
            $t = $length > 0 ? $d / $length : 0.0;
            $samples[] = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
        }
        $samples[] = $b;

        foreach ($samples as $p) {
            foreach ($obstacles as $r) {
                $inside = min($p[0] - $r['x'], $r['x'] + $r['w'] - $p[0], $p[1] - $r['y'], $r['y'] + $r['h'] - $p[1]);
                if ($inside > self::INSIDE_TOL) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * An SVG path of an orthogonal polyline, with repeated vertices dropped.
     *
     * @param  list<array{0: int, 1: int}>  $points
     */
    private static function polyline(array $points): string
    {
        $out = '';
        $previous = null;
        foreach ($points as $p) {
            $vertex = [(int) round($p[0]), (int) round($p[1])];
            if ($vertex === $previous) {
                continue;
            }
            $out .= ($out === '' ? 'M' : ' L') . $vertex[0] . ' ' . $vertex[1];
            $previous = $vertex;
        }

        return $out;
    }

    /**
     * Where a routed edge's label goes: the midpoint of the polyline's longest segment, and
     * only when that segment is long enough to hold the string.
     *
     * The straight chord's midpoint is what put "ospf full" on a card the line does not
     * terminate on, and once the path is a polyline that midpoint is not on it at all.
     *
     * @param  list<array{0: int, 1: int}>  $points
     * @return array{0: int, 1: int, 2: bool}
     */
    private static function labelOn(array $points, string $label): array
    {
        $best = null;
        $longest = -1.0;
        for ($i = 1; $i < count($points); $i++) {
            $length = abs($points[$i][0] - $points[$i - 1][0]) + abs($points[$i][1] - $points[$i - 1][1]);
            if ($length > $longest) {
                $longest = $length;
                $best = [$points[$i - 1], $points[$i]];
            }
        }
        if ($best === null) {
            return [0, 0, false];
        }

        return [
            (int) round(($best[0][0] + $best[1][0]) / 2),
            (int) round(($best[0][1] + $best[1][1]) / 2),
            $longest >= mb_strlen($label) * self::EDGE_CHAR_W + 8,
        ];
    }
}
