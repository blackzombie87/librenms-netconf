{{-- Per-hop traffic, the same discipline as the ESI partial: no src until promote() runs. --}}
@include('netconf::fabric.graph-lazy')
@php($portIds = array_values(array_filter([$hop['a_port_id'] ?? null, $hop['b_port_id'] ?? null])))
@if ($portIds === [])
    <small class="text-muted">no core port</small>
@else
    @foreach ($portIds as $portId)
        @php($periods = array_map(fn ($from) => route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => $from, 'width' => 340, 'height' => 100, 'legend' => 'yes']), ['-1d', '-1w', '-1mo', '-1y']))
        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('port_bits', ['id' => $portId]) }}">
            <img alt="port {{ $portId }}" data-src="{{ route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => '-1d', 'width' => 200, 'height' => 40, 'legend' => 'no']) }}" data-popup='@json($periods)'>
        </a>
    @endforeach
@endif
