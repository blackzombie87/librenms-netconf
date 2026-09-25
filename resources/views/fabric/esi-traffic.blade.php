{{-- Traffic of one ESI-LAG: the sum over the AE of every PE, plus one graph per PE so a quiet
     leg is visible instead of hidden inside a healthy sum. $row is an EsiMatrix row.

     No image here carries a `src`; `netconf::fabric.graph-lazy` explains why and publishes
     the one function that changes that, for one panel at a time. --}}
@php($esi = (string) $row['esi'])
@php($sides = array_map(fn ($s) => ['device_id' => (int) $s['device_id'], 'ifname' => $s['ifname'] === null ? null : (string) $s['ifname'], 'port_id' => $s['port_id'] === null ? null : (int) $s['port_id'], 'esi' => $esi], array_values($row['sides'])))
@php($ports = \SafferIt\LibrenmsNetconf\Fabric\View\EsiTrafficPorts::select($sides))
@php($thumb = fn (string $type, string $id) => ['type' => $type, 'id' => $id, 'from' => '-1d', 'width' => 300, 'height' => 80, 'legend' => 'no'])
@php($periodList = ['-1d', '-1w', '-1mo', '-1y'])
@php($periods = fn (string $type, string $id) => array_map(fn ($from) => route('graph', ['type' => $type, 'id' => $id, 'from' => $from, 'width' => 340, 'height' => 100, 'legend' => 'yes']), $periodList))

@if ($ports['excluded'])
    <p class="text-muted"><small>An anycast gateway segment on an IRB: no member ports and no traffic of its own.</small></p>
@elseif ($ports['port_ids'] === [])
    <p class="text-muted"><small>No core port on any side, so there is nothing to graph.</small></p>
@else
    @if (count($ports['port_ids']) > 1)
        @php($id = implode(',', $ports['port_ids']))
        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('multiport_bits', ['id' => $id]) }}">
            <img alt="ESI-LAG {{ $esi }} total" data-src="{{ route('graph', $thumb('multiport_bits', $id)) }}" data-popup='{{ json_encode($periods('multiport_bits', $id)) }}'>
        </a>
    @endif
    @foreach ($ports['per_pe'] as $deviceId => $portId)
        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('port_bits', ['id' => $portId]) }}">
            <img alt="PE {{ $deviceId }} port {{ $portId }}" data-src="{{ route('graph', $thumb('port_bits', (string) $portId)) }}" data-popup='{{ json_encode($periods('port_bits', (string) $portId)) }}'>
        </a>
    @endforeach
    <p class="text-muted" style="margin-top: 4px;"><small>
        @if (count($ports['port_ids']) > 1)
            The first graph sums the aggregated interfaces {{ implode(', ', $ports['port_ids']) }} &mdash; that sum is the virtual LAG, and <em>in</em> is traffic from the attached device into the fabric.
            Both legs of a single-active segment stay in the sum; the quiet one is visible because its own graph is flat.
        @else
            One PE has a core port, so this is that port and not a sum.
        @endif
        @if ($ports['skipped'] !== [])
            Omitted, no <code>ports</code> row yet:
            @foreach ($ports['skipped'] as $s){{ $nodes->name(collect($row['sides'])->firstWhere('device_id', $s['device_id'])['vtep_ip'] ?? '') }} {{ $s['ifname'] }}@if (! $loop->last), @endif @endforeach.
        @endif
    </small></p>
@endif

@include('netconf::fabric.graph-lazy')
