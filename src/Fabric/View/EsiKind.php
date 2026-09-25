<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

/**
 * What kind of Ethernet segment a `netconf_evpn_esi` row describes, and which of its flags
 * mean "this segment is degraded" (design doc `docs/EVPN-FABRIC-UI.md`, plan §11 E1).
 *
 * The distinction that matters is ESI-LAG versus anycast gateway. A type-5 segment (`05:…`,
 * configured on an `irb.N` unit, no remote PE) is the same value on both gateways of a CRB
 * fabric: it is not a multihoming LAG, it has no member ports and no traffic of its own, and
 * drawing it as one would put a bracket between two routers that share no cable.
 *
 * On the one production fabric this was checked against (2026-09-25, 14 members polled) the
 * ESI table holds 46 segments, every one of them `01:…`, so no such row exists there today.
 * The predicate is defence in depth: a user copy of `evpn-fabric.yaml` without the `irb.`
 * filter in its XPath, a second vendor filling the same columns, or the `esi-forwarding`
 * mapping on a gateway can all produce one.
 */
final class EsiKind
{
    /**
     * The flags `resources/views/fabric/esis.blade.php` paints a table row `danger` for.
     * Kept here so the tab and the picture cannot drift apart.
     */
    public const TAB_DANGER = ['single-pe', 'df-disagree', 'df-both', 'mode-differs', 'lag-down', 'unresolved'];

    /**
     * The flags that light the degraded chip on a card and on an ESI pair. Deliberately **not**
     * TAB_DANGER: `single-pe` is dropped, because one PE is an incomplete segment rather than a
     * degraded pair and the picture has no pair to mark; `lacp-degraded` is added, because
     * members that stopped distributing are an operational fault even though the ESI tab only
     * colours that cell and not the row.
     */
    public const CHIP = ['df-disagree', 'df-both', 'mode-differs', 'lag-down', 'unresolved', 'lacp-degraded'];

    /**
     * An anycast gateway segment rather than an ESI-LAG: the type-5 prefix, or a local
     * interface that is an IRB unit.
     */
    public static function isGateway(string $esi, ?string $ifname): bool
    {
        if ($ifname !== null && preg_match('/^irb(\.|$)/i', $ifname) === 1) {
            return true;
        }

        return strncasecmp($esi, '05:', 3) === 0;
    }

    /**
     * Whether an `EsiMatrix` row is an ESI-LAG: at least one side is a local interface that is
     * not an IRB. A `05:` segment whose only local interfaces are `irb.N` never qualifies.
     *
     * @param  array<string, mixed>  $row  an EsiMatrix::build() row
     */
    public static function isLag(array $row): bool
    {
        $esi = (string) ($row['esi'] ?? '');
        /** @var array<int|string, array<string, mixed>> $sides */
        $sides = (array) ($row['sides'] ?? []);
        foreach ($sides as $side) {
            $ifname = $side['ifname'] ?? null;
            if (! self::isGateway($esi, $ifname === null ? null : (string) $ifname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a flag set makes the segment degraded on the picture.
     *
     * @param  list<string>  $flags
     */
    public static function degraded(array $flags): bool
    {
        return array_intersect($flags, self::CHIP) !== [];
    }
}
