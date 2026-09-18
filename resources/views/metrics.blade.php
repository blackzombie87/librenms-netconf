@extends('layouts.librenmsv1')

@section('title', 'NETCONF metrics - ' . $device->displayName())

@section('content')
@php($periods = ['-6h' => '6h', '-1d' => 'day', '-1w' => 'week', '-1mo' => 'month', '-1y' => 'year'])
<div class="container-fluid">
    <div class="panel panel-default">
        <div class="panel-heading"><i class="fa fa-table fa-fw" aria-hidden="true"></i> <strong>NETCONF metrics</strong> — {!! \LibreNMS\Util\Url::deviceLink($device) !!}
            <span class="pull-right">
                graphs:
                @foreach ($periods as $from => $label)
                    <a href="{{ request()->fullUrlWithQuery(['period' => $from]) }}" class="{{ $period === $from ? 'text-primary' : 'text-muted' }}"><strong>{{ $label }}</strong></a>@if (! $loop->last) &middot; @endif
                @endforeach
                &nbsp;|&nbsp; <a href="{{ route('netconf.device', $device->device_id) }}">NETCONF settings</a>
            </span>
        </div>
        <div class="panel-body">
            @forelse ($metrics as $group => $rows)
                @php([$definition, $mapping] = explode(' / ', $group, 2))
                @php($numeric = collect($rows)->flatMap(fn ($r) => array_keys($r->dataSources()))->unique()->values())
                <h4><code>{{ $group }}</code> <small>{{ $rows->count() }} rows, updated {{ $rows->first()->last_seen?->diffForHumans() }}</small></h4>
                @php($fields = collect($rows)->flatMap(fn ($r) => array_keys(($r->values ?? []) + ($r->labels ?? [])))->unique()->values())
                <div style="overflow-x: auto;">
                <table class="table table-condensed table-hover table-striped">
                    <thead><tr><th>Index</th><th>Description</th>@foreach ($fields as $f)<th class="text-right">{{ $f }}</th>@endforeach<th></th></tr></thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><code>{{ $row->metric_index }}</code></td>
                            <td>{{ $row->descr }}</td>
                            @foreach ($fields as $f)
                                <td class="text-right">{{ $row->values[$f] ?? $row->labels[$f] ?? '' }}</td>
                            @endforeach
                            <td>
                                @if ($row->values)
                                    <a href="{{ route('netconf.graph.metric', ['metric' => $row->id, 'from' => $period, 'width' => 900, 'height' => 300]) }}" target="_blank" title="graph of this row"><i class="fa fa-area-chart" aria-hidden="true"></i></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                @if ($numeric->isNotEmpty())
                    <details style="margin-bottom: 20px;">
                        <summary>Graphs per field ({{ $numeric->count() }}), one line per row{{ $rows->count() > 25 ? ', first 25 rows' : '' }}</summary>
                        <div class="row">
                            @foreach ($numeric as $field)
                                <div class="col-md-6">
                                    <img class="img-responsive" loading="lazy" alt="{{ $field }}"
                                         src="{{ route('netconf.graph.metrics', ['device' => $device->device_id, 'definition' => $definition, 'mapping' => $mapping, 'field' => $field, 'from' => $period, 'width' => 600, 'height' => 220]) }}">
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            @empty
                <p>No custom metrics stored for this device yet.</p>
            @endforelse

            @if ($ports->isNotEmpty())
                <h4>Port metrics <small>{{ $ports->count() }} ports</small></h4>
                @php($pfields = collect($ports)->flatMap(fn ($r) => array_keys($r->values ?? []))->unique()->values())
                @php($pgroups = collect($ports)->map(fn ($r) => $r->definition . '/' . $r->mapping)->unique()->values())
                <div style="overflow-x: auto;">
                <table class="table table-condensed table-hover table-striped">
                    <thead><tr><th>Port</th><th>Mapping</th>@foreach ($pfields as $f)<th class="text-right">{{ $f }}</th>@endforeach<th></th></tr></thead>
                    <tbody>
                    @foreach ($ports as $row)
                        <tr>
                            <td><a href="{{ \LibreNMS\Util\Url::generate(['page' => 'device', 'device' => $device->device_id, 'tab' => 'port', 'port' => $row->port_id, 'view' => 'plugins']) }}">{{ $row->ifName }}</a> <small class="text-muted">{{ $row->ifAlias }}</small></td>
                            <td><code>{{ $row->definition }}/{{ $row->mapping }}</code></td>
                            @foreach ($pfields as $f)
                                <td class="text-right {{ ($row->values[$f] ?? 0) > 0 && ($row->types[$f] ?? 'GAUGE') !== 'GAUGE' ? 'text-warning' : '' }}">{{ $row->values[$f] ?? '' }}</td>
                            @endforeach
                            <td><a href="{{ route('netconf.graph.port', ['portMetric' => $row->id, 'from' => $period, 'width' => 900, 'height' => 300]) }}" target="_blank" title="graph of this port"><i class="fa fa-area-chart" aria-hidden="true"></i></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                <details style="margin-bottom: 20px;">
                    <summary>Graphs per counter, one line per port (first 25 ports)</summary>
                    @foreach ($pgroups as $pg)
                        @php([$pdef, $pmap] = explode('/', $pg, 2))
                        <div class="row">
                            @foreach ($pfields as $field)
                                <div class="col-md-6">
                                    <img class="img-responsive" loading="lazy" alt="{{ $field }}"
                                         src="{{ route('netconf.graph.ports', ['device' => $device->device_id, 'definition' => $pdef, 'mapping' => $pmap, 'field' => $field, 'from' => $period, 'width' => 600, 'height' => 220]) }}">
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </details>
            @endif
        </div>
    </div>
</div>
@endsection
