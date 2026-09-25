<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

/**
 * Which core ports one ESI-LAG's traffic graph is drawn from (design doc
 * `docs/EVPN-FABRIC-UI.md`, plan §11 E3). Pure: it takes the sides `EsiMatrix` already built
 * and returns port ids, nothing from SNMP and nothing from the datastore.
 *
 * The rule that matters is what is **not** in the list. The AE ports of the PEs sum to the
 * virtual LAG; adding the physical members under those AEs (`ports_stack.low_port_id`) would
 * count the same traffic a second time, so member ports are not an input here and cannot
 * appear in the output. A side whose AE has no `ports` row yet is named in prose rather than
 * guessed at, and an anycast gateway segment has no traffic of its own at all.
 */
final class EsiTrafficPorts
{
    /**
     * @param  list<array{device_id: int, ifname: ?string, port_id: ?int, esi: string}>  $sides
     * @return array{port_ids: list<int>, per_pe: array<int, int>, skipped: list<array{device_id: int, ifname: ?string}>, excluded: bool}
     */
    public static function select(array $sides): array
    {
        $portIds = [];
        $perPe = [];
        $skipped = [];
        $lagSides = 0;

        foreach ($sides as $side) {
            if (EsiKind::isGateway($side['esi'], $side['ifname'])) {
                continue;
            }
            $lagSides++;
            if ($side['port_id'] === null) {
                $skipped[] = ['device_id' => $side['device_id'], 'ifname' => $side['ifname']];

                continue;
            }
            $portIds[$side['port_id']] = true;
            $perPe[$side['device_id']] ??= $side['port_id'];
        }

        $ids = array_map(intval(...), array_keys($portIds));
        sort($ids);
        ksort($perPe);

        return [
            'port_ids' => $ids,
            'per_pe' => $perPe,
            'skipped' => $skipped,
            'excluded' => $lagSides === 0,
        ];
    }
}
