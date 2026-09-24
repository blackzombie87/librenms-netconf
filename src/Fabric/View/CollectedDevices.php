<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * Which members of a fabric the plugin actually collects from (plan §10.4).
 *
 * "There is a LibreNMS device behind this VTEP" and "the plugin polls it" are not the same
 * thing, and the checks used to take the first for the second: on the first production fabric
 * two MX204s were members whose loopbacks are VTEP addresses but on which NETCONF was never
 * enabled, so every symmetry check compared a full leaf against their empty tables and blamed
 * them — 1,442 issues. A member without EVPN rows is not a peer that lost its sessions; it is
 * a member nobody asked.
 *
 * The evidence is ownership of rows in the fabric tables: that is what every check reads, and
 * it is true exactly when there is something to compare. The collector status separates the two
 * reasons for having none — never polled, or polled and silent — which is what the new
 * `member-not-collected` issue says.
 */
final class CollectedDevices
{
    /** The tables a member of a fabric writes when the plugin collects from it. */
    private const TABLES = ['vni', 'neighbor', 'tunnel', 'esi'];

    /**
     * @param  list<int>  $deviceIds
     * @return array<int, array{rows: bool, polled: bool}>
     */
    public static function forDevices(array $deviceIds): array
    {
        $deviceIds = array_values(array_unique($deviceIds));
        if ($deviceIds === []) {
            return [];
        }
        $out = [];
        foreach ($deviceIds as $id) {
            $out[$id] = ['rows' => false, 'polled' => false];
        }

        $query = null;
        foreach (self::TABLES as $table) {
            $next = DB::table(TableSchema::tableName($table))->select('device_id')->whereIn('device_id', $deviceIds)->distinct();
            $query = $query === null ? $next : $query->union($next);
        }
        assert($query instanceof \Illuminate\Database\Query\Builder);
        foreach ($query->get() as $row) {
            $out[(int) $row->device_id]['rows'] = true;
        }
        foreach (DB::table('netconf_device_status')->whereIn('device_id', $deviceIds)->whereNotNull('last_ok')->pluck('device_id') as $id) {
            $out[(int) $id]['polled'] = true;
        }

        return $out;
    }

    /**
     * @param  list<int>  $deviceIds
     * @return array<int, true> the devices with fabric rows
     */
    public static function collected(array $deviceIds): array
    {
        return array_map(fn () => true, array_filter(self::forDevices($deviceIds), fn (array $state) => $state['rows']));
    }
}
