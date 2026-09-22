{{-- "Plugins" tab of a port: the plugin's per-port counters. The numbers are on the page,
     every graph is a fold-out and its image is created when the fold-out opens (plan §8 U3),
     the same pattern as the metrics section: one rrdtool run per graph, only when asked for. --}}
@php($periods = ['-6h' => '6h', '-1d' => 'day', '-1w' => 'week', '-1mo' => 'month', '-1y' => 'year'])
@php($graph = fn (array $params) => route('netconf.graph.port', $params + ['from' => $period]))
@include('netconf::fold-style')
<div class="panel panel-default">
    <div class="panel-heading">
        <i class="fa fa-terminal fa-fw" aria-hidden="true"></i> <strong>NETCONF port counters</strong>
        <span class="pull-right">
            graphs:
            @foreach ($periods as $from => $label)
                <a href="{{ request()->fullUrlWithQuery(['period' => $from]) }}" class="{{ $period === $from ? 'text-primary' : 'text-muted' }}"><strong>{{ $label }}</strong></a>@if (! $loop->last) &middot; @endif
            @endforeach
        </span>
    </div>
    <div class="panel-body">
        @foreach ($rows as $row)
            @php($counters = array_keys(array_filter($row->types ?? [], fn ($t) => $t !== 'GAUGE')))
            @php($gauges = array_keys(array_filter($row->types ?? [], fn ($t) => $t === 'GAUGE')))
            <h4><code>{{ $row->definition }}/{{ $row->mapping }}</code> <small class="text-muted">updated {{ $row->last_seen?->diffForHumans() }}</small></h4>
            <div class="tw:overflow-x-auto">
            <table class="table table-condensed table-striped" style="max-width: 900px;">
                <tr>
                    @foreach ($row->values ?? [] as $field => $value)
                        <th class="text-right tw:font-normal"><small>{{ $field }}{{ ($row->types[$field] ?? 'GAUGE') !== 'GAUGE' ? '*' : '' }}</small></th>
                    @endforeach
                </tr>
                <tr>
                    @foreach ($row->values ?? [] as $field => $value)
                        <td class="text-right {{ $value > 0 && ($row->types[$field] ?? 'GAUGE') !== 'GAUGE' ? 'text-warning' : '' }}">{{ \SafferIt\LibrenmsNetconf\Extract\Template::stringify((float) $value) }}</td>
                    @endforeach
                </tr>
            </table>
            </div>
            <p class="text-muted"><small>* counters, graphed as rate per second</small></p>
            @if ($counters !== [] || $gauges !== [])
                <details class="netconf-graphs panel panel-default">
                    <summary class="panel-heading">Graphs{{ $counters !== [] ? ': all counters' : '' }}{{ $gauges !== [] ? ($counters !== [] ? ', ' : ': ') . count($gauges) . ' gauge' . (count($gauges) === 1 ? '' : 's') : '' }}</summary>
                    <div class="panel-body"><div class="row">
                        @if ($counters !== [])
                            <div class="col-md-6 netconf-graph" data-src="{{ $graph(['portMetric' => $row->id, 'field' => implode(',', $counters), 'width' => 600, 'height' => 220]) }}" data-alt="all counters"></div>
                        @endif
                        @foreach ($gauges as $field)
                            <div class="col-md-6 netconf-graph" data-src="{{ $graph(['portMetric' => $row->id, 'field' => $field, 'width' => 600, 'height' => 220]) }}" data-alt="{{ $field }}"></div>
                        @endforeach
                    </div></div>
                </details>
            @endif
            @if (count($counters) > 1)
                <details class="netconf-graphs panel panel-default">
                    <summary class="panel-heading">Individual counter graphs ({{ count($counters) }})</summary>
                    <div class="panel-body"><div class="row">
                        @foreach ($counters as $field)
                            <div class="col-md-4 netconf-graph" data-src="{{ $graph(['portMetric' => $row->id, 'field' => $field, 'width' => 400, 'height' => 150]) }}" data-alt="{{ $field }}"></div>
                        @endforeach
                    </div></div>
                </details>
            @endif
        @endforeach
    </div>
</div>
<script>
(function () {
    // graphs cost one rrdtool run each: the image element is created when its fold-out opens
    document.querySelectorAll('details.netconf-graphs').forEach(function (details) {
        details.addEventListener('toggle', function () {
            if (! details.open) { return; }
            details.querySelectorAll('.netconf-graph[data-src]').forEach(function (box) {
                var img = document.createElement('img');
                img.className = 'img-responsive';
                img.alt = box.getAttribute('data-alt');
                img.src = box.getAttribute('data-src');
                box.removeAttribute('data-src');
                box.appendChild(img);
            });
        });
    });
})();
</script>
