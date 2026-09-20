<p class="text-muted">
    {{ $tunnels['total'] }} VXLAN tunnels from the monitored members, {{ $tunnels['with_port'] }} with a core port (traffic and errors)@if ($tunnels['asymmetric'] > 0), <span class="text-warning">{{ $tunnels['asymmetric'] }} without a tunnel back</span>@endif.
    The kernel <code>vtep.N</code> IFL carries the per-tunnel counters; it only appears in core <code>ports</code> once SNMP discovery has picked it up (the Junos IF-MIB lists remote VTEP IFLs on some releases only).
</p>
@if ($tunnels['by_device'] === [])
    <p>No tunnels recorded yet.</p>
@endif
@foreach ($tunnels['by_device'] as $deviceId => $rows)
    <table class="table table-condensed table-hover" style="margin-bottom: 20px;">
        <thead>
            <tr><th colspan="9">@include('netconf::fabric.node', ['node' => $nodes->get($nodes->addressOf($deviceId) ?? '')]) <small class="text-muted">{{ count($rows) }} tunnels</small></th></tr>
            <tr>
                <th>Remote VTEP</th>
                <th>Interface</th>
                <th>Mode</th>
                <th class="text-right">Next-hop</th>
                <th class="text-right">MACs</th>
                <th class="text-right">In / out</th>
                <th class="text-right">Errors in / out</th>
                <th>Traffic</th>
                <th>Reverse</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $t)
                <tr class="{{ $t['reverse'] === false ? 'warning' : '' }}">
                    <td>@include('netconf::fabric.node', ['node' => $nodes->get($t['remote_vtep_ip'])])</td>
                    <td>
                        @if ($t['port']){!! \LibreNMS\Util\Url::portLink($t['port'], $t['ifname']) !!}@else<code>{{ $t['ifname'] }}</code>@endif
                        @if ($t['ri_ifname'] && $t['ri_ifname'] !== $t['ifname'])<br><small class="text-muted">{{ $t['ri_ifname'] }}</small>@endif
                    </td>
                    <td><small>{{ $t['mode'] }}</small></td>
                    <td class="text-right"><small>{{ $t['nh_id'] }}</small></td>
                    <td class="text-right">{{ $t['mac_count'] ?? '' }}</td>
                    <td class="text-right">
                        @if ($t['port'])
                            <small>{{ \LibreNMS\Util\Number::formatSi($t['port']->ifInOctets_rate * 8, 2, 3, 'bps') }} / {{ \LibreNMS\Util\Number::formatSi($t['port']->ifOutOctets_rate * 8, 2, 3, 'bps') }}</small>
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($t['port'])
                            <small class="{{ ($t['port']->ifInErrors_delta ?? 0) + ($t['port']->ifOutErrors_delta ?? 0) > 0 ? 'text-danger' : 'text-muted' }}">{{ $t['port']->ifInErrors_delta ?? 0 }} / {{ $t['port']->ifOutErrors_delta ?? 0 }}</small>
                        @endif
                    </td>
                    <td>
                        @if ($t['port'])
                            {!! \LibreNMS\Util\Url::graphPopup(['type' => 'port_bits', 'id' => $t['port']->port_id, 'from' => '-1d', 'width' => 120, 'height' => 24, 'legend' => 'no', 'bg' => 'ffffff00', 'popup_title' => $t['ifname'] . ' → ' . $nodes->name($t['remote_vtep_ip'])]) !!}
                        @endif
                    </td>
                    <td>
                        @if ($t['reverse'] === true)<span class="label label-success" title="the far end has a tunnel back">ok</span>
                        @elseif ($t['reverse'] === false)<span class="label label-warning" title="the far end is monitored but has no tunnel to this device">missing</span>
                        @else<small class="text-muted" title="far end not monitored">n/a</small>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach
