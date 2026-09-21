<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * Per-device EVPN figures from the per-leaf tables, the junos-evpn instance metric and the
 * collector status: the one place the fabric list, the overview, the members tab, the device
 * badge and (F4) the checks engine take their counts from. Lists (VNIs, ESIs, instances) are
 * distinct per device so totals() can count them distinct over several devices.
 */
final class DeviceStats
{
    /**
     * @return array{vnis: list<int>, irbs: int, instances: list<string>, esis: list<string>, esis_local: int, esis_df: int, esis_degraded: int, tunnels: int, neighbors: int, local_macs: int|null, remote_macs: int|null, dup_macs: int, orphan_vnis: int, collector_failing: bool}
     */
    public static function forDevice(int $deviceId): array
    {
        return self::forDevices([$deviceId])[$deviceId];
    }

    /**
     * @param  list<int>  $deviceIds
     * @return array<int, array{vnis: list<int>, irbs: int, instances: list<string>, esis: list<string>, esis_local: int, esis_df: int, esis_degraded: int, tunnels: int, neighbors: int, local_macs: int|null, remote_macs: int|null, dup_macs: int, orphan_vnis: int, collector_failing: bool}>
     */
    public static function forDevices(array $deviceIds): array
    {
        $deviceIds = array_values(array_unique($deviceIds));
        if ($deviceIds === []) {
            return [];
        }
        $out = [];
        foreach ($deviceIds as $id) {
            $out[$id] = self::empty();
        }
        $sets = [];

        foreach (DB::table(TableSchema::tableName('vni'))->whereIn('device_id', $deviceIds)->get(['device_id', 'vni', 'instance', 'irb_ifname']) as $row) {
            $id = (int) $row->device_id;
            $sets[$id]['vnis'][(int) $row->vni] = true;
            if ($row->instance !== null && $row->instance !== '') {
                $sets[$id]['instances'][(string) $row->instance] = true;
            }
            if ($row->irb_ifname !== null) {
                $out[$id]['irbs']++;
            }
        }
        foreach (DB::table(TableSchema::tableName('neighbor'))->whereIn('device_id', $deviceIds)->distinct()->get(['device_id', 'instance', 'neighbor_ip']) as $row) {
            $id = (int) $row->device_id;
            $sets[$id]['instances'][(string) $row->instance] = true;
            $sets[$id]['neighbors'][(string) $row->neighbor_ip] = true;
        }
        foreach (DB::table(TableSchema::tableName('esi'))->whereIn('device_id', $deviceIds)->get(['device_id', 'esi', 'local_ifname', 'is_df', 'status', 'lag_status', 'remote_vtep_ips']) as $row) {
            $id = (int) $row->device_id;
            $sets[$id]['esis'][(string) $row->esi] = true;
            if ($row->local_ifname === null) {
                continue;
            }
            $out[$id]['esis_local']++;
            if ((int) $row->is_df === 1) {
                $out[$id]['esis_df']++;
            }
            if (self::esiDegraded($row)) {
                $out[$id]['esis_degraded']++;
            }
        }
        foreach (DB::table(TableSchema::tableName('tunnel'))->whereIn('device_id', $deviceIds)->selectRaw('device_id, count(*) as n')->groupBy('device_id')->get() as $row) {
            $out[(int) $row->device_id]['tunnels'] = (int) $row->n;
        }

        // VNIs without a single flood-list entry on the same leaf; only meaningful for leaves
        // whose remote table was collected at all
        $flooded = [];
        foreach (DB::table(TableSchema::tableName('vni_vtep'))->whereIn('device_id', $deviceIds)->distinct()->get(['device_id', 'vni']) as $row) {
            $flooded[(int) $row->device_id][(int) $row->vni] = true;
        }
        foreach ($flooded as $id => $vnis) {
            foreach (array_keys($sets[$id]['vnis'] ?? []) as $vni) {
                if (! isset($vnis[$vni])) {
                    $out[$id]['orphan_vnis']++;
                }
            }
        }

        foreach (self::instanceMetrics($deviceIds) as $id => $instances) {
            foreach ($instances as $instance) {
                $out[$id]['local_macs'] = ($out[$id]['local_macs'] ?? 0) + (int) ($instance['values']['local_macs'] ?? 0);
                $out[$id]['remote_macs'] = ($out[$id]['remote_macs'] ?? 0) + (int) ($instance['values']['remote_macs'] ?? 0);
            }
        }
        foreach (DB::table('sensors')->whereIn('device_id', $deviceIds)->where('sensor_type', 'like', 'netconf-%-dup-mac-total')->get(['device_id', 'sensor_current']) as $row) {
            $out[(int) $row->device_id]['dup_macs'] += (int) $row->sensor_current;
        }
        foreach (array_keys(self::failing($deviceIds)) as $id) {
            $out[$id]['collector_failing'] = true;
        }

        foreach ($sets as $id => $set) {
            $out[$id]['vnis'] = array_keys($set['vnis'] ?? []);
            $out[$id]['instances'] = array_keys($set['instances'] ?? []);
            $out[$id]['esis'] = array_keys($set['esis'] ?? []);
            $out[$id]['neighbors'] = count($set['neighbors'] ?? []);
        }

        return $out;
    }

