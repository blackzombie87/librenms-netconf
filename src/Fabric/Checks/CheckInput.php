<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Checks;

/**
 * Everything FabricChecks::evaluate() looks at, gathered once per fabric from the plugin and
 * core tables (FabricChecks::gather()) or built by hand in tests. Rows are the arrays the
 * view classes produce, so the checks judge exactly what the tabs show.
 */
final class CheckInput
{
    /**
     * @param  list<array<string, mixed>>  $neighbors  netconf_evpn_neighbor rows of the monitored members
     * @param  list<array<string, mixed>>  $sessions  OverlaySessions::forDevices() rows
     * @param  list<array{device_id: int, peer_ip: string, have: list<int>}>  $missingSessions  OverlaySessions::missing()
     * @param  list<array<string, mixed>>  $esis  EsiMatrix::build() rows
     * @param  list<array<string, mixed>>  $vnis  VniMatrix::build() rows
     * @param  array<int, list<array<string, mixed>>>  $tunnels  TunnelMatrix::build()['by_device']
     * @param  array<int, array<string, array{local_macs: int|null, dup_threshold: string|null, dup_window: string|null, dup_recovery: string|null}>>  $instances  device => instance => figures of the junos-evpn instance metric
     * @param  array<int, array<string, int>>  $dupMacs  device => instance => suppressed duplicate MACs (dup-mac-instance sensor)
     * @param  list<array<string, mixed>>  $macMoves  netconf_evpn_mac rows inside the mobility window with moves_recent >= the limit
     * @param  array<int, array{in_errors: int, out_errors: int, in_discards: int, out_discards: int}>  $portErrors  port_id => last-poll deltas of the tunnel ports
     * @param  array<int, string|null>  $versions  device => software version
     * @param  array<int, int>  $failing  device => consecutive collector failures (> 0 only)
     */
    public function __construct(
        public readonly array $neighbors = [],
        public readonly array $sessions = [],
        public readonly array $missingSessions = [],
        public readonly array $esis = [],
        public readonly array $vnis = [],
        public readonly array $tunnels = [],
        public readonly array $instances = [],
        public readonly array $dupMacs = [],
        public readonly array $macMoves = [],
        public readonly array $portErrors = [],
        public readonly array $versions = [],
        public readonly array $failing = [],
        public readonly int $macMovesLimit = 5,
    ) {
    }
}
