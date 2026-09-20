<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Port;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * ESI / multihoming tab (plan §7.4): one row per Ethernet segment over the whole fabric —
 * the PEs (monitored sides with their ESI-LAG, plus remote PEs only known by address), mode,
 * per-side LAG and resolution status, DF / BDF, aliasing, LACP members not distributing and
 * the remote MAC count. Flags (plan §7.5 check 5): single PE, DF disagreement, mode differs,
 * a side down or unresolved, aliasing off on one side, LACP degraded.
 */
final class EsiMatrix
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forFabric(FabricNodes $nodes): array
    {
        $deviceIds = $nodes->deviceIds() ?: [0];
        $esis = DB::table(TableSchema::tableName('esi'))->whereIn('device_id', $deviceIds)->get()->map(fn ($r) => (array) $r)->all();

        // LACP members not distributing, per device and aggregate (lacp.yaml count sensor, index = ae name)
        $lacp = [];
        foreach (DB::table('sensors')->whereIn('device_id', $deviceIds)->where('poller_type', 'netconf')->where('sensor_type', 'like', 'netconf-%-lacp-members-degraded')->get(['device_id', 'sensor_index', 'sensor_current']) as $s) {
            $lacp[(int) $s->device_id][(string) $s->sensor_index] = (int) $s->sensor_current;
        }

        $rows = self::build($esis, $nodes->deviceNodes(), $lacp);

        $portIds = [];
        foreach ($rows as $row) {
            foreach ($row['sides'] as $side) {
                if ($side['port_id'] !== null) {
                    $portIds[] = $side['port_id'];
                }
            }
        }
        /** @var \Illuminate\Support\Collection<int, Port> $ports */
        $ports = Port::query()->whereIn('port_id', array_values(array_unique($portIds)) ?: [0])->get()->keyBy('port_id');
        foreach ($rows as &$row) {
            foreach ($row['sides'] as &$side) {
                $side['port'] = $side['port_id'] === null ? null : $ports->get($side['port_id']);
            }
            unset($side);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $esiRows  netconf_evpn_esi rows of the monitored members
     * @param  array<int, list<string>>  $deviceNodes  device_id => addresses (member address first)
     * @param  array<int, array<string, int>>  $lacp  device_id => ae name => members not distributing
     * @return list<array<string, mixed>>
     */
    public static function build(array $esiRows, array $deviceNodes, array $lacp = []): array
    {
        $addressDevice = [];
        foreach ($deviceNodes as $deviceId => $ips) {
            foreach ($ips as $ip) {
                $addressDevice[$ip] = $deviceId;
            }
        }

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        foreach ($esiRows as $r) {
            $esi = (string) $r['esi'];
            $deviceId = (int) $r['device_id'];
            $row = &$rows[$esi];
            $row ??= [
                'esi' => $esi,
                'instances' => [],
                'sides' => [],
                'remote_pes' => [],
                'modes' => [],
                'df_ips' => [],
                'bdf_ips' => [],
                'remote_mac_count' => null,
                'seen_by' => [],
                'flags' => [],
            ];
            $row['seen_by'][] = $deviceId;
            if (! empty($r['instance'])) {
                $row['instances'][] = (string) $r['instance'];
            }
            if (! empty($r['mode'])) {
                $row['modes'][] = (string) $r['mode'];
            }
            if (! empty($r['df_ip'])) {
                $row['df_ips'][] = (string) $r['df_ip'];
            }
            if (! empty($r['bdf_ip'])) {
                $row['bdf_ips'][] = (string) $r['bdf_ip'];
            }
            if ($r['remote_mac_count'] !== null) {
                $row['remote_mac_count'] = max((int) $row['remote_mac_count'], (int) $r['remote_mac_count']);
            }
            $remote = json_decode((string) ($r['remote_vtep_ips'] ?? '[]'), true);
            foreach (is_array($remote) ? $remote : [] as $ip) {
                $row['remote_pes'][(string) $ip] = true;
            }
            if (! empty($r['local_ifname'])) {
                $aeName = preg_replace('/\.\d+$/', '', (string) $r['local_ifname']) ?? (string) $r['local_ifname'];
                $row['sides'][$deviceId] = [
                    'device_id' => $deviceId,
                    'vtep_ip' => $deviceNodes[$deviceId][0] ?? null,
                    'ifname' => (string) $r['local_ifname'],
                    'port_id' => $r['local_port_id'] === null ? null : (int) $r['local_port_id'],
                    'port' => null,
                    'mode' => $r['mode'] === null ? null : (string) $r['mode'],
                    'status' => $r['status'] === null ? null : (string) $r['status'],
                    'lag_status' => $r['lag_status'] === null ? null : (string) $r['lag_status'],
                    'is_df' => (bool) $r['is_df'],
                    'aliasing' => $r['aliasing'] === null ? null : (bool) $r['aliasing'],
                    'lacp_degraded' => $lacp[$deviceId][$aeName] ?? null,
                    'last_seen' => $r['last_seen'] ?? null,
                ];
            }
            unset($row);
        }

        foreach ($rows as &$row) {
            $row['instances'] = array_values(array_unique($row['instances']));
            $row['modes'] = array_values(array_unique($row['modes']));
            $row['mode'] = $row['modes'][0] ?? null;
            $row['df_ips'] = array_values(array_unique($row['df_ips']));
            $row['bdf_ips'] = array_values(array_unique($row['bdf_ips']));
            $row['df_ip'] = $row['df_ips'][0] ?? null;
            $row['bdf_ip'] = $row['bdf_ips'][0] ?? null;

            // every PE address: monitored sides first, then remote PEs that are not one of them
            $sideAddresses = [];
            foreach ($row['sides'] as $side) {
                foreach ($deviceNodes[$side['device_id']] ?? [] as $ip) {
                    $sideAddresses[$ip] = true;
                }
            }
            $remoteOnly = [];
            foreach (array_keys($row['remote_pes']) as $ip) {
                if (isset($sideAddresses[$ip])) {
                    continue;
                }
                $target = $addressDevice[$ip] ?? null;
                if ($target !== null && isset($row['sides'][$target])) {
                    continue;
                }
                $remoteOnly[] = $ip;
            }
            $row['remote_pes'] = $remoteOnly;
            $row['pe_count'] = count($row['sides']) + count($remoteOnly);

            $flags = [];
            if ($row['pe_count'] < 2) {
                $flags[] = 'single-pe';
            }
            if (count($row['df_ips']) > 1) {
                $flags[] = 'df-disagree';
            }
            if (count(array_filter($row['sides'], fn ($s) => $s['is_df'])) > 1) {
                $flags[] = 'df-both';
            }
            if (count($row['modes']) > 1) {
                $flags[] = 'mode-differs';
            }
            foreach ($row['sides'] as $side) {
                if ($side['lag_status'] !== null && ! str_starts_with($side['lag_status'], 'Up')) {
                    $flags[] = 'lag-down';
                }
                if ($side['status'] !== null && ! str_starts_with($side['status'], 'Resolved')) {
                    $flags[] = 'unresolved';
                }
                if (($side['lacp_degraded'] ?? 0) > 0) {
                    $flags[] = 'lacp-degraded';
                }
            }
            $aliasing = array_filter(array_column($row['sides'], 'aliasing'), fn ($a) => $a !== null);
            if (in_array(false, $aliasing, true)) {
                $flags[] = 'no-aliasing';
            }
            $row['flags'] = array_values(array_unique($flags));
            unset($row);
        }
        uksort($rows, 'strcmp');

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, string $q, bool $issuesOnly, FabricNodes $nodes): array
    {
        $needle = mb_strtolower(trim($q));

        return array_values(array_filter($rows, function ($row) use ($needle, $issuesOnly, $nodes) {
            if ($issuesOnly && $row['flags'] === []) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = [$row['esi'], ...$row['instances'], ...$row['remote_pes']];
            foreach ($row['sides'] as $side) {
                $haystack[] = $side['ifname'];
                $haystack[] = $nodes->name($side['vtep_ip'] ?? '');
            }
            foreach ($row['remote_pes'] as $ip) {
                $haystack[] = $nodes->name($ip);
            }
            foreach ($haystack as $text) {
                if (str_contains(mb_strtolower((string) $text), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
