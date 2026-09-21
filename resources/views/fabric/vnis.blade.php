@include('netconf::fabric.filter', ['placeholder' => 'VNI, VLAN tag, VLAN name, instance', 'total' => $vni_total, 'shown' => $vni_shown, 'issues' => $vni_issues])
<p class="text-muted">
    One row per VNI over the monitored members. Flood lists come from <code>show mac-vrf forwarding vxlan-tunnel-end-point remote</code> (every poll), the VLAN tag from the remote MAC table (every third poll).
    Gaps can only be detected between monitored carriers; unknown VTEPs in a flood list are counted but not judged.
</p>
<table class="table table-condensed table-hover">
    <thead>
        <tr>
            <th class="text-right">VNI</th>
            <th>VLAN</th>
            <th>Instance</th>
            <th>Carried by</th>
            <th>Flood list</th>
            <th>IRB</th>
            <th class="text-right">Remote MACs</th>
            <th>Issues</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($vnis as $v)
            <tr class="{{ in_array('flood-gap', $v['flags'], true) || in_array('irb-down', $v['flags'], true) ? 'danger' : ($v['flags'] !== [] ? 'warning' : '') }}">
                <td class="text-right"><strong>{{ $v['vni'] }}</strong></td>
                <td>
                    @if ($v['vlan_mismatch'])
                        @foreach ($v['vlan_ids'] as $deviceId => $tag)<small>{{ $nodes->name($nodes->addressOf($deviceId) ?? '') }}: <strong>{{ $tag }}</strong></small><br>@endforeach
                    @else
                        {{ implode(', ', array_unique($v['vlan_ids'])) }}
                    @endif
                    @if ($v['vlan_names'] !== [])<small class="text-muted">{{ implode(', ', $v['vlan_names']) }}</small>@endif
                </td>
                <td><small>{{ implode(', ', $v['instances']) }}</small></td>
                <td>
                    @foreach ($v['carriers'] as $c)
                        @include('netconf::fabric.node', ['node' => $nodes->get($nodes->addressOf($c['device_id']) ?? ''), 'show_ip' => false, 'plain' => true])@if (! $loop->last), @endif
                    @endforeach
                    @if ($v['multicast_groups'] !== [])<br><small class="text-muted">mcast {{ implode(', ', $v['multicast_groups']) }}</small>@endif
                </td>
                <td>
                    @php($peerNames = array_map(fn ($ip) => $nodes->name($ip), $v['flood_peers']))
                    <span title="{{ implode(', ', $peerNames) }}">{{ count($v['flood_peers']) }} VTEP{{ count($v['flood_peers']) === 1 ? '' : 's' }}</span>
                    @foreach ($v['gaps'] as $gap)
                        <br><small class="text-danger">{{ $nodes->name($nodes->addressOf($gap['device_id']) ?? '') }} lacks {{ $nodes->name($nodes->addressOf($gap['missing']) ?? '') }}</small>
                    @endforeach
                    @foreach ($v['stale'] as $stale)
                        <br><small class="text-warning">{{ $nodes->name($nodes->addressOf($stale['device_id']) ?? '') }} floods to {{ $nodes->name($stale['vtep_ip']) }}, which does not carry it</small>
                    @endforeach
                    @foreach ($v['orphan'] as $deviceId)
                        <br><small class="text-warning">empty on {{ $nodes->name($nodes->addressOf($deviceId) ?? '') }}</small>
                    @endforeach
                </td>
                <td>
                    @foreach ($v['irbs'] as $deviceId => $irb)
                        <small>{{ $irb['ifname'] }} <span class="label label-{{ $irb['status'] === null || strtolower($irb['status']) === 'up' ? 'success' : 'danger' }}">{{ $irb['status'] ?? '?' }}</span> {{ $nodes->name($nodes->addressOf($deviceId) ?? '') }}</small><br>
                    @endforeach
                    @if ($v['irb_partial'])<small class="text-muted">not on every carrier</small>@endif
                </td>
                <td class="text-right">{{ $v['remote_macs'] ?: '' }}</td>
                <td>@foreach ($v['flags'] as $flag)@include('netconf::fabric.flag', ['flag' => $flag]) @endforeach</td>
            </tr>
        @endforeach
        @if ($vnis === [])
            <tr><td colspan="8" class="text-muted">No VNI matches the filter.</td></tr>
        @endif
    </tbody>
</table>
@include('netconf::fabric.pager', ['pager' => $vni_pager])
