{{-- issue counts by severity as labels: $checks (critical, warning, info), $fabric_id, optional $link (default true) --}}
@php($any = false)
@foreach (['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $s => $class)
    @if (($checks[$s] ?? 0) > 0)
        @php($any = true)
        @if ($link ?? true)<a href="{{ route('netconf.fabric', [$fabric_id, 'checks', 'severity' => $s]) }}" class="label label-{{ $class }}">{{ $checks[$s] }} {{ $s }}</a>@else<span class="label label-{{ $class }}">{{ $checks[$s] }} {{ $s }}</span>@endif
    @endif
@endforeach
@if (! $any)<span class="label label-success">ok</span>@endif
