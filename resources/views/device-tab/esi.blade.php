{{-- ESI-LAG section (plan §3.8): every ESI of the device with its peers, resolved through the
     EVPN fabric tables. The same links are the device's EVPN-ESI neighbours. --}}
<div class="panel panel-default">
    <div class="panel-heading"><i class="fa fa-link fa-fw" aria-hidden="true"></i> <strong>EVPN multihoming</strong>
        <small class="text-muted">{{ count($esi_rows) }} ESI-LAGs, peers resolved through the EVPN fabric tables; also listed as <code>EVPN-ESI</code> neighbours in the Neighbours tab</small>
    </div>
    @if ($esi_rows === [])
        <div class="panel-body text-muted">No ESI-LAGs stored for this device. The fabric definition collects them on the next poll of a fabric member.</div>
    @else
    <div class="tw:overflow-x-auto">
    <table class="table table-condensed table-striped tw:mb-0">
        <thead>
            <tr>
                <th>Local LAG</th>
                <th>Peer</th>
                <th>Peer LAG</th>
                <th>Mode</th>
                <th>DF</th>
                <th>Status</th>
                <th class="text-right">Remote MACs</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($esi_rows as $row)
                <tr>
                    <td title="ESI {{ $row['esi'] }}@if ($row['instance']), instance {{ $row['instance'] }}@endif">
                        @if ($row['local_port']){!! \LibreNMS\Util\Url::portLink($row['local_port'], $row['local_ifname']) !!}@else{{ $row['local_ifname'] }}@endif
                    </td>
                    <td>
                        @forelse ($row['peers'] as $peer)
                            @if ($peer['device']){!! \LibreNMS\Util\Url::deviceLink($peer['device']) !!}@else<span class="text-muted" title="VTEP {{ $peer['vtep_ip'] }}, not monitored by the plugin">{{ $peer['name'] }}</span>@endif
                            @if (! $loop->last)<br>@endif
                        @empty
                            <span class="label label-danger" title="no remote PE advertises this ESI">no peer</span>
                        @endforelse
                    </td>
                    <td>
                        @foreach ($row['peers'] as $peer)
                            @if ($peer['port']){!! \LibreNMS\Util\Url::portLink($peer['port'], $peer['ifname']) !!}@elseif ($peer['ifname']){{ $peer['ifname'] }}@else<span class="text-muted">&mdash;</span>@endif
                            @if ($peer['lag_status'] && $peer['lag_status'] !== $row['lag_status'])<small class="text-warning" title="peer's LAG status">({{ $peer['lag_status'] }})</small>@endif
                            @if (! $loop->last)<br>@endif
                        @endforeach
                    </td>
                    <td><small>{{ $row['mode'] }}@if ($row['aliasing'] === false) <span class="text-warning" title="aliasing disabled">no aliasing</span>@endif</small></td>
                    <td>
                        @if ($row['is_df'])
                            <span class="label label-primary" title="this device is the designated forwarder">DF</span>
                        @elseif ($row['is_bdf'])
                            <span class="label label-default" title="this device is the backup designated forwarder">BDF</span>
                        @endif
                        @foreach ($row['peers'] as $peer)
                            @if ($peer['df'])<small title="designated forwarder">DF: {{ $peer['device']?->displayName() ?? $peer['name'] }}</small>@endif
                        @endforeach
                        @if (! $row['is_df'] && $row['df_ip'] === null && $row['peers'] !== [])
                            <small class="text-warning">not elected</small>
                        @endif
                    </td>
                    <td>
                        @php($lag = (string) $row['lag_status'])
                        @if (str_starts_with($lag, 'Up'))<span class="label label-success">{{ $lag }}</span>
                        @elseif ($lag !== '')<span class="label label-danger">{{ $lag }}</span>
                        @endif
                        <small class="text-muted">{{ $row['status'] }}</small>
                    </td>
                    <td class="text-right">{{ $row['remote_mac_count'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>
