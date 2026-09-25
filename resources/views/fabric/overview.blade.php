@if (($topo ?? '') === \SafferIt\LibrenmsNetconf\Http\Controllers\FabricController::TOPO_EAGLE)
    @include('netconf::fabric.overview-eagle')
@else
<div class="row">
    <div class="col-md-4">
        <table class="table table-condensed">
            <tr><th style="width: 45%;">Members</th><td>{{ $fabric['members'] }} <small class="text-muted">({{ $fabric['monitored'] }} NETCONF-polled{{ $fabric['devices'] > $fabric['monitored'] ? sprintf(', %d in LibreNMS without EVPN data', $fabric['devices'] - $fabric['monitored']) : '' }})</small><br>@include('netconf::fabric.roles', ['roles' => $fabric['roles'], 'border' => $fabric['border']])</td></tr>
            <tr><th>Health</th><td>@include('netconf::fabric.health', ['health' => $fabric['health'], 'fabric_id' => $fabric['id']])</td></tr>
            <tr><th>Checks</th><td>@include('netconf::fabric.checks-badge', ['checks' => $fabric['checks'], 'fabric_id' => $fabric['id']]) <small><a href="{{ route('netconf.fabric', [$fabric['id'], 'checks']) }}">all issues</a></small></td></tr>
            <tr><th>EVPN instances</th><td>{{ $fabric['totals']['instances'] }}</td></tr>
            <tr><th>VNIs</th><td>{{ $fabric['totals']['vnis'] }} <small class="text-muted">distinct over the monitored members</small></td></tr>
            <tr><th>ESIs</th><td>{{ $fabric['totals']['esis'] }}</td></tr>
            <tr><th>MACs</th><td>{{ number_format($fabric['totals']['local_macs']) }} local, {{ number_format($fabric['totals']['remote_macs']) }} remote <small class="text-muted">(sum over the monitored members' instances)</small></td></tr>
            <tr><th>VXLAN tunnels</th><td>{{ $fabric['totals']['tunnels'] }} <small class="text-muted">from monitored members</small></td></tr>
            <tr><th>EVPN neighbour edges</th><td>{{ $fabric['totals']['neighbors'] }}</td></tr>
            <tr><th>Last seen</th><td>{{ $fabric['last_seen'] }} <small class="text-muted">{{ $fabric['last_seen'] ? \Carbon\Carbon::parse($fabric['last_seen'])->diffForHumans() : '' }}</small></td></tr>
            @if ($fabric['notes'])
                <tr><th>Notes</th><td style="white-space: pre-line;">{{ $fabric['notes'] }}</td></tr>
            @endif
        </table>

        @include('netconf::fabric.rename')
    </div>
    <div class="col-md-8">
        @include('netconf::fabric.topology')
    </div>
</div>
@endif
