{{-- One link of the trace picture: access, underlay hop or gap. $link is an item of TracePathDiagram. --}}
<div class="nt-link nt-tone-{{ $link['tone'] }} {{ $link['kind'] === 'access' ? 'nt-access' : '' }}">
    <div class="nt-meta">
        @if ($link['kind'] === 'underlay')
            @if ($link['vni'] !== null)<span class="nt-chip nt-vni">VNI {{ $link['vni'] }}</span>@endif
            <span>{{ $link['protocol'] }}</span>
            @if ($link['ecmp'] > 1)<span class="nt-chip nt-warn">{{ $link['ecmp'] }}-way ECMP</span>@endif
            @if ($link['live'])<span class="nt-chip nt-live" title="read from this device's forwarding table">live</span>@endif
            @if ($link['wan'])<span class="nt-chip">WAN</span>@endif
        @elseif ($link['kind'] === 'access')
            <span class="nt-kind">access</span>
            @if ($link['esi'] !== null)<span class="nt-chip nt-esi" title="Ethernet segment {{ $link['esi'] }}">ESI{{ $link['df'] ? ' · DF' : '' }}</span>@endif
        @endif
    </div>
    <div class="nt-wire"></div>
    @if ($link['kind'] === 'underlay')
        <div class="nt-ifs"><span class="nt-if" title="{{ $link['left_if'] }}">{{ $link['left_if'] ?? '?' }}</span><span class="nt-if" title="{{ $link['right_if'] }}">{{ $link['right_if'] ?? '?' }}</span></div>
        <div class="nt-state">{{ $link['state'] ?? 'no session state' }}</div>
    @elseif ($link['kind'] === 'access')
        <div class="nt-ifs nt-one"><span class="nt-if" title="{{ $link['right_if'] }}">{{ $link['right_if'] ?? '?' }}</span></div>
    @else
        <div class="nt-state">{{ $link['state'] }}</div>
    @endif
    {{-- one graph for the link: the port on the near end; hovering it shows the periods --}}
    @if ($link['port_ids'] !== [])
        @php($portId = $link['port_ids'][0])
        @php($periods = array_map(fn ($from) => route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => $from, 'width' => 340, 'height' => 100, 'legend' => 'yes']), ['-1d', '-1w', '-1mo', '-1y']))
        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('port_bits', ['id' => $portId]) }}">
            <img class="nt-spark" alt="port {{ $portId }}" data-src="{{ route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => '-1d', 'width' => 120, 'height' => 24, 'legend' => 'no']) }}" data-popup='@json($periods)'>
        </a>
    @endif
</div>
