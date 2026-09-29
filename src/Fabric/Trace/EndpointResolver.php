<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\MacSearch;
use SafferIt\LibrenmsNetconf\Support\Mac;

/**
 * One query string to a ranked list of attachments (plan §12.5 / T4). Source ranking rather
 * than one required source, so the tracer is useful before the EVPN MAC database is
 * collected and better after it: a plugin MAC row on a fabric member first, then core's
 * bridge tables, then core ARP. A leaf that only learnt the MAC *from* the fabric is
 * corroboration and never an attachment — it proves the MAC exists, not where it lives.
 *
 * The resolver names every source it consulted, including the ones that were empty because
 * the MAC database is switched off on that device. On the first production fabric that was
 * the answer to nearly every "not found", and a trace that does not say so sends the
 * operator looking for a network fault that is a setting.
 */
final class EndpointResolver
{
    /**
     * @return array{candidates: list<Endpoint>, consulted: list<array{source: string, rows: int, note: string|null}>, notes: list<string>}
     */
    public static function resolve(string $query, FabricNodes $nodes): array
    {
        $search = MacSearch::run($query, $nodes->deviceIds());

        $plugin = [];
        foreach ($search['rows'] as $row) {
            $source = $row['source'];
            $plugin[] = [
                'device_id' => (int) $row['device_id'],
                'mac' => (string) $row['mac_hex'],
                'vni' => (int) $row['vni'],
                'ips' => $row['ips'],
                'source_type' => (string) ($source['type'] ?? ''),
                'source' => (string) ($source['text'] ?? ''),
                'ifname' => $source['port']?->ifName === null ? ($source['type'] === 'local' ? (string) $source['text'] : null) : (string) $source['port']->ifName,
                'port_id' => $source['port']?->port_id === null ? null : (int) $source['port']->port_id,
                'esi' => $source['type'] === 'esi' ? (string) $source['text'] : null,
                'peers' => self::peers($source),
                'is_duplicate' => (bool) $row['is_duplicate'],
                'moves' => (int) $row['moves'],
            ];
        }
        $macIp = array_map(fn ($r) => [
            'device_id' => (int) $r['device_id'],
            'mac' => (string) $r['mac_address'],
            'ip' => (string) $r['ip_address'],
            'vni' => $r['vni'] === null ? null : (int) $r['vni'],
            'bridge_domain' => (string) $r['bridge_domain'],
            'ifname' => $r['ifname'] === null ? null : (string) $r['ifname'],
            'port_id' => $r['port_id'] === null ? null : (int) $r['port_id'],
        ], $search['mac_ip'] ?? []);
        $fdb = array_map(fn ($r) => [
            'device_id' => (int) $r['device_id'],
            'mac' => (string) $r['mac_address'],
            'port_id' => $r['port_id'] === null ? null : (int) $r['port_id'],
            'ifname' => $r['ifName'] === null ? null : (string) $r['ifName'],
        ], $search['fdb']);
        $arp = array_map(fn ($r) => [
            'device_id' => (int) $r['device_id'],
            'mac' => (string) $r['mac_address'],
            'ip' => (string) $r['ipv4_address'],
            'port_id' => $r['port_id'] === null ? null : (int) $r['port_id'],
            'ifname' => $r['ifName'] ?? null,
        ], $search['arp']);

        $members = [];
        foreach ($nodes->deviceIds() as $deviceId) {
            $address = $nodes->addressOf($deviceId);
            if ($address !== null) {
                $members[$deviceId] = ['address' => $address, 'name' => $nodes->name($address)];
            }
        }

        return self::select(MacSearch::classify($query), ['plugin' => $plugin, 'mac_ip' => $macIp, 'fdb' => $fdb, 'arp' => $arp], $members, $search['opted_in']);
    }

