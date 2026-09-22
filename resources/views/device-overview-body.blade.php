{{-- Rows of the overview panel: status, fabric membership, stored counts, one link. No tables
     of values here (plan §8 U1) — the per-device page has them, Health has the sensors. --}}
<table class="table table-condensed" style="margin-bottom: 0;">
    <tr>
        <th style="width: 35%;">Polling</th>
        <td>
            @if (! $enabled)
                <span class="label label-default">disabled</span>
            @elseif ($status && $status->consecutive_failures > 0)
                <span class="label label-danger">{{ $status->consecutive_failures }} failures</span>
                @if ($status->inBackoff())<small>back-off until {{ $status->next_attempt->format('H:i') }}</small>@endif
            @elseif ($status?->last_ok)
                <span class="label label-success">ok</span>
                <small class="text-muted">{{ sprintf('%.1f s', $status->last_duration ?? 0) }} &middot; {{ $status->transport }}{{ $enabled_attrib === null ? ' (default)' : '' }} &middot; {{ $status->last_ok->diffForHumans() }}</small>
            @else
                <span class="label label-warning">not polled yet</span>
            @endif
            @if ($skip_reason && $enabled)<br><small class="text-muted">{{ $skip_reason }}</small>@endif
        </td>
    </tr>
    @if ($status?->last_error)
        <tr class="danger"><th>Last error</th><td><small>{{ \Illuminate\Support\Str::limit($status->last_error, 160) }}</small></td></tr>
    @endif
    @if ($fabric_badge)
        <tr><th>EVPN fabric</th><td>@include('netconf::fabric.badge', ['badge' => $fabric_badge])</td></tr>
    @endif
    <tr>
        <th>Stored</th>
        <td>
            @if ($sensor_total > 0)
                <a href="{{ route('device', [$device->device_id, 'health']) }}">{{ $sensor_total }} sensors</a>@if ($state_total > 0) <small class="text-muted">({{ $state_total }} states)</small>@endif
                @if ($critical_total > 0) <span class="label label-danger">{{ $critical_total }} critical</span>@endif
            @else
                no sensors
            @endif
            @if ($metric_rows > 0)
                &middot; <a href="{{ \SafferIt\LibrenmsNetconf\Support\DevicePage::url($device->device_id, 'metrics') }}">{{ $metric_rows }} metric rows</a>
            @endif
            @if ($fabric_badge && $fabric_badge['esis'] > 0)
                &middot; <a href="{{ \SafferIt\LibrenmsNetconf\Support\DevicePage::url($device->device_id, 'esi') }}">{{ $fabric_badge['esis'] }} ESI-LAGs</a>
            @endif
            @if ($status?->definitions)
                &middot; {{ count($status->definitions) }} definitions
            @endif
            <span class="pull-right"><a href="{{ $href }}">Open NETCONF page &rarr;</a></span>
        </td>
    </tr>
</table>
