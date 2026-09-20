<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;

/**
 * VNIs tab (plan §7.4): one row per VNI over all monitored members of a fabric — the VLAN
 * tag per leaf (mismatch flagged), the leaves carrying it, their flood lists compared with
 * the other carriers (gap flagged), the anycast IRBs, and the remote MAC count.
 */
final class VniMatrix
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forFabric(FabricNodes $nodes): array
    {
        $deviceIds = $nodes->deviceIds() ?: [0];
        $vnis = DB::table(TableSchema::tableName('vni'))->whereIn('device_id', $deviceIds)->get()->map(fn ($r) => (array) $r)->all();
        $flood = DB::table(TableSchema::tableName('vni_vtep'))->whereIn('device_id', $deviceIds)->get(['device_id', 'vni', 'remote_vtep_ip'])->map(fn ($r) => (array) $r)->all();

        return self::build($vnis, $flood, $nodes->deviceNodes());
    }

    /**
     * @param  list<array<string, mixed>>  $vniRows  netconf_evpn_vni rows
     * @param  list<array<string, mixed>>  $floodRows  netconf_evpn_vni_vtep rows (device_id, vni, remote_vtep_ip)
     * @param  array<int, list<string>>  $deviceNodes  device_id => its addresses (member address first)
     * @return list<array<string, mixed>>
     */
    public static function build(array $vniRows, array $floodRows, array $deviceNodes): array
    {
        /** @var array<int, array<int, list<string>>> $flood device => vni => remote VTEPs */
        $flood = [];
        /** @var array<int, true> $collected devices whose remote table was fetched at all */
        $collected = [];
        foreach ($floodRows as $r) {
            $flood[(int) $r['device_id']][(int) $r['vni']][] = (string) $r['remote_vtep_ip'];
            $collected[(int) $r['device_id']] = true;
        }
        $addressDevice = [];
        foreach ($deviceNodes as $deviceId => $ips) {
            foreach ($ips as $ip) {
                $addressDevice[$ip] = $deviceId;
            }
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];
        foreach ($vniRows as $r) {
            $vni = (int) $r['vni'];
            $deviceId = (int) $r['device_id'];
            $row = &$rows[$vni];
            $row ??= [
                'vni' => $vni,
                'carriers' => [],
                'vlan_ids' => [],
                'vlan_names' => [],
                'instances' => [],
                'multicast_groups' => [],
                'irbs' => [],
                'remote_macs' => 0,
                'flood' => [],
                'gaps' => [],
                'stale' => [],
                'flags' => [],
            ];
            $row['carriers'][$deviceId] = ['device_id' => $deviceId, 'source_vtep' => $r['source_vtep'] ?? null, 'vlan_id' => $r['vlan_id'] === null ? null : (int) $r['vlan_id']];
            if ($r['vlan_id'] !== null) {
                $row['vlan_ids'][$deviceId] = (int) $r['vlan_id'];
            }
            if (! empty($r['vlan_name'])) {
                $row['vlan_names'][] = (string) $r['vlan_name'];
            }
            if (! empty($r['instance'])) {
                $row['instances'][] = (string) $r['instance'];
            }
            if (! empty($r['multicast_group']) && $r['multicast_group'] !== '0.0.0.0') {
                $row['multicast_groups'][] = (string) $r['multicast_group'];
            }
            if (! empty($r['irb_ifname'])) {
                $row['irbs'][$deviceId] = ['ifname' => (string) $r['irb_ifname'], 'status' => $r['irb_status'] === null ? null : (string) $r['irb_status']];
            }
            $row['remote_macs'] += (int) ($r['remote_macs'] ?? 0);
            $row['flood'][$deviceId] = array_values(array_unique($flood[$deviceId][$vni] ?? []));
            unset($row);
        }

        foreach ($rows as &$row) {
            $carrierIds = array_keys($row['carriers']);
            $row['vlan_names'] = array_values(array_unique($row['vlan_names']));
            $row['instances'] = array_values(array_unique($row['instances']));
            $row['multicast_groups'] = array_values(array_unique($row['multicast_groups']));
            $row['vlan_mismatch'] = count(array_unique($row['vlan_ids'])) > 1;
            $row['flood_peers'] = array_values(array_unique(array_merge(...array_values($row['flood']) ?: [[]])));

            // flood-list gaps between monitored carriers: A carries the VNI but B's flood list lacks A
            foreach ($carrierIds as $a) {
                if (! isset($collected[$a])) {
                    continue;   // no remote table from this leaf: nothing to compare
                }
                foreach ($carrierIds as $b) {
                    if ($a === $b) {
                        continue;
                    }
                    $bAddresses = $deviceNodes[$b] ?? [];
                    if ($bAddresses !== [] && array_intersect($bAddresses, $row['flood'][$a]) === []) {
                        $row['gaps'][] = ['device_id' => $a, 'missing' => $b];
                    }
                }
                // flood entries pointing at a monitored member that does not carry the VNI
                foreach ($row['flood'][$a] as $ip) {
                    $target = $addressDevice[$ip] ?? null;
                    if ($target !== null && ! isset($row['carriers'][$target])) {
                        $row['stale'][] = ['device_id' => $a, 'vtep_ip' => $ip, 'target' => $target];
                    }
                }
            }
            $row['orphan'] = [];
            foreach ($carrierIds as $a) {
                if (isset($collected[$a]) && $row['flood'][$a] === []) {
                    $row['orphan'][] = $a;
                }
            }
            $row['irb_down'] = array_keys(array_filter($row['irbs'], fn ($irb) => $irb['status'] !== null && strtolower($irb['status']) !== 'up'));
            $row['irb_partial'] = $row['irbs'] !== [] && count($row['irbs']) < count($carrierIds);   // legal (gateway pair vs. plain leaves), informational

            $flags = [];
            if ($row['vlan_mismatch']) {
                $flags[] = 'vlan-mismatch';
            }
            if ($row['gaps'] !== []) {
                $flags[] = 'flood-gap';
            }
            if ($row['stale'] !== []) {
                $flags[] = 'stale-flood';
            }
            if ($row['orphan'] !== []) {
                $flags[] = 'orphan';
            }
            if ($row['irb_down'] !== []) {
                $flags[] = 'irb-down';
            }
            $row['flags'] = $flags;
            unset($row);
        }
        ksort($rows);

        return array_values($rows);
    }

    /**
     * Case-insensitive filter on VNI, VLAN tag, VLAN name and instance.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, string $q, bool $issuesOnly): array
    {
        $q = trim($q);
        $needle = mb_strtolower($q);

        return array_values(array_filter($rows, function ($row) use ($needle, $q, $issuesOnly) {
            if ($issuesOnly && $row['flags'] === []) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            if ((string) $row['vni'] === $q || in_array((int) $q, $row['vlan_ids'], true)) {
                return true;
            }
            foreach (array_merge($row['vlan_names'], $row['instances']) as $text) {
                if (str_contains(mb_strtolower($text), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
