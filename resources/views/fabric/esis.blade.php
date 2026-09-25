@include('netconf::fabric.filter', ['placeholder' => 'ESI, interface, device, instance', 'total' => $esi_total, 'shown' => count($esis), 'issues' => $esi_issues])
<p class="text-muted">
    One row per Ethernet segment. Monitored PEs show their ESI-LAG, LAG state and DF role; PEs only known by address come from the remote-PE list of the others.
    The same ESI on two leaves is the multihoming pair (type-1 auto-derived ESIs encode the shared LACP system-id). Peers are also core <code>EVPN-ESI</code> neighbours on the device pages.
</p>
<table class="table table-condensed table-hover">
    <thead>
        <tr>
            <th>ESI</th>
            <th>PEs</th>
            <th>Mode</th>
            <th>DF / BDF</th>
            <th>Aliasing</th>
            <th>LACP</th>
            <th class="text-right">Remote MACs</th>
            <th>Issues</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($esis as $e)
            @php($severe = array_intersect($e['flags'], ['single-pe', 'df-disagree', 'df-both', 'mode-differs', 'lag-down', 'unresolved']) !== [])
            <tr class="{{ $severe ? 'danger' : ($e['flags'] !== [] ? 'warning' : '') }}">
                <td><code>{{ $e['esi'] }}</code>@if ($e['instances'] !== [])<br><small class="text-muted">{{ implode(', ', $e['instances']) }}</small>@endif</td>
                <td>
                    @foreach ($e['sides'] as $side)
                        @include('netconf::fabric.node', ['node' => $nodes->get($side['vtep_ip'] ?? ''), 'show_ip' => false])
                        @if ($side['port']){!! \LibreNMS\Util\Url::portLink($side['port'], $side['ifname']) !!}@else<code>{{ $side['ifname'] }}</code>@endif
                        @php($lag = (string) $side['lag_status'])
                        @if ($lag !== '')<span class="label label-{{ str_starts_with($lag, 'Up') ? 'success' : 'danger' }}">{{ $lag }}</span>@endif
                        @if ($side['status'] && ! str_starts_with($side['status'], 'Resolved'))<span class="label label-danger">{{ $side['status'] }}</span>@endif
                        <br>
                    @endforeach
                    @foreach ($e['remote_pes'] as $ip)
                        @include('netconf::fabric.node', ['node' => $nodes->get($ip), 'show_ip' => false]) <small class="text-muted">remote PE</small><br>
                    @endforeach
                </td>
                <td><small>{{ implode(' / ', $e['modes']) }}</small></td>
                <td>
                    @if ($e['df_ip'])
                        <small>DF {{ $nodes->name($e['df_ip']) }}</small>
                        @if (count($e['df_ips']) > 1)<br><small class="text-danger">also named: @foreach (array_slice($e['df_ips'], 1) as $ip){{ $nodes->name($ip) }} @endforeach</small>@endif
                    @elseif ($e['sides'] !== [])
                        <small class="text-warning">not elected</small>
                    @endif
                    @if ($e['bdf_ip'])<br><small class="text-muted">BDF {{ $nodes->name($e['bdf_ip']) }}</small>@endif
                </td>
                <td>
                    @foreach ($e['sides'] as $side)
                        @if ($side['aliasing'] === false)<span class="label label-warning" title="{{ $nodes->name($side['vtep_ip'] ?? '') }}">off</span>
                        @elseif ($side['aliasing'] === true)<small class="text-muted">on</small>
                        @endif
                    @endforeach
                </td>
                <td>
                    @foreach ($e['sides'] as $side)
                        @if ($side['lacp_degraded'] !== null)
                            <small class="{{ $side['lacp_degraded'] > 0 ? 'text-danger' : 'text-muted' }}" title="{{ $nodes->name($side['vtep_ip'] ?? '') }} {{ $side['ifname'] }}">{{ $side['lacp_degraded'] > 0 ? $side['lacp_degraded'] . ' not distributing' : 'ok' }}</small><br>
                        @endif
                    @endforeach
                </td>
                <td class="text-right">{{ $e['remote_mac_count'] ?? '' }}</td>
                <td>@foreach ($e['flags'] as $flag)@include('netconf::fabric.flag', ['flag' => $flag]) @endforeach</td>
            </tr>
            @if (\SafferIt\LibrenmsNetconf\Fabric\View\EsiKind::isLag($e))
                <tr class="{{ $severe ? 'danger' : ($e['flags'] !== [] ? 'warning' : '') }}">
                    <td colspan="8" style="border-top: 0; padding-top: 0;">
                        <details class="esi-traffic">
                            <summary class="text-muted"><small>Traffic</small></summary>
                            <div style="margin-top: 6px;">@include('netconf::fabric.esi-traffic', ['row' => $e])</div>
                        </details>
                    </td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
