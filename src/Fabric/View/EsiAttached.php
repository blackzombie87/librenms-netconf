<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use SafferIt\LibrenmsNetconf\Fabric\EsiLinks;

/**
 * The devices hanging off an ESI-LAG, from core's own discovery rows (plan §11 E3). Pure: it takes the ESI sides `EsiMatrix` already
 * built, the `ports_stack` rows that name the physical members of those AEs, and the `links`
 * rows on either, and returns one record per far end.
 *
 * Three rules keep the picture honest. The plugin's own `evpn-esi` links are dropped first —
 * those describe a multihoming relation, not a cable, and drawing them would put the peer
 * leaf on the access side of the bracket. A far end that is itself a fabric member is
 * dropped, because it already has a card. And one server multihomed to both PEs is one
 * record with two attachments, not two servers: core keys a link by
 * `local_port_id|remote_hostname|remote_port`, so one PE can resolve the device while the
 * other stores only a hostname, and the two are merged on that hostname.
 */
final class EsiAttached
{
    /**
     * @param  list<array{esi: string, device_id: int, port_id: int}>  $lagSides  gateway segments already removed
     * @param  list<array{high_port_id: int, low_port_id: int}>  $stack  high = the aggregator, low = a member
     * @param  list<array{local_port_id: int, protocol: ?string, remote_device_id: int, remote_hostname: string, remote_port: string}>  $links
     * @param  array<int, true>  $fabricDeviceIds
     * @param  array<int, list<string>>  $deviceNames  device_id => hostname and display name
     * @return list<array{key: string, esi: string, label: string, device_id: int|null, anchor: string|null, attachments: list<array{device_id: int, port_id: int, remote_port: string}>}>
     */
    public static function select(array $lagSides, array $stack, array $links, array $fabricDeviceIds, array $deviceNames = []): array
    {
        // every port that belongs to an ESI-LAG side: the AE itself and its stack members
        /** @var array<int, list<array{esi: string, device_id: int}>> $owner */
        $owner = [];
        $aeOf = [];
        foreach ($lagSides as $side) {
            $owner[$side['port_id']][] = ['esi' => $side['esi'], 'device_id' => $side['device_id']];
            $aeOf[$side['port_id']] = true;
        }
        foreach ($stack as $row) {
            foreach ($owner[$row['high_port_id']] ?? [] as $side) {
                $owner[$row['low_port_id']][] = $side;
            }
        }

        /** @var array<string, array{key: string, esi: string, label: string, device_id: int|null, anchor: string|null, attachments: list<array{device_id: int, port_id: int, remote_port: string}>, names: array<string, true>}> $records */
        $records = [];
        /** @var array<string, array<string, true>> $resolvedNames  esi => normalised hostname => true, for a device that resolved */
        $resolvedNames = [];
        $pending = [];

        foreach ($links as $link) {
            if (($link['protocol'] ?? null) === EsiLinks::PROTOCOL) {
                continue;
            }
            foreach ($owner[$link['local_port_id']] ?? [] as $side) {
                $remoteId = (int) $link['remote_device_id'];
                if ($remoteId > 0 && isset($fabricDeviceIds[$remoteId])) {
                    continue;   // the peer leaf already has a card of its own
                }
                $name = self::normalise($link['remote_hostname']);
                $attachment = ['device_id' => $side['device_id'], 'port_id' => (int) $link['local_port_id'], 'remote_port' => (string) $link['remote_port']];
                if ($remoteId > 0) {
                    $key = $side['esi'] . '|device:' . $remoteId;
                    $records[$key] ??= [
                        'key' => $key, 'esi' => $side['esi'],
                        'label' => ($deviceNames[$remoteId][0] ?? null) ?? ($link['remote_hostname'] !== '' ? (string) $link['remote_hostname'] : 'device ' . $remoteId),
                        'device_id' => $remoteId, 'anchor' => null, 'attachments' => [], 'names' => [],
                    ];
                    $records[$key]['attachments'][] = $attachment;
                    if ($name !== '') {
                        $records[$key]['names'][$name] = true;
                        $resolvedNames[$side['esi']][$name] = $key;
                    }
                    foreach ($deviceNames[$remoteId] ?? [] as $alias) {
                        $alias = self::normalise($alias);
                        if ($alias !== '') {
                            $records[$key]['names'][$alias] = true;
                            $resolvedNames[$side['esi']][$alias] = $key;
                        }
                    }

                    continue;
                }
                $pending[] = ['esi' => $side['esi'], 'name' => $name, 'hostname' => (string) $link['remote_hostname'], 'attachment' => $attachment];
            }
        }

        // an unresolved row merges into a resolved device only when the hostname matches; two
        // unresolved rows merge on that name alone, and a different name stays its own record
        foreach ($pending as $row) {
            $key = $resolvedNames[$row['esi']][$row['name']] ?? null;
            if ($key === null) {
                $key = $row['esi'] . '|name:' . $row['name'];
                $records[$key] ??= [
                    'key' => $key, 'esi' => $row['esi'], 'label' => $row['hostname'] !== '' ? $row['hostname'] : 'unknown',
                    'device_id' => null, 'anchor' => null, 'attachments' => [], 'names' => [$row['name'] => true],
                ];
            }
            $records[$key]['attachments'][] = $row['attachment'];
        }

        $out = [];
        foreach ($records as $record) {
            unset($record['names']);
            $record['anchor'] = null;
            $out[] = $record;
        }
        usort($out, fn ($a, $b) => [$a['esi'], $a['label']] <=> [$b['esi'], $b['label']]);

        return $out;
    }

    private static function normalise(string $hostname): string
    {
        return mb_strtolower(trim($hostname));
    }
}
