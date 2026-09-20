<?php

use SafferIt\LibrenmsNetconf\Fabric\View\EsiMatrix;

/**
 * Ethernet-segment aggregation over the monitored leaves (plan §7.4 ESI tab, §7.5 check 5).
 */
function esiRow(int $device, string $esi, array $extra = []): array
{
    return $extra + ['device_id' => $device, 'esi' => $esi, 'instance' => 'default-switch', 'local_ifname' => null, 'local_port_id' => null, 'mode' => 'all-active', 'status' => 'Resolved', 'lag_status' => 'Up/Forwarding', 'is_df' => 0, 'df_ip' => null, 'bdf_ip' => null, 'aliasing' => 1, 'remote_vtep_ips' => '[]', 'remote_mac_count' => null, 'last_seen' => null];
}

it('joins both sides of a multihoming pair and drops the sides from the remote PE list', function () {
    $esi = '01:00:10:00:11:00:00:00:02:00';
    $rows = EsiMatrix::build(
        [
            esiRow(1, $esi, ['local_ifname' => 'ae2.0', 'local_port_id' => 45, 'is_df' => 1, 'df_ip' => '192.0.2.11', 'bdf_ip' => '192.0.2.12', 'remote_vtep_ips' => '["192.0.2.12"]', 'remote_mac_count' => 40]),
            esiRow(2, $esi, ['local_ifname' => 'ae2.0', 'local_port_id' => 77, 'df_ip' => '192.0.2.11', 'bdf_ip' => '192.0.2.12', 'remote_vtep_ips' => '["192.0.2.11"]', 'remote_mac_count' => 41]),
            // a third leaf sees the segment remotely only
            esiRow(3, $esi, ['remote_vtep_ips' => '["192.0.2.11","192.0.2.12"]']),
        ],
        [1 => ['192.0.2.11'], 2 => ['192.0.2.12'], 3 => ['192.0.2.13']],
        [1 => ['ae2' => 0], 2 => ['ae2' => 1]],
    );

    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]['sides']))->toBe([1, 2])
        ->and($rows[0]['sides'][1]['is_df'])->toBeTrue()
        ->and($rows[0]['sides'][2]['lacp_degraded'])->toBe(1)
        ->and($rows[0]['remote_pes'])->toBe([])
        ->and($rows[0]['pe_count'])->toBe(2)
        ->and($rows[0]['df_ip'])->toBe('192.0.2.11')
        ->and($rows[0]['remote_mac_count'])->toBe(41)
        ->and($rows[0]['seen_by'])->toBe([1, 2, 3])
        ->and($rows[0]['flags'])->toBe(['lacp-degraded']);
});

it('flags single PE, DF disagreement, mode mismatch, LAG down, unresolved and aliasing off', function () {
    $nodes = [1 => ['192.0.2.11'], 2 => ['192.0.2.12']];
    $rows = EsiMatrix::build(
        [
            esiRow(1, '01:aa', ['local_ifname' => 'ae1.0', 'lag_status' => 'Down', 'status' => 'Unresolved']),
            esiRow(1, '01:bb', ['local_ifname' => 'ae3.0', 'df_ip' => '192.0.2.11', 'is_df' => 1, 'remote_vtep_ips' => '["192.0.2.12"]', 'mode' => 'all-active']),
            esiRow(2, '01:bb', ['local_ifname' => 'ae3.0', 'df_ip' => '192.0.2.12', 'is_df' => 1, 'remote_vtep_ips' => '["192.0.2.11"]', 'mode' => 'single-active', 'aliasing' => 0]),
            // unmonitored pair seen from leaf 1 only: two remote PEs, healthy
            esiRow(1, '01:cc', ['remote_vtep_ips' => '["192.0.2.61","192.0.2.62"]']),
        ],
        $nodes,
    );
    $byEsi = array_column($rows, null, 'esi');

    expect(array_keys($byEsi))->toBe(['01:aa', '01:bb', '01:cc'])
        ->and($byEsi['01:aa']['flags'])->toBe(['single-pe', 'lag-down', 'unresolved'])
        ->and($byEsi['01:bb']['flags'])->toBe(['df-disagree', 'df-both', 'mode-differs', 'no-aliasing'])
        ->and($byEsi['01:bb']['df_ips'])->toBe(['192.0.2.11', '192.0.2.12'])
        ->and($byEsi['01:cc']['sides'])->toBe([])
        ->and($byEsi['01:cc']['remote_pes'])->toBe(['192.0.2.61', '192.0.2.62'])
        ->and($byEsi['01:cc']['flags'])->toBe([]);
});
