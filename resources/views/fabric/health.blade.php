{{-- health badges: $health (key => count), $fabric_id --}}
@php($labels = [
    'sessions_down' => ['EVPN sessions down', 'danger', 'bgp'],
    'esis_degraded' => ['ESI-LAGs degraded', 'danger', 'esis'],
    'dup_macs' => ['duplicate MACs', 'danger', 'macs'],
    'orphan_vnis' => ['VNIs without flood list', 'warning', 'vnis'],
    'unknown_vteps' => ['unknown VTEPs', 'default', 'members'],
    'collector_failing' => ['members not polling', 'warning', 'members'],
])
@php($any = false)
@foreach ($labels as $key => [$text, $class, $tab])
    @if (($health[$key] ?? 0) > 0)
        @php($any = true)
        <a href="{{ route('netconf.fabric', [$fabric_id, $tab]) }}" class="label label-{{ $class }}" title="{{ $text }}">{{ $health[$key] }} {{ $text }}</a>
    @endif
@endforeach
@if (! $any)<span class="label label-success">ok</span>@endif
