<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Checks;

use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\MacMobility;
use SafferIt\LibrenmsNetconf\Fabric\View\DeviceStats;
use SafferIt\LibrenmsNetconf\Fabric\View\EsiMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;
use SafferIt\LibrenmsNetconf\Fabric\View\OverlaySessions;
use SafferIt\LibrenmsNetconf\Fabric\View\TunnelMatrix;
use SafferIt\LibrenmsNetconf\Fabric\View\VniMatrix;
use SafferIt\LibrenmsNetconf\NetconfSettings;
use SafferIt\LibrenmsNetconf\Support\Mac;

/**
 * The consistency checks of plan §7.5, run over one fabric at the end of every resolve. The
 * checks judge the same aggregation the tabs show (EsiMatrix, VniMatrix, OverlaySessions,
 * TunnelMatrix) plus the per-instance metric, the duplicate-MAC sensors, the MAC mobility
 * window and a few core tables. evaluate() is pure; gather() fills its input from the
 * database; run() stores the findings through IssueStore.
 */
final class FabricChecks
{
    /**
     * Check id => label, default severity, what it means (Checks tab legend and README).
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const CHECKS = [
        'neighbor-asymmetric' => ['Asymmetric neighbour', Issue::WARNING, 'a member lists another monitored member as EVPN neighbour, the other does not list it back'],
        'session-down' => ['EVPN session down', Issue::CRITICAL, 'an overlay BGP session of a member towards another member is not established'],
        'session-missing' => ['EVPN session missing', Issue::WARNING, 'a member lacks a session to a peer the other monitored members have'],
        'vni-flood-gap' => ['Flood-list gap', Issue::CRITICAL, 'a leaf carries a VNI but is missing from the flood list of another carrier'],
        'vni-stale-flood' => ['Stale flood entry', Issue::WARNING, 'a flood list points at a monitored member that does not carry the VNI'],
        'vni-orphan' => ['VNI without flood list', Issue::WARNING, 'a VNI on a leaf has no remote VTEP at all'],
        'vni-vlan-mismatch' => ['VLAN tag differs', Issue::INFO, 'the same VNI maps to different VLAN tags on different leaves (legal)'],
        'vni-irb-down' => ['IRB down', Issue::CRITICAL, 'the anycast IRB of a VNI is not up on a gateway'],
        'vni-irb-partial' => ['IRB not on every carrier', Issue::INFO, 'some carriers of a VNI have an IRB, others do not (legal for a gateway pair)'],
        'esi-single-pe' => ['ESI with one PE', Issue::CRITICAL, 'an Ethernet segment has only one PE: the multihoming peer is down or misconfigured'],
        'esi-df-disagree' => ['DF disagreement', Issue::CRITICAL, 'the PEs of a segment name different designated forwarders, or more than one claims the role'],
        'esi-mode-differs' => ['ESI mode differs', Issue::CRITICAL, 'all-active on one PE, single-active on the other'],
        'esi-lag-down' => ['ESI-LAG down', Issue::CRITICAL, 'the ESI-LAG is not up on a PE'],
        'esi-unresolved' => ['ESI unresolved', Issue::CRITICAL, 'the ESI is not resolved on a PE'],
        'esi-lacp-degraded' => ['LACP degraded', Issue::WARNING, 'LACP members of an ESI-LAG are not distributing'],
        'esi-no-aliasing' => ['Aliasing off', Issue::WARNING, 'aliasing is disabled on one PE of a segment'],
        'dup-mac' => ['Duplicate MACs', Issue::CRITICAL, 'MACs are suppressed by duplicate-MAC detection on a leaf'],
        'dup-mac-params' => ['Duplicate-MAC parameters differ', Issue::WARNING, 'threshold, window or recovery time of duplicate-MAC detection differ between leaves of one instance'],
        'mac-mobility' => ['MAC mobility', Issue::WARNING, 'a MAC changed its active source more often than the limit within one hour'],
        'route-count' => ['MAC routes missing', Issue::WARNING, 'a member receives no MAC routes from a neighbour that has local MACs in the same instance'],
        'version-skew' => ['Software version skew', Issue::INFO, 'the monitored members run different software versions'],
        'unknown-vtep' => ['Unknown VTEP', Issue::WARNING, 'a member address does not belong to a monitored device'],
        'tunnel-asymmetric' => ['Tunnel without reverse', Issue::WARNING, 'a member has a VXLAN tunnel to another member that has none back'],
        'tunnel-errors' => ['Tunnel errors', Issue::WARNING, 'the vtep.N port of a tunnel counted errors or discards in the last poll'],
        'member-not-polling' => ['Member not polling', Issue::WARNING, 'the NETCONF collection of a member is failing'],
    ];

    public function __construct(private readonly IssueStore $store = new IssueStore)
    {
    }

    /**
     * Run the checks for every fabric and store the findings.
     *
     * @return array{fabrics: int, issues: int, new: int, cleared: int}
     */
    public function run(?string $now = null): array
    {
        $now ??= now()->toDateTimeString();
        $totals = ['fabrics' => 0, 'issues' => 0, 'new' => 0, 'cleared' => 0];
        $fabricIds = DB::table(TableSchema::tableName('fabric'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($fabricIds as $fabricId) {
            $result = $this->runFabric($fabricId, $now);
            $totals['fabrics']++;
            $totals['issues'] += $result['total'];
            $totals['new'] += $result['new'];
            $totals['cleared'] += $result['cleared'];
        }
        $totals['cleared'] += $this->store->dropOthers($fabricIds);

        return $totals;
    }

    /**
     * @return array{total: int, new: int, cleared: int, changed: int}
     */
    public function runFabric(int $fabricId, ?string $now = null): array
    {
        $nodes = FabricNodes::forFabric($fabricId);
        $issues = self::evaluate($nodes, self::gather($nodes));

        return $this->store->sync($fabricId, $issues, $now ?? now()->toDateTimeString());
    }

    /**
     * Collect what the checks look at for the monitored members of a fabric.
     */
    public static function gather(FabricNodes $nodes): CheckInput
    {
        $deviceIds = $nodes->deviceIds();
        if ($deviceIds === []) {
            return new CheckInput;
        }
        $settings = NetconfSettings::effective();
        $limit = max(0, (int) ($settings['evpn_mac_moves'] ?? 5));

        $neighbors = DB::table(TableSchema::tableName('neighbor'))->whereIn('device_id', $deviceIds)->get(['device_id', 'instance', 'neighbor_ip', 'mac_routes', 'mac_ip_routes'])->map(fn ($r) => (array) $r)->all();
        $sessions = OverlaySessions::forDevices($deviceIds);
        $missing = OverlaySessions::missing($sessions, $nodes->deviceNodes());
        $esis = EsiMatrix::forFabric($nodes);
        $vnis = VniMatrix::forFabric($nodes);
        $tunnels = TunnelMatrix::build(
            DB::table(TableSchema::tableName('tunnel'))->whereIn('device_id', $deviceIds)->get()->map(fn ($r) => (array) $r)->all(),
            $nodes->deviceNodes(),
        )['by_device'];

        $instances = [];
        foreach (DeviceStats::instanceMetrics($deviceIds) as $deviceId => $rows) {
            foreach ($rows as $index => $row) {
                $values = $row['values'];
                $labels = $row['labels'];
                $instances[$deviceId][$index] = [
                    'local_macs' => isset($values['local_macs']) ? (int) $values['local_macs'] : null,
                    'dup_threshold' => isset($labels['dup_threshold']) ? (string) $labels['dup_threshold'] : null,
                    'dup_window' => isset($labels['dup_window']) ? (string) $labels['dup_window'] : null,
                    'dup_recovery' => isset($labels['dup_recovery']) ? (string) $labels['dup_recovery'] : null,
                ];
            }
        }

        $dupMacs = [];
        foreach (DB::table('sensors')->whereIn('device_id', $deviceIds)->where('sensor_type', 'like', 'netconf-%-dup-mac-instance')->where('sensor_current', '>', 0)->get(['device_id', 'sensor_index', 'sensor_current']) as $s) {
            $dupMacs[(int) $s->device_id][(string) $s->sensor_index] = (int) $s->sensor_current;
        }

        $macMoves = [];
        if ($limit > 0) {
            $windowStart = now()->subSeconds(MacMobility::WINDOW_SECONDS)->toDateTimeString();
            $macMoves = DB::table(TableSchema::tableName('mac'))->whereIn('device_id', $deviceIds)
                ->where('moves_recent', '>=', $limit)->where('moves_since', '>=', $windowStart)
                ->get(['device_id', 'vni', 'mac_address', 'instance', 'source_type', 'source', 'moves', 'moves_recent', 'moves_since'])->map(fn ($r) => (array) $r)->all();
        }

        $portIds = [];
        foreach ($tunnels as $rows) {
            foreach ($rows as $t) {
                if ($t['port_id'] !== null) {
                    $portIds[] = (int) $t['port_id'];
                }
            }
        }
        $portErrors = [];
        if ($portIds !== []) {
            foreach (DB::table('ports')->whereIn('port_id', array_unique($portIds))->get(['port_id', 'ifInErrors_delta', 'ifOutErrors_delta', 'ifInDiscards_delta', 'ifOutDiscards_delta']) as $p) {
                $portErrors[(int) $p->port_id] = [
                    'in_errors' => (int) $p->ifInErrors_delta,
                    'out_errors' => (int) $p->ifOutErrors_delta,
                    'in_discards' => (int) $p->ifInDiscards_delta,
                    'out_discards' => (int) $p->ifOutDiscards_delta,
                ];
            }
        }

        $versions = DB::table('devices')->whereIn('device_id', $deviceIds)->pluck('version', 'device_id')->map(fn ($v) => $v === null || $v === '' ? null : (string) $v)->all();
        $failing = DeviceStats::failing($deviceIds);

        return new CheckInput(
            neighbors: $neighbors,
            sessions: $sessions,
            missingSessions: $missing,
            esis: $esis,
            vnis: $vnis,
            tunnels: $tunnels,
            instances: $instances,
            dupMacs: $dupMacs,
            macMoves: $macMoves,
            portErrors: $portErrors,
            versions: $versions,
            failing: $failing,
            macMovesLimit: $limit,
        );
    }

    /**
     * The checks. Pure: names come from $nodes, everything else from $input.
     *
     * @return list<Issue>
     */
    public static function evaluate(FabricNodes $nodes, CheckInput $input): array
    {
        $issues = [];
        $name = fn (int $deviceId) => $nodes->name($nodes->addressOf($deviceId) ?? (string) $deviceId);
        $addressDevice = [];
        foreach ($nodes->deviceNodes() as $deviceId => $ips) {
            foreach ($ips as $ip) {
                $addressDevice[$ip] = $deviceId;
            }
        }
        $monitored = array_keys($nodes->deviceNodes());

        // 1. asymmetric neighbours between monitored members
        /** @var array<int, array<int, true>> $lists device => devices it lists */
        $lists = [];
        foreach ($input->neighbors as $n) {
            $target = $addressDevice[(string) $n['neighbor_ip']] ?? null;
            if ($target !== null && $target !== (int) $n['device_id']) {
                $lists[(int) $n['device_id']][$target] = true;
            }
        }
        foreach ($lists as $a => $targets) {
            foreach (array_keys($targets) as $b) {
                if (! isset($lists[$b][$a]) && in_array($b, $monitored, true)) {
                    $issues[] = new Issue('neighbor-asymmetric', Issue::WARNING, "$a>$b", sprintf('%s lists %s as EVPN neighbour, %s does not list %s', $name($a), $name($b), $name($b), $name($a)), [$a, $b]);
                }
            }
        }

        // 2. overlay sessions down towards a member, and sessions others have
        $memberAddresses = array_keys(array_filter($nodes->all(), fn ($n) => $n['member']));
        foreach ($input->sessions as $s) {
            if ($s['state'] === null || $s['up']) {
                continue;
            }
            $peer = (string) $s['peer_ip'];
            $peerDevice = $addressDevice[$peer] ?? null;
            if ($peerDevice === null && ! in_array($peer, $memberAddresses, true)) {
                continue;   // a session outside the fabric
            }
            $deviceId = (int) $s['device_id'];
            $devices = $peerDevice === null ? [$deviceId] : array_values(array_unique([$deviceId, $peerDevice]));
            $issues[] = new Issue('session-down', Issue::CRITICAL, "$deviceId/$peer", sprintf('EVPN session %s → %s is %s%s', $name($deviceId), $nodes->name($peer), $s['state'], $s['flaps'] ? sprintf(' (%d flaps)', $s['flaps']) : ''), $devices, ['state' => $s['state'], 'peer_ip' => $peer]);
        }
        foreach ($input->missingSessions as $m) {
            $deviceId = (int) $m['device_id'];
            $issues[] = new Issue('session-missing', Issue::WARNING, "$deviceId/{$m['peer_ip']}", sprintf('%s has no EVPN session to %s, which %s', $name($deviceId), $nodes->name($m['peer_ip']), self::listNames(array_map($name, $m['have'])) . (count($m['have']) === 1 ? ' has' : ' have')), [$deviceId], ['peer_ip' => $m['peer_ip'], 'have' => $m['have']]);
        }

        // 3./4./10. VNIs
        foreach ($input->vnis as $v) {
            $vni = (int) $v['vni'];
            foreach ($v['gaps'] as $gap) {
                $issues[] = new Issue('vni-flood-gap', Issue::CRITICAL, "$vni/{$gap['device_id']}>{$gap['missing']}", sprintf('VNI %d: flood list of %s lacks %s, which carries the VNI', $vni, $name((int) $gap['device_id']), $name((int) $gap['missing'])), [(int) $gap['device_id'], (int) $gap['missing']], ['vni' => $vni]);
            }
            foreach ($v['stale'] as $stale) {
                $issues[] = new Issue('vni-stale-flood', Issue::WARNING, "$vni/{$stale['device_id']}>{$stale['target']}", sprintf('VNI %d: %s floods to %s, which does not carry the VNI', $vni, $name((int) $stale['device_id']), $name((int) $stale['target'])), [(int) $stale['device_id'], (int) $stale['target']], ['vni' => $vni, 'vtep_ip' => $stale['vtep_ip']]);
            }
            foreach ($v['orphan'] as $deviceId) {
                $issues[] = new Issue('vni-orphan', Issue::WARNING, "$vni/$deviceId", sprintf('VNI %d has an empty flood list on %s', $vni, $name((int) $deviceId)), [(int) $deviceId], ['vni' => $vni]);
            }
            if ($v['vlan_mismatch']) {
                $parts = [];
                foreach ($v['vlan_ids'] as $deviceId => $tag) {
                    $parts[] = sprintf('%s: VLAN %d', $name((int) $deviceId), $tag);
                }
                $issues[] = new Issue('vni-vlan-mismatch', Issue::INFO, (string) $vni, sprintf('VNI %d maps to different VLAN tags: %s', $vni, implode(', ', $parts)), array_map('intval', array_keys($v['vlan_ids'])), ['vni' => $vni, 'vlan_ids' => $v['vlan_ids']]);
            }
            foreach ($v['irb_down'] as $deviceId) {
                $irb = $v['irbs'][$deviceId];
                $issues[] = new Issue('vni-irb-down', Issue::CRITICAL, "$vni/$deviceId", sprintf('IRB %s for VNI %d is %s on %s', $irb['ifname'], $vni, $irb['status'], $name((int) $deviceId)), [(int) $deviceId], ['vni' => $vni, 'ifname' => $irb['ifname']]);
            }
            if ($v['irb_partial']) {
                $with = array_map('intval', array_keys($v['irbs']));
                $without = array_values(array_diff(array_map('intval', array_keys($v['carriers'])), $with));
                $issues[] = new Issue('vni-irb-partial', Issue::INFO, (string) $vni, sprintf('VNI %d has an IRB on %s but not on %s', $vni, self::listNames(array_map($name, $with)), self::listNames(array_map($name, $without))), array_merge($with, $without), ['vni' => $vni]);
            }
        }

        // 5. Ethernet segments
        foreach ($input->esis as $e) {
            $esi = (string) $e['esi'];
            $sides = $e['sides'];
            $sideIds = array_map('intval', array_keys($sides));
            $sideText = fn (array $side) => sprintf('%s %s', $name((int) $side['device_id']), $side['ifname']);
            foreach ($e['flags'] as $flag) {
                switch ($flag) {
                    case 'single-pe':
                        if ($sides !== []) {
                            $issues[] = new Issue('esi-single-pe', Issue::CRITICAL, $esi, sprintf('ESI %s (%s) has no peer PE', $esi, implode(', ', array_map($sideText, $sides))), $sideIds, ['esi' => $esi]);
                        } elseif ($e['remote_pes'] !== []) {
                            $issues[] = new Issue('esi-single-pe', Issue::WARNING, $esi, sprintf('ESI %s is known from one remote PE only (%s), seen by %s', $esi, $nodes->name($e['remote_pes'][0]), self::listNames(array_map($name, $e['seen_by']))), array_map('intval', $e['seen_by']), ['esi' => $esi]);
                        }
                        break;
                    case 'df-disagree':
                    case 'df-both':
                        $dfs = $flag === 'df-both'
                            ? array_map(fn ($s) => $name((int) $s['device_id']), array_filter($sides, fn ($s) => $s['is_df']))
                            : array_map(fn ($ip) => $nodes->name($ip), $e['df_ips']);
                        $issues[] = new Issue('esi-df-disagree', Issue::CRITICAL, $esi, sprintf('ESI %s: %s', $esi, $flag === 'df-both' ? 'more than one PE claims the DF role: ' . self::listNames(array_values($dfs)) : 'the PEs name different designated forwarders: ' . self::listNames(array_values($dfs))), $sideIds, ['esi' => $esi, 'flag' => $flag]);
                        break;
                    case 'mode-differs':
                        $issues[] = new Issue('esi-mode-differs', Issue::CRITICAL, $esi, sprintf('ESI %s: mode differs between the PEs (%s)', $esi, implode(' / ', $e['modes'])), $sideIds, ['esi' => $esi, 'modes' => $e['modes']]);
                        break;
                    case 'lag-down':
                        foreach ($sides as $side) {
                            if ($side['lag_status'] !== null && ! str_starts_with($side['lag_status'], 'Up')) {
                                $issues[] = new Issue('esi-lag-down', Issue::CRITICAL, $esi . '/' . $side['device_id'], sprintf('ESI-LAG %s is %s (ESI %s)', $sideText($side), $side['lag_status'], $esi), [(int) $side['device_id']], ['esi' => $esi, 'ifname' => $side['ifname']]);
                            }
                        }
                        break;
                    case 'unresolved':
                        foreach ($sides as $side) {
                            if ($side['status'] !== null && ! str_starts_with($side['status'], 'Resolved')) {
                                $issues[] = new Issue('esi-unresolved', Issue::CRITICAL, $esi . '/' . $side['device_id'], sprintf('ESI %s is %s on %s', $esi, $side['status'], $sideText($side)), [(int) $side['device_id']], ['esi' => $esi, 'ifname' => $side['ifname']]);
                            }
                        }
                        break;
                    case 'lacp-degraded':
                        foreach ($sides as $side) {
                            if (($side['lacp_degraded'] ?? 0) > 0) {
                                $issues[] = new Issue('esi-lacp-degraded', Issue::WARNING, $esi . '/' . $side['device_id'], sprintf('%s: %d LACP member(s) not distributing (ESI %s)', $sideText($side), $side['lacp_degraded'], $esi), [(int) $side['device_id']], ['esi' => $esi, 'ifname' => $side['ifname']]);
                            }
                        }
                        break;
                    case 'no-aliasing':
                        foreach ($sides as $side) {
                            if ($side['aliasing'] === false) {
                                $issues[] = new Issue('esi-no-aliasing', Issue::WARNING, $esi . '/' . $side['device_id'], sprintf('aliasing is off on %s (ESI %s)', $sideText($side), $esi), [(int) $side['device_id']], ['esi' => $esi, 'ifname' => $side['ifname']]);
                            }
                        }
                        break;
                }
            }
        }

        // 6. duplicate MACs suppressed
        foreach ($input->dupMacs as $deviceId => $byInstance) {
            foreach ($byInstance as $instance => $count) {
                $issues[] = new Issue('dup-mac', Issue::CRITICAL, "$deviceId/$instance", sprintf('%d duplicate MAC%s suppressed in %s on %s', $count, $count === 1 ? '' : 's', $instance, $name((int) $deviceId)), [(int) $deviceId], ['instance' => $instance, 'count' => $count]);
            }
        }

        // 7. MAC mobility
        foreach ($input->macMoves as $m) {
            $deviceId = (int) $m['device_id'];
            $mac = Mac::readable((string) $m['mac_address']);
            $issues[] = new Issue('mac-mobility', Issue::WARNING, "{$m['vni']}/{$m['mac_address']}/$deviceId", sprintf('MAC %s in VNI %d moved %d times within an hour as seen by %s (now %s %s)', $mac, (int) $m['vni'], (int) $m['moves_recent'], $name($deviceId), $m['source_type'] ?? 'on', $m['source'] ?? '?'), [$deviceId], ['vni' => (int) $m['vni'], 'mac' => $mac, 'moves' => (int) $m['moves'], 'since' => $m['moves_since']]);
        }

        // 8. route-count sanity: A receives no MAC routes from B, B has local MACs in that instance
        foreach ($input->neighbors as $n) {
            $a = (int) $n['device_id'];
            $b = $addressDevice[(string) $n['neighbor_ip']] ?? null;
            if ($b === null || $b === $a) {
                continue;
            }
            $instance = (string) $n['instance'];
            $localMacs = $input->instances[$b][$instance]['local_macs'] ?? null;
            $received = (int) ($n['mac_routes'] ?? 0) + (int) ($n['mac_ip_routes'] ?? 0);
            if ($localMacs !== null && $localMacs > 0 && $received === 0) {
                $issues[] = new Issue('route-count', Issue::WARNING, "$a/$b/$instance", sprintf('%s receives no MAC routes from %s in %s, which has %d local MACs', $name($a), $name($b), $instance, $localMacs), [$a, $b], ['instance' => $instance, 'local_macs' => $localMacs]);
            }
        }

        // 9. duplicate-MAC detection parameters per instance
        /** @var array<string, array<string, list<int>>> $params instance => "t/w/r" => devices */
        $params = [];
        foreach ($input->instances as $deviceId => $byInstance) {
            foreach ($byInstance as $instance => $figures) {
                if ($figures['dup_threshold'] === null && $figures['dup_window'] === null && $figures['dup_recovery'] === null) {
                    continue;
                }
                $params[$instance][sprintf('%s/%s/%s', $figures['dup_threshold'] ?? '-', $figures['dup_window'] ?? '-', $figures['dup_recovery'] ?? '-')][] = (int) $deviceId;
            }
        }
        foreach ($params as $instance => $variants) {
            if (count($variants) < 2) {
                continue;
            }
            $parts = [];
            $devices = [];
            foreach ($variants as $variant => $ids) {
                $parts[] = sprintf('%s on %s', $variant, self::listNames(array_map($name, $ids)));
                $devices = array_merge($devices, $ids);
            }
            $issues[] = new Issue('dup-mac-params', Issue::WARNING, $instance, sprintf('duplicate-MAC detection (threshold/window/recovery) differs in %s: %s', $instance, implode('; ', $parts)), $devices, ['instance' => $instance]);
        }

        // 11. software version skew
        $byVersion = [];
        foreach ($input->versions as $deviceId => $version) {
            if ($version !== null) {
                $byVersion[$version][] = (int) $deviceId;
            }
        }
        if (count($byVersion) > 1) {
            ksort($byVersion);
            $parts = [];
            foreach ($byVersion as $version => $ids) {
                $parts[] = sprintf('%s (%s)', $version, self::listNames(array_map($name, $ids)));
            }
            $issues[] = new Issue('version-skew', Issue::INFO, 'fabric', 'software versions differ: ' . implode(', ', $parts), array_merge(...array_values($byVersion)), ['versions' => array_map('count', $byVersion)]);
        }

        // 12. unknown VTEPs
        foreach ($nodes->all() as $ip => $node) {
            if ($node['member'] && $node['device_id'] === null) {
                $issues[] = new Issue('unknown-vtep', Issue::WARNING, $ip, sprintf('VTEP %s%s is not a monitored device', $ip, $node['name'] !== $ip ? " ({$node['name']})" : ''), [], ['role' => $node['role']]);
            }
        }

        // 13. tunnels: no reverse tunnel, errors on the vtep.N port
        foreach ($input->tunnels as $rows) {
            foreach ($rows as $t) {
                $deviceId = (int) $t['device_id'];
                $remote = (string) $t['remote_vtep_ip'];
                if ($t['reverse'] === false && $t['remote_device_id'] !== null) {
                    $issues[] = new Issue('tunnel-asymmetric', Issue::WARNING, "$deviceId>{$t['remote_device_id']}", sprintf('%s has a VXLAN tunnel to %s, %s has none back', $name($deviceId), $name((int) $t['remote_device_id']), $name((int) $t['remote_device_id'])), [$deviceId, (int) $t['remote_device_id']], ['remote_vtep_ip' => $remote]);
                }
                $errors = $t['port_id'] === null ? null : ($input->portErrors[(int) $t['port_id']] ?? null);
                if ($errors !== null && array_sum($errors) > 0) {
                    $devices = $t['remote_device_id'] === null ? [$deviceId] : [$deviceId, (int) $t['remote_device_id']];
                    $issues[] = new Issue('tunnel-errors', Issue::WARNING, "$deviceId/$remote", sprintf('tunnel %s on %s to %s: %d / %d errors in / out, %d / %d discards in the last poll', $t['ifname'] ?? 'vtep', $name($deviceId), $nodes->name($remote), $errors['in_errors'], $errors['out_errors'], $errors['in_discards'], $errors['out_discards']), $devices, $errors + ['ifname' => $t['ifname']]);
                }
            }
        }

        // members whose collector fails: everything above about them may be stale
        foreach ($input->failing as $deviceId => $failures) {
            $issues[] = new Issue('member-not-polling', Issue::WARNING, (string) $deviceId, sprintf('NETCONF collection on %s is failing (%d consecutive failure%s), its EVPN data may be stale', $name((int) $deviceId), $failures, $failures === 1 ? '' : 's'), [(int) $deviceId], ['failures' => $failures]);
        }

        usort($issues, fn (Issue $a, Issue $b) => [Issue::rank($a->severity), $a->check, $a->subject] <=> [Issue::rank($b->severity), $b->check, $b->subject]);

        return $issues;
    }

    /**
     * @param  list<string>  $names
     */
    private static function listNames(array $names): string
    {
        $names = array_values(array_unique($names));
        usort($names, 'strnatcasecmp');

        return implode(', ', $names);
    }
}
