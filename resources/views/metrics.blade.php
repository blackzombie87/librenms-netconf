@extends('layouts.librenmsv1')

@section('title', 'NETCONF metrics - ' . $device->displayName())

@section('content')
@php($periods = ['-6h' => '6h', '-1d' => 'day', '-1w' => 'week', '-1mo' => 'month', '-1y' => 'year'])
@php($graph = fn (string $route, array $params) => route($route, $params + ['from' => $period, 'width' => 600, 'height' => 220]))
<div class="container-fluid">
    <div class="panel panel-default">
        <div class="panel-heading"><i class="fa fa-table fa-fw" aria-hidden="true"></i> <strong>NETCONF metrics</strong> — {!! \LibreNMS\Util\Url::deviceLink($device) !!}
            <span class="pull-right">
                graphs:
                @foreach ($periods as $from => $label)
                    <a href="{{ request()->fullUrlWithQuery(['period' => $from]) }}" class="{{ $period === $from ? 'text-primary' : 'text-muted' }}"><strong>{{ $label }}</strong></a>@if (! $loop->last) &middot; @endif
                @endforeach
                &nbsp;|&nbsp; <a href="#" data-netconf-fold="open">expand all</a> &middot; <a href="#" data-netconf-fold="close">collapse all</a>
                &nbsp;|&nbsp; <a href="{{ route('netconf.device', $device->device_id) }}">NETCONF settings</a>
            </span>
        </div>
        <div class="panel-body">
            <p class="text-muted">{{ $metrics->count() }} metric mappings, {{ $metrics->flatten(1)->count() }} rows{{ $ports->isNotEmpty() ? ', ' . $ports->count() . ' port rows' : '' }}. Open a mapping for its table; graphs are rendered when their fold-out is opened.</p>
            @forelse ($metrics as $group => $rows)
                @php([$definition, $mapping] = explode(' / ', $group, 2))
                @php($numeric = collect($rows)->flatMap(fn ($r) => array_keys($r->dataSources()))->unique()->values())
                @php($fields = collect($rows)->flatMap(fn ($r) => array_keys(($r->values ?? []) + ($r->labels ?? [])))->unique()->values())
                <details class="netconf-mapping" style="margin-bottom: 10px;">
                    <summary><code>{{ $group }}</code> <small class="text-muted">{{ $rows->count() }} rows, {{ $fields->count() }} fields, updated {{ $rows->first()->last_seen?->diffForHumans() }}</small></summary>
                    <div style="overflow-x: auto;">
                    <table class="table table-condensed table-hover table-striped">
                        <thead><tr><th>Index</th><th>Description</th>@foreach ($fields as $f)<th class="text-right">{{ $f }}</th>@endforeach<th></th></tr></thead>
                        <tbody>
                        @foreach ($rows as $row)
                            <tr><td><code>{{ $row->metric_index }}</code></td><td>{{ $row->descr }}</td>@foreach ($fields as $f)<td class="text-right">{{ $row->values[$f] ?? $row->labels[$f] ?? '' }}</td>@endforeach<td>@if ($row->values)<a href="{{ route('netconf.graph.metric', ['metric' => $row->id, 'from' => $period, 'width' => 900, 'height' => 300]) }}" target="_blank" title="graph of this row"><i class="fa fa-area-chart" aria-hidden="true"></i></a>@endif</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                    @if ($numeric->isNotEmpty())
                        <details class="netconf-graphs" style="margin-bottom: 10px;">
                            <summary>Graphs per field ({{ $numeric->count() }}), one line per row{{ $rows->count() > $max_series ? sprintf(', first %d of %d rows', $max_series, $rows->count()) : '' }}</summary>
                            <div class="row">
                                @foreach ($numeric as $field)
                                    <div class="col-md-6 netconf-graph" data-src="{{ $graph('netconf.graph.metrics', ['device' => $device->device_id, 'definition' => $definition, 'mapping' => $mapping, 'field' => $field]) }}" data-alt="{{ $field }}"></div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </details>
            @empty
                <p>No custom metrics stored for this device yet.</p>
            @endforelse

            @if ($ports->isNotEmpty())
                @php($pfields = collect($ports)->flatMap(fn ($r) => array_keys($r->values ?? []))->unique()->values())
                @php($pgroups = collect($ports)->map(fn ($r) => $r->definition . '/' . $r->mapping)->unique()->values())
                <details class="netconf-mapping" style="margin-bottom: 10px;">
                    <summary><strong>Port metrics</strong> <small class="text-muted">{{ $ports->count() }} ports, {{ $pfields->count() }} counters, {{ $pgroups->count() }} mapping(s)</small></summary>
                    <div style="overflow-x: auto;">
                    <table class="table table-condensed table-hover table-striped">
                        <thead><tr><th>Port</th><th>Mapping</th>@foreach ($pfields as $f)<th class="text-right">{{ $f }}</th>@endforeach<th></th></tr></thead>
                        <tbody>
                        @foreach ($ports as $row)
                            <tr><td><a href="{{ \LibreNMS\Util\Url::generate(['page' => 'device', 'device' => $device->device_id, 'tab' => 'port', 'port' => $row->port_id, 'view' => 'plugins']) }}">{{ $row->ifName }}</a> <small class="text-muted">{{ $row->ifAlias }}</small></td><td><code>{{ $row->definition }}/{{ $row->mapping }}</code></td>@foreach ($pfields as $f)<td class="text-right {{ ($row->values[$f] ?? 0) > 0 && ($row->types[$f] ?? 'GAUGE') !== 'GAUGE' ? 'text-warning' : '' }}">{{ $row->values[$f] ?? '' }}</td>@endforeach<td><a href="{{ route('netconf.graph.port', ['portMetric' => $row->id, 'from' => $period, 'width' => 900, 'height' => 300]) }}" target="_blank" title="graph of this port"><i class="fa fa-area-chart" aria-hidden="true"></i></a></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                    <details class="netconf-graphs" style="margin-bottom: 10px;">
                        <summary>Graphs per counter, one line per port{{ $ports->count() > $max_series ? sprintf(' (first %d of %d ports per mapping)', $max_series, $ports->count()) : '' }}</summary>
                        @foreach ($pgroups as $pg)
                            @php([$pdef, $pmap] = explode('/', $pg, 2))
                            <div class="row">
                                @foreach ($pfields as $field)
                                    <div class="col-md-6 netconf-graph" data-src="{{ $graph('netconf.graph.ports', ['device' => $device->device_id, 'definition' => $pdef, 'mapping' => $pmap, 'field' => $field]) }}" data-alt="{{ $field }}"></div>
                                @endforeach
                            </div>
                        @endforeach
                    </details>
                </details>
            @endif
        </div>
    </div>
</div>
<script>
(function () {
    // graphs cost one rrdtool run each: create the <img> only when its fold-out is opened (plan G12)
    function materialize(details) {
        if (! details.open) { return; }
        details.querySelectorAll('.netconf-graph[data-src]').forEach(function (box) {
            var img = document.createElement('img');
            img.className = 'img-responsive';
            img.alt = box.getAttribute('data-alt');
            img.src = box.getAttribute('data-src');
            box.removeAttribute('data-src');
            box.appendChild(img);
        });
    }
    document.querySelectorAll('details.netconf-graphs').forEach(function (details) {
        details.addEventListener('toggle', function () { materialize(details); });
    });
    document.querySelectorAll('[data-netconf-fold]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var open = link.getAttribute('data-netconf-fold') === 'open';
            document.querySelectorAll('details.netconf-mapping').forEach(function (details) { details.open = open; });
        });
    });
})();
</script>
@endsection
