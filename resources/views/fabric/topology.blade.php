{{-- topology: the interactive map (vis-network) with the inline SVG as the static fallback --}}
@php($t = $topology)
@include('netconf::fabric.topology-net')
<div class="netconf-topology" id="nt-static-wrap">
    <div class="nt-bar">
        <label class="checkbox-inline"><input type="checkbox" checked onchange="document.getElementById('nt-svg').classList.toggle('nt-hide-overlay', !this.checked)"> overlay neighbours <small class="text-muted">({{ count($t['overlay']) }})</small></label>
        <label class="checkbox-inline"><input type="checkbox" checked onchange="document.getElementById('nt-svg').classList.toggle('nt-hide-esi', !this.checked)"> ESI pairs <small class="text-muted">({{ count($t['esi']) }})</small></label>
        <label class="checkbox-inline"><input type="checkbox" checked onchange="document.getElementById('nt-svg').classList.toggle('nt-hide-labels', !this.checked)"> link labels</label>
        @php($outside = count(array_filter($t['stubs'], fn ($s) => $s['outside'] ?? false)))
        @if ($outside > 0)
            <label class="checkbox-inline"><input type="checkbox" onchange="document.getElementById('nt-svg').classList.toggle('nt-hide-outside', !this.checked)"> sessions out of the fabric <small class="text-muted">({{ $outside }})</small></label>
        @endif
        <span class="text-muted" style="margin-left: 12px;">
            <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#5cb85c" stroke-width="3"/></svg> underlay session up
            <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#d9534f" stroke-width="3"/></svg> down
            <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#999" stroke-width="2"/></svg> LLDP only
            <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#8e6bbf" stroke-width="2" stroke-dasharray="6 3"/></svg> via WAN
            <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#337ab7" stroke-width="1" stroke-dasharray="3 3"/></svg> overlay
            <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#f0ad4e" stroke-width="2"/></svg> ESI pair
        </span>
        <span class="pull-right"><button type="button" class="btn btn-default btn-xs" id="nt-interactive-on">interactive map</button></span>
    </div>
    <style>
        .nt-hide-overlay .nt-overlay, .nt-hide-esi .nt-esi, .nt-hide-labels .nt-label { display: none; }
        .nt-outside { display: none; }
        #nt-svg:not(.nt-hide-outside) .nt-outside { display: inline; }
        .netconf-topology svg text { font-family: inherit; }
        .netconf-topology a { text-decoration: none; }
        .netconf-topology .nt-node:hover rect { stroke-width: 2.5; }
        .nt-bar { margin-bottom: 6px; }
        #nt-net { height: 62vh; min-height: 420px; border: 1px solid #ddd; border-radius: 4px; }
        #nt-net-wrap .vis-network:focus { outline: none; }
    </style>
    <div style="overflow-x: auto;">
        <svg id="nt-svg" width="{{ $t['width'] }}" height="{{ $t['height'] }}" viewBox="0 0 {{ $t['width'] }} {{ $t['height'] }}" xmlns="http://www.w3.org/2000/svg" style="display: block; max-width: 100%; height: auto;">
            {{-- site groups --}}
            @foreach ($t['groups'] as $g)
                @if (count($t['groups']) > 1 || $g['label'])
                    <rect x="{{ $g['x'] }}" y="{{ $g['y'] }}" width="{{ $g['w'] }}" height="{{ $g['h'] }}" rx="6" fill="rgba(0,0,0,0.03)" stroke="#ccc" stroke-dasharray="4 3"/>
                    @if ($g['label'])<text x="{{ $g['x'] + 6 }}" y="{{ $g['y'] + 13 }}" font-size="11" fill="#777">{{ $g['label'] }}</text>@endif
                @endif
            @endforeach

            {{-- overlay arcs --}}
            <g class="nt-overlay">
                @foreach ($t['overlay'] as $o)
                    <path d="{{ $o['path'] }}" fill="none" stroke="{{ $o['symmetric'] || ! $o['both_monitored'] ? '#337ab7' : '#d9534f' }}" stroke-width="1" stroke-dasharray="3 3" opacity="0.7">
                        <title>EVPN neighbours {{ $nodes->name($o['a']) }} ↔ {{ $nodes->name($o['b']) }}@if (! $o['symmetric'] && $o['both_monitored']) — listed by one side only @endif</title>
                    </path>
                @endforeach
            </g>

            {{-- underlay links --}}
            @foreach ($t['underlay'] as $e)
                @php($color = $e['protocol'] === 'lldp-only' ? '#999' : ($e['wan'] ? '#8e6bbf' : ($e['up'] === false ? '#d9534f' : ($e['up'] === true ? '#5cb85c' : '#999'))))
                <path d="{{ $e['path'] }}" fill="none" stroke="{{ $color }}" stroke-width="{{ $e['protocol'] === 'lldp-only' ? 2 : 3 }}" @if ($e['wan']) stroke-dasharray="6 3" @endif>
                    <title>{{ $nodes->name($e['a']) }} {{ $e['a_port'] }} ↔ {{ $nodes->name($e['b']) }} {{ $e['b_port'] }}: {{ $e['protocol'] }} {{ $e['state'] }}@if ($e['network']), {{ $e['network'] }}@endif @if ($e['lldp']), LLDP confirmed @endif</title>
                </path>
                <text class="nt-label" x="{{ $e['lx'] }}" y="{{ $e['ly'] }}" font-size="9" fill="#666" text-anchor="middle">{{ $e['protocol'] }}@if ($e['state']) {{ $e['state'] }}@endif</text>
            @endforeach

            {{-- half edges towards unmonitored neighbours; a far end no other member peers with
                 is a session out of the fabric (transit, an IX), hidden until asked for --}}
            @foreach ($t['stubs'] as $s)
                @php($color = $s['protocol'] === 'lldp-only' ? '#999' : ($s['wan'] ? '#8e6bbf' : ($s['up'] === false ? '#d9534f' : '#5cb85c')))
                @php($class = ($s['outside'] ?? false) ? 'nt-outside' : '')
                <line class="{{ $class }}" x1="{{ $s['x1'] }}" y1="{{ $s['y1'] }}" x2="{{ $s['x2'] }}" y2="{{ $s['y2'] }}" stroke="{{ $color }}" stroke-width="3" @if ($s['wan']) stroke-dasharray="6 3" @endif>
                    <title>{{ $nodes->name($s['a']) }} {{ $s['a_port'] }} → {{ $s['label'] ?? 'unknown' }}: {{ $s['protocol'] }} {{ $s['state'] }}@if ($s['network']), {{ $s['network'] }}@endif — @if ($s['outside'] ?? false) a session out of the fabric: no other member peers with this address @else far end not resolved to a fabric member @endif</title>
                </line>
                <circle class="{{ $class }}" cx="{{ $s['x2'] }}" cy="{{ $s['y2'] }}" r="3" fill="{{ $color }}"/>
            @endforeach

            {{-- ESI pair brackets --}}
            <g class="nt-esi">
                @foreach ($t['esi'] as $b)
                    <path d="{{ $b['path'] }}" fill="none" stroke="#f0ad4e" stroke-width="2">
                        <title>{{ $b['esis'] }} shared ESI{{ $b['esis'] === 1 ? '' : 's' }}: {{ $nodes->name($b['a']) }} ↔ {{ $nodes->name($b['b']) }}</title>
                    </path>
                    <text class="nt-label" x="{{ $b['lx'] }}" y="{{ $b['ly'] }}" font-size="9" fill="#a56c00" text-anchor="middle">{{ $b['esis'] }} ESI{{ $b['esis'] === 1 ? '' : 's' }}</text>
                @endforeach
            </g>

            {{-- nodes --}}
            @foreach ($t['nodes'] as $ip => $n)
                @php($fill = match ($n['role']) { 'gateway' => '#dbe9f6', 'spine' => '#e3f1fa', 'leaf' => '#e6f4e6', default => '#f2f2f2' })
                @php($stroke = $n['device_id'] === null ? '#999' : ($n['status'] === false ? '#d9534f' : '#5a5a5a'))
                @php($device = $nodes->device($ip))
                <g class="nt-node">
                    @if ($device)<a href="{{ \LibreNMS\Util\Url::deviceUrl($device) }}">@endif
                    <rect x="{{ $n['x'] }}" y="{{ $n['y'] }}" width="{{ \SafferIt\LibrenmsNetconf\Fabric\View\Topology::NODE_W }}" height="{{ \SafferIt\LibrenmsNetconf\Fabric\View\Topology::NODE_H }}" rx="5" fill="{{ $fill }}" stroke="{{ $stroke }}" stroke-width="1.5" @if ($n['device_id'] === null) stroke-dasharray="4 3" @endif>
                        <title>{{ $n['name'] }} ({{ $ip }}) — {{ $n['role'] }}@if ($n['border']), border @endif @if ($n['device_id'] === null) — not monitored @elseif ($n['status'] === false) — device down @endif</title>
                    </rect>
                    <text x="{{ $n['x'] + 64 }}" y="{{ $n['y'] + 16 }}" font-size="11" font-weight="bold" text-anchor="middle" fill="#222">{{ \Illuminate\Support\Str::limit($n['name'], 20, '…') }}</text>
                    <text x="{{ $n['x'] + 64 }}" y="{{ $n['y'] + 31 }}" font-size="10" text-anchor="middle" fill="#666">{{ $ip }}@if ($n['border']) · border @endif</text>
                    @if ($n['status'] === false)<circle cx="{{ $n['x'] + 122 }}" cy="{{ $n['y'] + 6 }}" r="4" fill="#d9534f"/>@endif
                    @if ($device)</a>@endif
                </g>
            @endforeach
        </svg>
    </div>
    <p class="text-muted" style="margin-top: 6px;"><small>
        Underlay links come from core IPv4 subnets, BGP / OSPF sessions and LLDP between members (plan §7.6); a short stub is a session whose far end is not a fabric member yet.
        Overlay arcs are the EVPN neighbour lists of the monitored members; red when only one monitored side lists the other. Dashed boxes are VTEPs without a monitored device.
        This picture puts every member in one row — fine for a handful, tight beyond that; the <em>interactive map</em> is the one that scales.
    </small></p>
</div>
