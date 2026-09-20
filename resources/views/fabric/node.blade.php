{{-- one fabric node: $node (FabricNodes::get()), optional $show_ip (default true) --}}
@if ($node['device'])
    {!! \LibreNMS\Util\Url::deviceLink($node['device']) !!}
@else
    <span class="text-muted" title="VTEP {{ $node['vtep_ip'] }}, not monitored by the plugin">{{ $node['name'] }}</span>
@endif
@if (($show_ip ?? true) && $node['name'] !== $node['vtep_ip'])<small class="text-muted">{{ $node['vtep_ip'] }}</small>@endif
