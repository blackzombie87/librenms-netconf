{{-- Eagle overview (plan §11): full width, because a side column would squeeze the viewport
     below the width the layout was computed for and any camera that then fitted the SVG would
     scale the 11 px labels down with it. $shape, $eagle, $eagle_input, $focus, $view. --}}
@php($counts = $eagle['counts'])
<div class="row">
    <div class="col-md-12">
        <p style="margin-bottom: 6px;">
            <span class="label label-primary" title="{{ $shape['evidence'] }}">{{ $shape['badge'] }}</span>
            @include('netconf::fabric.roles', ['roles' => $fabric['roles'], 'border' => $fabric['border']])
            @include('netconf::fabric.health', ['health' => $fabric['health'], 'fabric_id' => $fabric['id']])
            @include('netconf::fabric.checks-badge', ['checks' => $fabric['checks'], 'fabric_id' => $fabric['id']])
            <small class="text-muted pull-right">last seen {{ $fabric['last_seen'] }}</small>
        </p>
        <p class="text-muted" style="margin-bottom: 10px;"><small>@foreach ($eagle['sentences'] as $sentence){{ $sentence }} @endforeach</small></p>

        @include('netconf::fabric.topology-eagle')
        @include('netconf::fabric.inspector')

        <p class="text-muted" style="margin-top: 10px;"><small>
            {{ $fabric['members'] }} members ({{ $fabric['monitored'] }} NETCONF-polled) &middot;
            {{ $fabric['totals']['instances'] }} EVPN instances &middot;
            {{ $fabric['totals']['vnis'] }} VNIs &middot;
            {{ $fabric['totals']['esis'] }} ESIs &middot;
            {{ number_format($fabric['totals']['local_macs']) }} local / {{ number_format($fabric['totals']['remote_macs']) }} remote MACs &middot;
            {{ $fabric['totals']['tunnels'] }} VXLAN tunnels &middot;
            {{ $fabric['totals']['neighbors'] }} EVPN neighbour edges
            @if ($fabric['notes'])<br>{{ $fabric['notes'] }}@endif
        </small></p>

        @if ($can_admin)
            <details style="margin-top: 8px;">
                <summary class="text-muted"><small>Name and notes</small></summary>
                <div style="margin-top: 8px;">@include('netconf::fabric.rename')</div>
            </details>
        @endif
    </div>
</div>