    /**
     * One multihomed MAC row to the PEs that actually own the segment.
     *
     * This is where a MAC on an ESI-LAG lives, and it is **not** the leaf whose EVPN database
     * the row came from: every leaf in the fabric reports such a MAC with the Ethernet
     * Segment as its active source, so attributing it to the reporting leaf picks whichever
     * one happened to sort first. The segment's PEs are the `netconf_evpn_esi` rows that
     * carry a local LAG, and they also supply the access interface the reporting row has no
     * idea about (`ae36`, `ae48`).
     *
     * The DF comes first — it is the leg that forwards BUM — but both are returned, because
     * unicast may use either and the trace says so.
     *
     * @param  array{kind: string|null, value: string, q: string}  $query
     * @param  array<string, mixed>  $row
     * @param  array<int, array{address: string, name: string}>  $members
     * @param  list<string>  $ips
     * @return list<Endpoint>
     */
    private static function esiCandidates(array $query, array $row, array $members, array $ips): array
    {
        $reporter = $members[$row['device_id']]['name'] ?? ('device ' . $row['device_id']);
        $peers = $row['peers'] ?? [];
        usort($peers, fn (array $a, array $b) => [$a['is_df'] !== true, $a['device_id']] <=> [$b['is_df'] !== true, $b['device_id']]);

        $out = [];
        foreach ($peers as $peer) {
            $member = $members[$peer['device_id']] ?? null;
            if ($member === null) {
                continue;
            }
            $out[] = new Endpoint(
                query: $query['q'], kind: $query['kind'], mac: $row['mac'], ips: $ips, vni: $row['vni'],
                deviceId: $peer['device_id'], address: $member['address'], name: $member['name'],
                ifname: $peer['ifname'], portId: $peer['port_id'], esi: $row['esi'],
                source: Endpoint::SOURCE_EVPN_ESI,
                evidence: [sprintf(
                    'Ethernet segment %s in VNI %d: %s on %s%s (reported by %s)',
                    (string) $row['esi'], $row['vni'], $peer['ifname'], $member['name'],
                    $peer['is_df'] === true ? ', DF' : '', $reporter,
                )],
                isDuplicate: (bool) $row['is_duplicate'],
                moves: (int) $row['moves'],
                df: $peer['is_df'] === true,
            );
        }

        if ($out !== []) {
            return $out;
        }

        // the segment is not in the ESI table, or no monitored leaf has a LAG for it: the MAC
        // exists and nothing here says where, which is not the same as saying it is here
        $member = $members[$row['device_id']] ?? null;

        return [new Endpoint(
            query: $query['q'], kind: $query['kind'], mac: $row['mac'], ips: $ips, vni: $row['vni'],
            deviceId: $row['device_id'], address: $member['address'] ?? null, name: $member['name'] ?? null,
            ifname: null, portId: null, esi: $row['esi'],
            source: Endpoint::SOURCE_EVPN_ESI_UNKNOWN,
            evidence: [sprintf(
                'Ethernet segment %s in VNI %d, reported by %s: no monitored PE has a LAG for it, so where it attaches is unknown',
                (string) $row['esi'], $row['vni'], $reporter,
            )],
            isDuplicate: (bool) $row['is_duplicate'],
            moves: (int) $row['moves'],
        )];
    }

