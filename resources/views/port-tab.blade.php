@php($periods = ['-6h' => '6h', '-1d' => 'day', '-1w' => 'week', '-1mo' => 'month', '-1y' => 'year'])
<div class="panel panel-default">
    <div class="panel-heading">
        <i class="fa fa-terminal fa-fw" aria-hidden="true"></i> <strong>NETCONF port counters</strong>
        <span class="pull-right">
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
            <table class="table table-condensed table-striped" style="max-width: 900px;">
                <tr>
                    @foreach ($row->values ?? [] as $field => $value)
                        <th class="text-right" style="font-weight: normal;"><small>{{ $field }}{{ ($row->types[$field] ?? 'GAUGE') !== 'GAUGE' ? '*' : '' }}</small></th>
                    @endforeach
                </tr>
                <tr>
                    @foreach ($row->values ?? [] as $field => $value)
                        <td class="text-right {{ $value > 0 && ($row->types[$field] ?? 'GAUGE') !== 'GAUGE' ? 'text-warning' : '' }}">{{ \SafferIt\LibrenmsNetconf\Extract\Template::stringify((float) $value) }}</td>
                    @endforeach
                </tr>
            </table>
            <p class="text-muted"><small>* counters, graphed as rate per second</small></p>
            <div class="row">
                @if ($counters !== [])
                    <div class="col-md-6">
                        <img class="img-responsive" loading="lazy" alt="all counters"
                             src="{{ route('netconf.graph.port', ['portMetric' => $row->id, 'field' => implode(',', $counters), 'from' => $period, 'width' => 600, 'height' => 220]) }}">
                    </div>
                @endif
                @foreach ($gauges as $field)
                    <div class="col-md-6">
                        <img class="img-responsive" loading="lazy" alt="{{ $field }}"
                             src="{{ route('netconf.graph.port', ['portMetric' => $row->id, 'field' => $field, 'from' => $period, 'width' => 600, 'height' => 220]) }}">
                    </div>
                @endforeach
            </div>
            <details style="margin-bottom: 15px;">
                <summary>Individual counter graphs</summary>
                <div class="row">
                    @foreach ($counters as $field)
                        <div class="col-md-4">
                            <img class="img-responsive" loading="lazy" alt="{{ $field }}"
                                 src="{{ route('netconf.graph.port', ['portMetric' => $row->id, 'field' => $field, 'from' => $period, 'width' => 400, 'height' => 150]) }}">
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>
</div>
