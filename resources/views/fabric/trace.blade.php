{{-- Tracer (plan §12): source and destination to the full path through the fabric, with the
     interfaces on every hop. Graph mode reads stored tables only; live mode is an admin POST
     because it opens an SSH session to every device on the way. --}}
<form method="get" class="form-inline" style="margin-bottom: 10px;">
    <input type="text" name="from" class="form-control" placeholder="source MAC or IP" value="{{ $trace_from }}" style="width: 220px;" autofocus>
    <input type="text" name="to" class="form-control" placeholder="destination MAC or IP" value="{{ $trace_to }}" style="width: 220px;">
    <input type="text" name="vni" class="form-control" placeholder="VNI (optional)" value="{{ $trace_vni }}" style="width: 130px;">
    <button type="submit" class="btn btn-primary">Trace</button>
    @if ($can_admin)
        <button type="submit" class="btn btn-default" form="netconf-trace-live" title="ask every device on the way what its own forwarding table says">Trace live</button>
    @endif
</form>
@if ($can_admin)
    <form method="post" id="netconf-trace-live" action="{{ route('netconf.fabric.trace', $fabric['id']) }}" class="tw:hidden">
        @csrf
        <input type="hidden" name="from" value="{{ $trace_from }}">
        <input type="hidden" name="to" value="{{ $trace_to }}">
        <input type="hidden" name="vni" value="{{ $trace_vni }}">
    </form>
@endif

<p class="text-muted"><small>
    Both endpoints are looked up in the EVPN MAC database, then in core's bridge tables and ARP, in that order &mdash; the answer says which source it came from.
    The overlay is one logical hop (a VXLAN tunnel); the hops below it are the underlay that carries it, with the interface on both ends.
    A live trace reads each device's own forwarding table, which is the only thing that knows which of two parallel links a packet takes; it never writes anything.
</small></p>

@php($result = $trace_live ?? $trace)
@if ($result === null)
    <p class="text-muted">Enter a source and a destination.</p>
@elseif (! ($result['ok'] ?? false))
    <div class="alert alert-warning">{{ $result['reason'] }}</div>
    @include('netconf::fabric.trace-sources', ['sources' => $result['a_sources'], 'label' => $result['from']])
    @include('netconf::fabric.trace-sources', ['sources' => $result['b_sources'], 'label' => $result['to']])
@else
    <div class="panel panel-default" id="netconf-trace-result">
        <div class="panel-heading">
            <strong>{{ ($result['mode'] ?? 'graph') === 'live' ? 'Live trace' : 'Trace' }}</strong>
            @if ($result['equal_paths'] > 1)<span class="label label-info">{{ $result['equal_paths'] }} equal-hop paths</span>@endif
            @if ($result['same_leaf'])<span class="label label-default">local switching, no VXLAN</span>@endif
        </div>
        <div class="panel-body">
            <pre style="white-space: pre-wrap; margin-bottom: 10px;">{{ $result['line'] }}</pre>

            @if ($result['path'] !== [])
                <table class="table table-condensed">
                    <thead><tr><th>Hop</th><th>Out</th><th>In</th><th>Protocol</th><th>State</th><th>Traffic</th></tr></thead>
                    <tbody>
                        @foreach ($result['path'] as $hop)
                            <tr>
                                <td>{{ $nodes->name($hop['a']) }} &rarr; {{ $nodes->name($hop['b']) }}@if ($hop['live'] ?? false) <span class="label label-info" title="read from this device's forwarding table">live</span>@endif</td>
                                <td><code>{{ $hop['a_ifname'] ?? '?' }}</code></td>
                                <td><code>{{ $hop['b_ifname'] ?? '?' }}</code></td>
                                <td>{{ $hop['protocol'] }}@if (($hop['ecmp'] ?? 1) > 1) <span class="label label-warning">{{ $hop['ecmp'] }}-way ECMP</span>@endif</td>
                                <td class="{{ $hop['up'] === false ? 'text-danger' : ($hop['up'] === true ? 'text-success' : 'text-muted') }}">{{ $hop['state'] ?? 'no session state' }}</td>
                                <td>@include('netconf::fabric.trace-graph', ['hop' => $hop])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @php($pathNodes = array_values(array_unique(array_merge(array_column($result['path'], 'a'), array_column($result['path'], 'b')))))
                <p><small><a href="{{ route('netconf.fabric', [$fabric['id'], 'overview']) }}?{{ http_build_query(['topo' => 'eagle', 'highlight' => array_values(array_filter(array_column($result['path'], 'link_key'))), 'through' => $pathNodes]) }}">show this path on the fabric picture</a></small></p>
            @endif

            @if ($result['warnings'] !== [])
                <div class="alert alert-warning" style="margin-bottom: 10px;">
                    @foreach ($result['warnings'] as $warning)<div>{{ $warning }}</div>@endforeach
                </div>
            @endif

            <h5>What was checked</h5>
            <table class="table table-condensed">
                @foreach ($result['checks'] as $check)
                    <tr>
                        <td style="width: 40%;">{{ $check['label'] }}</td>
                        <td style="width: 60px;">
                            @if ($check['ok'] === true)<span class="label label-success">yes</span>
                            @elseif ($check['ok'] === false)<span class="label label-danger">no</span>
                            @else <span class="label label-default">unknown</span>
                            @endif
                        </td>
                        <td><small class="text-muted">{{ $check['detail'] }}</small></td>
                    </tr>
                @endforeach
            </table>

            @if (($result['walk']['stopped'] ?? null) !== null)
                <p class="text-muted"><small>The live walk stopped: {{ $result['walk']['stopped'] }}. The rest of the path comes from the stored graph.</small></p>
            @endif
            @if (($result['log'] ?? []) !== [])
                <details><summary class="text-muted"><small>Sessions opened</small></summary>
                    <pre style="margin-top: 6px;">@foreach ($result['log'] as $line){{ $line }}
@endforeach</pre>
                </details>
            @endif

            @include('netconf::fabric.trace-sources', ['sources' => $result['a_sources'], 'label' => $result['from']])
            @include('netconf::fabric.trace-sources', ['sources' => $result['b_sources'], 'label' => $result['to']])
        </div>
    </div>
@endif