    /**
     * The PEs of an Ethernet Segment: the leaves that report a local LAG for it, which is
     * where a multihomed MAC actually hangs. A PE the plugin does not monitor, or one with
     * no local LAG for the segment, is not one of them.
     *
     * @param  array<string, mixed>  $source  MacSearch's resolved source
     * @return list<array{device_id: int, ifname: string, port_id: int|null, is_df: bool|null}>
     */
    private static function peers(array $source): array
    {
        if (($source['type'] ?? null) !== 'esi') {
            return [];
        }
        $out = [];
        foreach ((array) ($source['peers'] ?? []) as $key => $peer) {
            if (! is_int($key) || ($peer['ifname'] ?? null) === null) {
                continue;
            }
            $out[] = [
                'device_id' => $key,
                'ifname' => (string) $peer['ifname'],
                'port_id' => ($peer['port']->port_id ?? null) === null ? null : (int) $peer['port']->port_id,
                'is_df' => $peer['is_df'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The pure half: rank what the three sources returned, and say what was consulted.
     *
     * @param  array{kind: string|null, value: string, q: string}  $query
     * @param  array{plugin: list<array<string, mixed>>, mac_ip?: list<array<string, mixed>>, fdb: list<array<string, mixed>>, arp: list<array<string, mixed>>}  $rows
     * @param  array<int, array{address: string, name: string}>  $members
     * @param  array{devices: int, candidates: int, rows: int, global: bool, fabric: bool}|array<string, mixed>  $macCollection
     * @return array{candidates: list<Endpoint>, consulted: list<array{source: string, rows: int, note: string|null}>, notes: list<string>}
     */
    public static function select(array $query, array $rows, array $members, array $macCollection = []): array
    {
        $candidates = [];
        $seen = [];

        // The device's own IP/MAC table first. It is the only source that states all four of
        // address, MAC, bridge domain and access interface in one row, so it needs no
        // inference at all — and on a multihomed segment each PE reports its own leg, which
        // is exactly the answer the ESI detour has to reconstruct.
        foreach ($rows['mac_ip'] ?? [] as $row) {
            $member = $members[$row['device_id']] ?? null;
            $candidates[] = new Endpoint(
                query: $query['q'], kind: $query['kind'], mac: (string) $row['mac'], ips: [(string) $row['ip']],
                vni: $row['vni'], deviceId: $row['device_id'],
                address: $member['address'] ?? null, name: $member['name'] ?? null,
                ifname: $row['ifname'], portId: $row['port_id'], esi: null,
                source: Endpoint::SOURCE_EVPN_MAC_IP,
                evidence: [sprintf(
                    'IP/MAC table on %s: %s is %s in %s%s, on %s',
                    $member['name'] ?? ('device ' . $row['device_id']),
                    (string) $row['ip'], Mac::readable((string) $row['mac']), (string) $row['bridge_domain'],
                    $row['vni'] === null ? ' (VNI not polled yet)' : sprintf(' (VNI %d)', $row['vni']),
                    $row['ifname'] ?? 'an unnamed interface',
                )],
            );
        }

        foreach ($rows['plugin'] as $row) {
            $member = $members[$row['device_id']] ?? null;
            $ips = array_values(array_map(strval(...), (array) ($row['ips'] ?? [])));

            if ($row['source_type'] === 'esi') {
                foreach (self::esiCandidates($query, $row, $members, $ips) as $candidate) {
                    // one segment is reported by every leaf in the fabric, so the same PE
                    // arrives once per reporting leaf: keep it once
                    $key = $candidate->source . '|' . $candidate->mac . '|' . $candidate->vni . '|' . $candidate->address . '|' . $candidate->ifname;
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $candidates[] = $candidate;
                    }
                }

                continue;
            }

            $candidates[] = new Endpoint(
                query: $query['q'],
                kind: $query['kind'],
                mac: $row['mac'],
                ips: $ips,
                vni: $row['vni'],
                deviceId: $row['device_id'],
                address: $member['address'] ?? null,
                name: $member['name'] ?? null,
                ifname: $row['ifname'] === null ? null : (string) $row['ifname'],
                portId: $row['port_id'],
                esi: $row['esi'],
                source: $row['source_type'] === 'local' ? Endpoint::SOURCE_EVPN_LOCAL : Endpoint::SOURCE_EVPN_REMOTE,
                evidence: [sprintf(
                    'EVPN database on %s: %s %s in VNI %d',
                    $member['name'] ?? ('device ' . $row['device_id']),
                    $row['source_type'] === 'remote' ? 'learnt from' : 'local on',
                    $row['source'],
                    $row['vni'],
                )],
                isDuplicate: (bool) $row['is_duplicate'],
                moves: (int) $row['moves'],
            );
        }

        foreach ($rows['fdb'] as $row) {
            $member = $members[$row['device_id']] ?? null;
            $ifname = $row['ifname'] === null ? null : (string) $row['ifname'];
            if ($ifname !== null && preg_match('/^vtep\./i', $ifname) === 1) {
                continue;   // the MAC arrived over a tunnel: that is not an access port
            }
            $candidates[] = new Endpoint(
                query: $query['q'], kind: $query['kind'], mac: (string) $row['mac'], ips: [], vni: null,
                deviceId: $row['device_id'], address: $member['address'] ?? null, name: $member['name'] ?? null,
                ifname: $ifname, portId: $row['port_id'], esi: null, source: Endpoint::SOURCE_FDB,
                evidence: [sprintf('core bridge table on %s: %s', $member['name'] ?? ('device ' . $row['device_id']), $ifname ?? 'unknown port')],
            );
        }

        foreach ($rows['arp'] as $row) {
            $member = $members[$row['device_id']] ?? null;
            $candidates[] = new Endpoint(
                query: $query['q'], kind: $query['kind'], mac: (string) $row['mac'], ips: [(string) $row['ip']], vni: null,
                deviceId: $row['device_id'], address: $member['address'] ?? null, name: $member['name'] ?? null,
                ifname: $row['ifname'] === null ? null : (string) $row['ifname'], portId: $row['port_id'], esi: null,
                source: Endpoint::SOURCE_ARP,
                evidence: [sprintf('core ARP on %s: %s is %s', $member['name'] ?? ('device ' . $row['device_id']), (string) $row['ip'], Mac::readable((string) $row['mac']))],
            );
        }

        // the DF of a segment first among equally ranked candidates: both legs of an
        // all-active ESI-LAG are real attachments and unicast may use either, so the order
        // has to come from the fabric rather than from whichever address sorts lower
        usort($candidates, fn (Endpoint $a, Endpoint $b) => [$a->rank(), ! $a->df, $a->address ?? '', $a->ifname ?? ''] <=> [$b->rank(), ! $b->df, $b->address ?? '', $b->ifname ?? '']);

        $consulted = [
            ['source' => 'IP/MAC table (netconf_evpn_mac_ip)', 'rows' => count($rows['mac_ip'] ?? []), 'note' => self::macNote($macCollection)],
            ['source' => 'EVPN MAC database (netconf_evpn_mac)', 'rows' => count($rows['plugin']), 'note' => self::macNote($macCollection)],
            ['source' => 'core bridge tables (ports_fdb)', 'rows' => count($rows['fdb']), 'note' => null],
            ['source' => 'core ARP (ipv4_mac)', 'rows' => count($rows['arp']), 'note' => null],
        ];

        $notes = [];
        if ($query['kind'] === null) {
            $notes[] = 'Type a MAC address, an IP address or a VNI.';
        } elseif ($candidates === []) {
            $notes[] = 'Not found in any source.';
        } elseif (array_filter($candidates, fn (Endpoint $e) => $e->isAttachment()) === []) {
            $notes[] = 'Learnt from the fabric, but no leaf claims it locally: no access port anywhere.';
        }

        return ['candidates' => $candidates, 'consulted' => $consulted, 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $macCollection  MacSearch::optedIn()
     */
    private static function macNote(array $macCollection): ?string
    {
        if ($macCollection === []) {
            return null;
        }
        if (! ($macCollection['fabric'] ?? false)) {
            return 'the EVPN fabric view is off, so this table is never written';
        }
        $devices = (int) ($macCollection['devices'] ?? 0);
        $candidates = (int) ($macCollection['candidates'] ?? 0);
        if ($devices === 0) {
            return 'collection is off on every device here, so this table is empty';
        }

        return $devices < $candidates
            ? sprintf('collected on %d of %d devices here', $devices, $candidates)
            : null;
    }
}
