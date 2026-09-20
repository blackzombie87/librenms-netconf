<p class="text-muted">
    {{ $sessions_total }} EVPN overlay sessions seen from the monitored members
    @if ($sessions_down > 0)&middot; <span class="text-danger">{{ $sessions_down }} down</span>@endif
    @if ($sessions_missing > 0)&middot; <span class="text-warning">{{ $sessions_missing }} missing</span>@endif
    &middot; state and uptime from core BGP (SNMP) or the plugin's <code>show bgp summary</code> rows, EVPN route counts per neighbour from <code>show evpn instance extensive</code>.
    Only sessions of monitored members are known; a peer that other members have and one lacks is listed as <em>missing</em>.
</p>
@if ($sessions_by_device === [])
    <p>No overlay sessions recorded yet.</p>
@endif
@foreach ($sessions_by_device as $deviceId => $rows)
    @php($own = $nodes->addressOf($deviceId))
    <table class="table table-condensed table-hover" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th colspan="10">
                    @include('netconf::fabric.node', ['node' => $nodes->get($own ?? ''), 'show_ip' => true])
                    <small class="text-muted">{{ count($rows) }} peers</small>
                    @if ($nodes->device($own ?? ''))
                        <small><a href="{{ \LibreNMS\Util\Url::deviceUrl($nodes->device($own), ['tab' => 'routing', 'proto' => 'bgp']) }}">core BGP page</a></small>
                    @endif
                </th>
            </tr>
            <tr>
                <th>Peer</th>
                <th>AS</th>
                <th>State</th>
                <th>Uptime</th>
                <th class="text-right">Flaps</th>
                <th class="text-right" title="bgp.evpn.0 prefixes active / received / accepted">EVPN RIB act / rcv / acc</th>
                <th class="text-right" title="EVPN type-2 MAC routes from this neighbour">MAC</th>
                <th class="text-right" title="type-2 MAC+IP routes">MAC-IP</th>
                <th class="text-right" title="type-1 EAD / type-3 IMET / type-4 ES routes">EAD / IMET / ES</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $s)
                @php($peer = $nodes->get($s['peer_ip']))
                <tr class="{{ ($s['missing'] ?? false) ? 'warning' : ($s['state'] !== null && ! $s['up'] ? 'danger' : '') }}">
                    <td>
                        @include('netconf::fabric.node', ['node' => $peer])
                        @if ($peer['role'] !== 'unknown')<span class="label label-default">{{ $peer['role'] }}</span>@endif
                        @if ($s['description'] && $s['description'] !== $peer['name'])<br><small class="text-muted">{{ $s['description'] }}</small>@endif
                    </td>
                    @if ($s['missing'] ?? false)
                        <td colspan="8"><span class="text-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> no session; {{ count($s['have']) }} other member{{ count($s['have']) === 1 ? ' has' : 's have' }} one:
                            @foreach ($s['have'] as $otherId){{ $nodes->name($nodes->addressOf($otherId) ?? '') }}@if (! $loop->last), @endif @endforeach</span></td>
                        <td></td>
                    @else
                        <td>{{ $s['remote_as'] }}</td>
                        <td>
                            @if ($s['state'] === null)
                                <span class="text-muted" title="no BGP row for this peer; the neighbour is known from the EVPN instance only">n/a</span>
                            @elseif ($s['up'])
                                <span class="label label-success">{{ $s['state'] }}</span>
                            @else
                                <span class="label label-danger">{{ $s['state'] }}</span>
                            @endif
                        </td>
                        <td><small>{{ $s['uptime'] ? \LibreNMS\Util\Time::formatInterval($s['uptime'], true) : '' }}</small></td>
                        <td class="text-right">{{ $s['flaps'] }}</td>
                        <td class="text-right"><small>@if ($s['rib']){{ $s['rib']['active'] }} / {{ $s['rib']['received'] }} / {{ $s['rib']['accepted'] }}@endif</small></td>
                        <td class="text-right">{{ $s['routes']['mac'] ?? '' }}</td>
                        <td class="text-right">{{ $s['routes']['mac_ip'] ?? '' }}</td>
                        <td class="text-right"><small>@if ($s['routes']){{ $s['routes']['ead'] }} / {{ $s['routes']['imet'] }} / {{ $s['routes']['es'] }}@endif</small></td>
                        <td><small class="text-muted">{{ implode(', ', $s['source']) }}</small></td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach
