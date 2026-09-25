{{-- Inspector: a full-width row under the viewport, never a column beside it -- a side column
     would squeeze the viewport below the width the layout was computed for. Every panel is
     server-rendered and hidden; a click only unhides one. No graph image is requested here. --}}
@php($ei = $eagle_input)
<div id="eagle-inspector" class="panel panel-default" style="margin-top: 10px;">
    <div class="panel-body" style="padding: 10px;">
        <p id="eagle-inspector-empty" class="text-muted" style="margin: 0;"{{ $focus === '' ? '' : ' hidden' }}>
            <small>Select a member, a link or an ESI-LAG in the picture.</small>
        </p>

        @foreach ($eagle['nodes'] as $id => $n)
            @continue($n['kind'] !== 'member')
            @php($device = $nodes->device($n['ip']))
            <div data-focus="member:{{ $n['ip'] }}"{{ $focus === 'member:' . $n['ip'] ? '' : ' hidden' }}>
                <h5 style="margin-top: 0;">
                    @if ($device)<a href="{{ \LibreNMS\Util\Url::deviceUrl($device) }}">{{ $n['name'] }}</a>@else {{ $n['name'] }} @endif
                    <small class="text-muted">{{ $n['ip'] }} &middot; role {{ $n['role'] }}@if ($n['tier'] !== $n['role']), drawn on the {{ $n['tier'] }} tier @endif @if ($n['border']) &middot; border @endif</small>
                </h5>
                <p class="text-muted" style="margin-bottom: 6px;"><small>
                    {{ $n['device_id'] === null ? 'not a monitored device' : ($n['status'] === false ? 'SNMP down' : 'SNMP up') }} &middot;
                    {{ $n['collected'] ? 'EVPN data collected' : 'no EVPN data' }} &middot;
                    version {{ $n['version'] ?? 'unknown' }}
                    @if ($n['site'] ?? null) &middot; site {{ $n['site'] }} @endif
                    &middot; <a href="{{ route('netconf.fabric', [$fabric['id'], 'members']) }}">Members tab</a>
                </small></p>
                @php($links = array_values(array_filter($ei['underlay'], fn ($e) => $e['a'] === $n['ip'] || $e['b'] === $n['ip'])))
                @if ($links !== [])
                    <table class="table table-condensed" style="margin-bottom: 6px;">
                        <tr><th>Underlay link</th><th>Protocol</th><th>State</th><th>Ports</th></tr>
                        @foreach ($links as $e)
                            <tr>
                                <td>{{ $nodes->name($e['a']) }} &harr; {{ $e['b'] === null ? ($e['b_label'] ?? 'unknown') : $nodes->name($e['b']) }}</td>
                                <td>{{ $e['protocol'] }}</td>
                                <td class="{{ $e['up'] === false ? 'text-danger' : ($e['up'] === true ? 'text-success' : 'text-muted') }}">{{ $e['state'] ?? 'no session state' }}</td>
                                <td><small>{{ $e['a_port'] }} &harr; {{ $e['b_port'] }}</small></td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                @php($pairs = array_values(array_filter($ei['esi_pairs'], fn ($p) => $p['a'] === $n['ip'] || $p['b'] === $n['ip'])))
                @if ($pairs !== [])
                    <p><small>ESI-LAG pairs:
                        @foreach ($pairs as $p)
                            <span class="label label-{{ $p['degraded'] > 0 ? 'danger' : 'default' }}">{{ $nodes->name($p['a']) }} &harr; {{ $nodes->name($p['b']) }}: {{ $p['esis'] }}@if ($p['degraded'] > 0), {{ $p['degraded'] }} degraded @endif</span>
                        @endforeach
                    </small></p>
                @endif
            </div>
        @endforeach

        @foreach ($eagle['edges'] as $e)
            @continue($e['layer'] === 'attached')
            <div data-focus="{{ $e['id'] }}"{{ $focus === $e['id'] ? '' : ' hidden' }}>
                <h5 style="margin-top: 0;">{{ $nodes->name($e['a']) }} &harr; {{ $e['layer'] === 'underlay' && $e['kind'] === 'trunk' ? $e['b'] : $nodes->name($e['b']) }}
                    <small class="text-muted">{{ $e['kind'] }}</small>
                </h5>
                <p class="text-muted" style="margin: 0;"><small>
                    {{ $e['title'] }}
                    @if ($e['kind'] === 'missing') &mdash; from the "everyone else peers with this" check; see the <a href="{{ route('netconf.fabric', [$fabric['id'], 'bgp']) }}">BGP overlay tab</a>. @endif
                    @if ($e['kind'] === 'asymmetric') &mdash; only one of the two members lists the other. @endif
                    @if ($e['layer'] === 'esi') &mdash; <a href="{{ route('netconf.fabric', [$fabric['id'], 'esis']) }}">ESI tab</a>. @endif
                </small></p>
            </div>
        @endforeach

        @foreach ($ei['esi_rows'] as $row)
            <div data-focus="esi:{{ $row['esi'] }}"{{ $focus === 'esi:' . $row['esi'] ? '' : ' hidden' }}>
                <h5 style="margin-top: 0;"><code>{{ $row['esi'] }}</code> <small class="text-muted">{{ $row['mode'] ?? 'mode unknown' }}</small></h5>
                @if (! \SafferIt\LibrenmsNetconf\Fabric\View\EsiKind::isLag($row))
                    <p class="text-muted"><small>An anycast gateway segment on an IRB, not an ESI-LAG: it has no member ports and no traffic of its own, so the picture does not draw it.</small></p>
                @else
                    <table class="table table-condensed" style="margin-bottom: 6px;">
                        <tr><th>PE</th><th>Interface</th><th>LAG</th><th>Resolution</th><th>DF</th><th>Aliasing</th><th>LACP</th></tr>
                        @foreach ($row['sides'] as $side)
                            <tr>
                                <td>{{ $nodes->name($side['vtep_ip'] ?? '') }}</td>
                                <td>@if ($side['port']){!! \LibreNMS\Util\Url::portLink($side['port']) !!}@else {{ $side['ifname'] }} @endif</td>
                                <td>{{ $side['lag_status'] ?? '—' }}</td>
                                <td>{{ $side['status'] ?? '—' }}</td>
                                <td>{{ $side['is_df'] ? 'DF' : '' }}</td>
                                <td>{{ $side['aliasing'] === null ? '—' : ($side['aliasing'] ? 'on' : 'off') }}</td>
                                <td>{{ $side['lacp_degraded'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                    @if ($row['flags'] !== [])
                        <p><small>@foreach ($row['flags'] as $flag)<span class="label label-{{ in_array($flag, \SafferIt\LibrenmsNetconf\Fabric\View\EsiKind::CHIP, true) ? 'danger' : 'warning' }}">{{ $flag }}</span> @endforeach</small></p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
</div>
