{{-- one fabric node: $node (FabricNodes::get()), optional $show_ip (default true) and
     $plain (default false: a device link without core's overlib tooltip, which costs about
     2 kB per link and is repeated per row in the big tables) --}}
@if ($node['device'] && ($plain ?? false))
    {{-- core's deviceLink() carries a ~2 kB overlib tooltip (or, with overlib off, the same
         markup inline), which is repeated per row in the big tables --}}
    @if (\Illuminate\Support\Facades\Gate::allows('view', $node['device']))
        <a href="{{ \LibreNMS\Util\Url::deviceUrl($node['device']) }}" class="list-device">{{ $node['device']->display }}</a>
    @else
        {{ $node['device']->display }}
    @endif
@elseif ($node['device'])
    {!! \LibreNMS\Util\Url::deviceLink($node['device']) !!}
@else
    <span class="text-muted" title="VTEP {{ $node['vtep_ip'] }}, not monitored by the plugin">{{ $node['name'] }}</span>
@endif
@if (($show_ip ?? true) && $node['name'] !== $node['vtep_ip'])<small class="text-muted">{{ $node['vtep_ip'] }}</small>@endif