    /**
     * The junos-evpn "instance" metric rows per device and instance, decoded once: the figures
     * below and the checks engine read the same rows.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, array<string, array{values: array<string, mixed>, labels: array<string, mixed>}>>
     */
    public static function instanceMetrics(array $deviceIds): array
    {
        $out = [];
        foreach (DB::table('netconf_metrics')->whereIn('device_id', $deviceIds)->where('mapping', 'instance')->where('definition', 'like', '%-evpn')->get(['device_id', 'metric_index', 'values', 'labels']) as $row) {
            $values = json_decode((string) $row->values, true);
            $labels = json_decode((string) $row->labels, true);
            $out[(int) $row->device_id][(string) $row->metric_index] = [
                'values' => is_array($values) ? $values : [],
                'labels' => is_array($labels) ? $labels : [],
            ];
        }

        return $out;
    }

    /**
     * Devices whose collector is failing => the number of consecutive failures.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, int>
     */
    public static function failing(array $deviceIds): array
    {
        /** @var array<int, int> $failing */
        $failing = DB::table('netconf_device_status')->whereIn('device_id', $deviceIds)->where('consecutive_failures', '>', 0)
            ->pluck('consecutive_failures', 'device_id')->map(fn ($v) => (int) $v)->all();

        return $failing;
    }

    /**
     * Figures over several devices: VNIs, ESIs and instances counted distinct (the same VNI on
     * two leaves is one VNI), everything else summed.
     *
     * @param  iterable<array{vnis: list<int>, instances: list<string>, esis: list<string>, esis_degraded: int, tunnels: int, neighbors: int, local_macs: int|null, remote_macs: int|null, dup_macs: int, orphan_vnis: int, collector_failing: bool}>  $perDevice
     * @return array{vnis: int, esis: int, instances: int, tunnels: int, neighbors: int, local_macs: int, remote_macs: int, esis_degraded: int, dup_macs: int, orphan_vnis: int, collector_failing: int}
     */
    public static function totals(iterable $perDevice): array
    {
        $vnis = [];
        $esis = [];
        $instances = [];
        $sums = ['tunnels' => 0, 'neighbors' => 0, 'local_macs' => 0, 'remote_macs' => 0, 'esis_degraded' => 0, 'dup_macs' => 0, 'orphan_vnis' => 0, 'collector_failing' => 0];
        foreach ($perDevice as $d) {
            foreach ($d['vnis'] as $vni) {
                $vnis[$vni] = true;
            }
            foreach ($d['esis'] as $esi) {
                $esis[$esi] = true;
            }
            foreach ($d['instances'] as $instance) {
                $instances[$instance] = true;
            }
            foreach (['tunnels', 'neighbors', 'local_macs', 'remote_macs', 'esis_degraded', 'dup_macs', 'orphan_vnis'] as $key) {
                $sums[$key] += (int) ($d[$key] ?? 0);
            }
            $sums['collector_failing'] += $d['collector_failing'] ? 1 : 0;
        }

        return ['vnis' => count($vnis), 'esis' => count($esis), 'instances' => count($instances)] + $sums;
    }

    /** A local ESI-LAG whose LAG is not up, whose ESI is unresolved or that has no remote PE. */
    public static function esiDegraded(object $esi): bool
    {
        $lag = (string) ($esi->lag_status ?? '');
        if ($lag !== '' && ! str_starts_with($lag, 'Up')) {
            return true;
        }
        $status = (string) ($esi->status ?? '');
        if ($status !== '' && ! str_starts_with($status, 'Resolved')) {
            return true;
        }
        $remote = json_decode((string) ($esi->remote_vtep_ips ?? '[]'), true);

        return ! is_array($remote) || $remote === [];
    }

    /**
     * @return array{vnis: list<int>, irbs: int, instances: list<string>, esis: list<string>, esis_local: int, esis_df: int, esis_degraded: int, tunnels: int, neighbors: int, local_macs: int|null, remote_macs: int|null, dup_macs: int, orphan_vnis: int, collector_failing: bool}
     */
    private static function empty(): array
    {
        return ['vnis' => [], 'irbs' => 0, 'instances' => [], 'esis' => [], 'esis_local' => 0, 'esis_df' => 0, 'esis_degraded' => 0, 'tunnels' => 0, 'neighbors' => 0, 'local_macs' => null, 'remote_macs' => null, 'dup_macs' => 0, 'orphan_vnis' => 0, 'collector_failing' => false];
    }
}
