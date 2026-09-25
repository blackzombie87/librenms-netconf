{{-- MAC search form + results: $q, $search (MacSearch::run()), $scope_note --}}
<form method="get" class="form-inline" style="margin-bottom: 10px;">
    <input type="text" name="q" class="form-control" placeholder="MAC (any notation or prefix), IP address or VNI" value="{{ $q }}" style="width: 360px;" autofocus>
    <button type="submit" class="btn btn-primary">Search</button>
    <span class="text-muted" style="margin-left: 10px;">{{ $scope_note }}</span>
</form>
<p class="text-muted">
    The EVPN MAC database (<code>show evpn database</code>, every third poll) is collected on
    {{ $search['opted_in']['devices'] }} of {{ $search['opted_in']['candidates'] }} device{{ $search['opted_in']['candidates'] === 1 ? '' : 's' }} here,
    {{ number_format($search['opted_in']['rows']) }} MAC rows.
    @if (! $search['opted_in']['fabric'])
        <span class="text-warning">The EVPN fabric view is switched off, so nothing is collected.</span>
    @elseif (! $search['opted_in']['global'])
        Collection is switched off globally on the plugin settings page; enable single devices on their NETCONF tab.
    @else
        It is on by default &mdash; switch it off globally on the plugin settings page, or per device on its NETCONF tab.
    @endif
    Core bridge tables (FDB) and ARP are searched as well.
</p>

@if ($search['kind'] !== null)
    <h4 style="margin-top: 20px;">EVPN database
        <small>{{ count($search['rows']) }}{{ $search['truncated'] ? '+' : '' }} rows for {{ $search['kind'] }} <code>{{ $search['value'] }}</code>@if ($search['truncated']), first {{ \SafferIt\LibrenmsNetconf\Fabric\View\MacSearch::LIMIT }} shown — narrow the search @endif</small>
    </h4>
    @if ($search['rows'] === [])
        <p class="text-muted">No EVPN database row matches @if ($search['opted_in']['devices'] === 0) — no device here collects the MAC database @endif.</p>
    @else
        <table class="table table-condensed table-hover">
            <thead>
                <tr>
                    <th>MAC</th>
                    <th class="text-right">VNI</th>
                    <th>IPs</th>
                    <th>Seen by</th>
                    <th>Active source</th>
                    <th>Since</th>
                    <th class="text-right">Moves</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($search['rows'] as $r)
                    <tr class="{{ $r['is_duplicate'] ? 'danger' : '' }}">
                        <td><a href="{{ route('netconf.evpn.mac', ['q' => $r['mac']]) }}"><code>{{ $r['mac'] }}</code></a></td>
                        <td class="text-right"><a href="{{ url()->current() }}?q={{ $r['vni'] }}">{{ $r['vni'] }}</a>@if ($r['instance'])<br><small class="text-muted">{{ $r['instance'] }}</small>@endif</td>
                        <td><small>@foreach ($r['ips'] as $ip)<a href="{{ url()->current() }}?q={{ $ip }}">{{ $ip }}</a>@if (! $loop->last), @endif @endforeach</small></td>
                        <td>@if ($r['device']){!! \LibreNMS\Util\Url::deviceLink($r['device']) !!}@else device {{ $r['device_id'] }}@endif</td>
                        <td>
                            @php($src = $r['source'])
                            @if ($src['type'] === 'local')
                                <span class="label label-success">local</span>
                                @if ($src['port']){!! \LibreNMS\Util\Url::portLink($src['port'], $src['text']) !!}@else<code>{{ $src['text'] }}</code>@endif
                            @elseif ($src['type'] === 'remote')
                                <span class="label label-info">remote</span>
                                @if ($src['device']){!! \LibreNMS\Util\Url::deviceLink($src['device']) !!}@else<span class="text-muted" title="VTEP not monitored">{{ $src['text'] }}</span>@endif
                                @if ($src['device'] && $src['text'] !== $r['source']['text'])<small class="text-muted">{{ $r['source']['text'] }}</small>@endif
                            @elseif ($src['type'] === 'esi')
                                <span class="label label-primary" title="{{ $src['text'] }}">ESI</span>
                                @forelse ($src['peers'] as $peer)
                                    @if ($peer['device']){!! \LibreNMS\Util\Url::deviceLink($peer['device']) !!}@else<span class="text-muted">{{ $peer['name'] ?? '?' }}</span>@endif
                                    @if ($peer['port']){!! \LibreNMS\Util\Url::portLink($peer['port'], $peer['ifname']) !!}@elseif ($peer['ifname'])<code>{{ $peer['ifname'] }}</code>@endif
                                    @if (! $loop->last)<br>@endif
                                @empty
                                    <code>{{ $src['text'] }}</code> <small class="text-muted">no PE known</small>
                                @endforelse
                            @else
                                <code>{{ $src['text'] }}</code>
                            @endif
                        </td>
                        <td><small>{{ $r['active_since'] }}</small></td>
                        <td class="text-right">{{ $r['moves'] ?: '' }}</td>
                        <td>@if ($r['is_duplicate'])<span class="label label-danger">duplicate</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($search['fdb'] !== [] || $search['arp'] !== [])
        <h4 style="margin-top: 20px;">Core tables <small>bridge tables (FDB) and ARP as discovered over SNMP</small></h4>
        <div class="row">
            @if ($search['fdb'] !== [])
                <div class="col-md-6">
                    <table class="table table-condensed table-hover">
                        <thead><tr><th>MAC</th><th>Device</th><th>Port</th><th>VLAN</th><th>Updated</th></tr></thead>
                        <tbody>
                            @foreach ($search['fdb'] as $f)
                                <tr>
                                    <td><code>{{ $f['mac'] }}</code></td>
                                    <td>@if ($f['device']){!! \LibreNMS\Util\Url::deviceLink($f['device']) !!}@endif</td>
                                    <td><a href="{{ url('device/' . $f['device_id'] . '/port/' . $f['port_id']) }}">{{ $f['ifName'] }}</a></td>
                                    <td><small>{{ $f['vlan_vlan'] }} {{ $f['vlan_name'] }}</small></td>
                                    <td><small>{{ $f['updated_at'] }}</small></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($search['arp'] !== [])
                <div class="col-md-6">
                    <table class="table table-condensed table-hover">
                        <thead><tr><th>MAC</th><th>IP</th><th>Device</th><th>Port</th></tr></thead>
                        <tbody>
                            @foreach ($search['arp'] as $a)
                                <tr>
                                    <td><a href="{{ url()->current() }}?q={{ $a['mac'] }}"><code>{{ $a['mac'] }}</code></a></td>
                                    <td>{{ $a['ipv4_address'] }}@if ($a['context_name']) <small class="text-muted">{{ $a['context_name'] }}</small>@endif</td>
                                    <td>@if ($a['device']){!! \LibreNMS\Util\Url::deviceLink($a['device']) !!}@endif</td>
                                    <td>{{ $a['ifName'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
@endif
