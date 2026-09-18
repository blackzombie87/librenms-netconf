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
                <small class="text-muted">{{ $status->last_ok->diffForHumans() }}, {{ sprintf('%.1fs', $status->last_duration ?? 0) }}, {{ $status->transport }}</small>
            @else
                <span class="label label-warning">not polled yet</span>
            @endif
            @if ($skip_reason && $enabled)<br><small class="text-muted">{{ $skip_reason }}</small>@endif
        </td>
    </tr>
    @if ($status?->last_error)
        <tr class="danger"><th>Last error</th><td><small>{{ \Illuminate\Support\Str::limit($status->last_error, 160) }}</small></td></tr>
    @endif
    @if ($status?->definitions)
        <tr><th>Definitions</th><td>@foreach ($status->definitions as $name)<span class="label label-info">{{ $name }}</span> @endforeach</td></tr>
    @endif
    <tr>
        <th>Sensors</th>
        <td>
            {{ $sensor_total }} ({{ $state_total }} states)
            @if ($critical->isNotEmpty())
                — <span class="text-danger">{{ $critical->count() }} critical:</span>
                @foreach ($critical->take(6) as $sensor)
                    <br><small>{!! \LibreNMS\Util\Url::sensorLink($sensor) !!}: <span class="text-danger">{{ $sensor->sensor_class === 'state' ? ($sensor->currentTranslation()?->state_descr ?? $sensor->sensor_current) : $sensor->formatValue() }}</span></small>
                @endforeach
                @if ($critical->count() > 6)<br><small>… {{ $critical->count() - 6 }} more</small>@endif
            @endif
        </td>
    </tr>
</table>
@foreach ($metrics as $group => $rows)
    @php($fields = collect($rows)->flatMap(fn ($r) => array_keys($r->values ?? []))->unique()->take(6)->values())
    <table class="table table-condensed table-striped" style="margin-bottom: 0; border-top: 1px solid #ddd;">
        <thead>
            <tr>
                <th colspan="{{ 1 + $fields->count() }}"><code>{{ $group }}</code> <small class="text-muted">{{ $rows->count() }} rows</small></th>
            </tr>
            <tr>
                <th>Index</th>
                @foreach ($fields as $f)<th class="text-right"><small>{{ $f }}</small></th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows->take($rows_per_mapping) as $row)
                <tr>
                    <td title="{{ $row->descr }}"><small>{{ $row->metric_index }}</small></td>
                    @foreach ($fields as $f)<td class="text-right"><small>{{ isset($row->values[$f]) ? \SafferIt\LibrenmsNetconf\Extract\Template::stringify((float) $row->values[$f]) : '' }}</small></td>@endforeach
                </tr>
            @endforeach
            @if ($rows->count() > $rows_per_mapping)
                <tr><td colspan="{{ 1 + $fields->count() }}"><small><a href="{{ route('netconf.device.metrics', $device->device_id) }}">… all {{ $rows->count() }} rows</a></small></td></tr>
            @endif
        </tbody>
    </table>
@endforeach
