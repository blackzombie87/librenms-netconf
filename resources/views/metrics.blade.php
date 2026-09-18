@extends('layouts.librenmsv1')

@section('title', 'NETCONF metrics - ' . $device->displayName())

@section('content')
<div class="container-fluid">
    <div class="panel panel-default">
        <div class="panel-heading"><i class="fa fa-table fa-fw" aria-hidden="true"></i> <strong>NETCONF metrics</strong> — {!! \LibreNMS\Util\Url::deviceLink($device) !!}
            <span class="pull-right"><a href="{{ route('netconf.device', $device->device_id) }}">NETCONF settings</a></span>
        </div>
        <div class="panel-body">
            @forelse ($metrics as $group => $rows)
                <h4><code>{{ $group }}</code> <small>{{ $rows->count() }} rows, updated {{ $rows->first()->last_seen?->diffForHumans() }}</small></h4>
                @php($fields = collect($rows)->flatMap(fn ($r) => array_keys(($r->values ?? []) + ($r->labels ?? [])))->unique()->values())
                <div style="overflow-x: auto;">
                <table class="table table-condensed table-hover table-striped">
                    <thead><tr><th>Index</th><th>Description</th>@foreach ($fields as $f)<th class="text-right">{{ $f }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><code>{{ $row->metric_index }}</code></td>
                            <td>{{ $row->descr }}</td>
                            @foreach ($fields as $f)
                                <td class="text-right">{{ $row->values[$f] ?? $row->labels[$f] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @empty
                <p>No custom metrics stored for this device yet.</p>
            @endforelse

            @if ($ports->isNotEmpty())
                <h4>Port metrics <small>{{ $ports->count() }} ports</small></h4>
                @php($pfields = collect($ports)->flatMap(fn ($r) => array_keys($r->values ?? []))->unique()->values())
                <div style="overflow-x: auto;">
                <table class="table table-condensed table-hover table-striped">
                    <thead><tr><th>Port</th><th>Mapping</th>@foreach ($pfields as $f)<th class="text-right">{{ $f }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($ports as $row)
                        <tr>
                            <td><a href="{{ \LibreNMS\Util\Url::portUrl($row->port_id) }}">{{ $row->ifName }}</a> <small class="text-muted">{{ $row->ifAlias }}</small></td>
                            <td><code>{{ $row->definition }}/{{ $row->mapping }}</code></td>
                            @foreach ($pfields as $f)
                                <td class="text-right">{{ $row->values[$f] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
