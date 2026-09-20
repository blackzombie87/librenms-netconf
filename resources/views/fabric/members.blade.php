<table class="table table-condensed table-hover">
    <thead>
        <tr>
            <th>Member</th>
            <th>VTEP / router-id</th>
            <th>Role</th>
            <th>Platform</th>
            <th>Location</th>
            <th>Instances</th>
            <th class="text-right">VNIs</th>
            <th class="text-right">ESI-LAGs</th>
            <th class="text-right">MACs local / remote</th>
            <th class="text-right">Neighbours</th>
            <th class="text-right">Tunnels</th>
            <th>Plugin</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($members as $m)
            <tr class="{{ $m['failures'] > 0 ? 'danger' : ($m['device'] === null ? 'text-muted' : '') }}">
                <td>
                    @if ($m['device'])
                        {!! \LibreNMS\Util\Url::deviceLink($m['device']) !!}
                        <small><a href="{{ route('netconf.device', $m['device_id']) }}" title="plugin page"><i class="fa fa-terminal" aria-hidden="true"></i></a></small>
                    @else
                        <span title="not monitored by the plugin">{{ $m['name'] }}</span>
                        <br><small class="text-muted">unknown VTEP — add the device to LibreNMS with the plugin enabled</small>
                    @endif
                    @if ($m['pinned'])<span class="label label-default" title="manual assignment, survives recompute">pinned</span>@endif
                </td>
                <td>
                    <code>{{ $m['vtep_ip'] }}</code>
                    @if ($m['router_id'] && $m['router_id'] !== $m['vtep_ip'])<br><small class="text-muted">router-id {{ $m['router_id'] }}</small>@endif
                    @foreach ($m['aliases'] as $alias)<br><small class="text-muted">also {{ $alias }}</small>@endforeach
                </td>
                <td>
                    @php($class = match ($m['role']) { 'gateway' => 'primary', 'spine' => 'info', 'leaf' => 'success', default => 'default' })
                    <span class="label label-{{ $class }}">{{ $m['role'] }}</span>
                    @if ($m['border'])<span class="label label-warning" title="L3 contexts / type-5 prefixes">border</span>@endif
                </td>
                <td><small>{{ $m['hardware'] }}@if ($m['version'])<br>{{ $m['version'] }}@endif</small></td>
                <td><small>{{ $m['location'] }}</small></td>
                <td><small>{{ implode(', ', $m['instances']) }}</small></td>
                <td class="text-right">{{ $m['stats']['vnis'] ?? '' }}@if (($m['stats']['irbs'] ?? 0) > 0) <small class="text-muted">({{ $m['stats']['irbs'] }} IRB)</small>@endif</td>
                <td class="text-right">@if ($m['stats']){{ $m['stats']['esis'] }} <small class="text-muted">DF {{ $m['stats']['esis_df'] }}</small>@endif</td>
                <td class="text-right">@if ($m['stats'] && $m['stats']['local_macs'] !== null){{ number_format($m['stats']['local_macs']) }} / {{ number_format($m['stats']['remote_macs']) }}@endif</td>
                <td class="text-right">{{ $m['stats']['neighbors'] ?? '' }}</td>
                <td class="text-right">{{ $m['stats']['tunnels'] ?? '' }}</td>
                <td>
                    @if ($m['device'] === null)
                        &mdash;
                    @elseif ($m['failures'] > 0)
                        <span class="label label-danger" title="{{ $m['last_error'] }}">{{ $m['failures'] }} failures</span>
                    @elseif ($m['last_ok'])
                        <span class="label label-success">ok</span> <small class="text-muted">{{ \Carbon\Carbon::parse($m['last_ok'])->diffForHumans() }}</small>
                    @else
                        <span class="label label-warning">not polled</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
