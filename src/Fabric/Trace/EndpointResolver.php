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
                'is_duplicate' => (bool) $row['is_duplicate'],
                'moves' => (int) $row['moves'],
            ];
        }
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

        return self::select(MacSearch::classify($query), ['plugin' => $plugin, 'fdb' => $fdb, 'arp' => $arp], $members, $search['opted_in']);
    }

    /**
     * The pure half: rank what the three sources returned, and say what was consulted.
     *
     * @param  array{kind: string|null, value: string, q: string}  $query
     * @param  array{plugin: list<array<string, mixed>>, fdb: list<array<string, mixed>>, arp: list<array<string, mixed>>}  $rows
     * @param  array<int, array{address: string, name: string}>  $members
     * @param  array{devices: int, candidates: int, rows: int, global: bool, fabric: bool}|array<string, mixed>  $macCollection
     * @return array{candidates: list<Endpoint>, consulted: list<array{source: string, rows: int, note: string|null}>, notes: list<string>}
     */
    public static function select(array $query, array $rows, array $members, array $macCollection = []): array
    {
        $candidates = [];
        foreach ($rows['plugin'] as $row) {
            $member = $members[$row['device_id']] ?? null;
            $source = match ($row['source_type']) {
                'local' => Endpoint::SOURCE_EVPN_LOCAL,
                'esi' => Endpoint::SOURCE_EVPN_ESI,
                default => Endpoint::SOURCE_EVPN_REMOTE,
            };
            $candidates[] = new Endpoint(
                query: $query['q'],
                kind: $query['kind'],
                mac: $row['mac'],
                ips: array_values(array_map(strval(...), (array) ($row['ips'] ?? []))),
                vni: $row['vni'],
                deviceId: $row['device_id'],
                address: $member['address'] ?? null,
                name: $member['name'] ?? null,
                ifname: $row['ifname'] === null ? null : (string) $row['ifname'],
                portId: $row['port_id'],
                esi: $row['esi'],
                source: $source,
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

        usort($candidates, fn (Endpoint $a, Endpoint $b) => [$a->rank(), $a->address ?? '', $a->ifname ?? ''] <=> [$b->rank(), $b->address ?? '', $b->ifname ?? '']);

        $consulted = [
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
