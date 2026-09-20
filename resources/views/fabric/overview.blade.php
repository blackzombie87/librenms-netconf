<div class="row">
    <div class="col-md-4">
        <table class="table table-condensed">
            <tr><th style="width: 45%;">Members</th><td>{{ $fabric['members'] }} <small class="text-muted">({{ $fabric['monitored'] }} monitored)</small><br>@include('netconf::fabric.roles', ['roles' => $fabric['roles'], 'border' => $fabric['border']])</td></tr>
            <tr><th>Health</th><td>@include('netconf::fabric.health', ['health' => $fabric['health'], 'fabric_id' => $fabric['id']])</td></tr>
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

        @if ($can_admin)
            <form method="post" action="{{ route('netconf.fabric.update', $fabric['id']) }}" class="form-horizontal">
                @csrf
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="fabric-name">Name</label>
                    <div class="col-sm-9"><input type="text" id="fabric-name" name="name" class="form-control input-sm" maxlength="64" value="{{ old('name', $fabric['name']) }}" required></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="fabric-notes">Notes</label>
                    <div class="col-sm-9"><textarea id="fabric-notes" name="notes" class="form-control input-sm" rows="2" maxlength="2000">{{ old('notes', $fabric['notes']) }}</textarea></div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-9"><button type="submit" class="btn btn-primary btn-sm">Save</button> <small class="text-muted">The key and the members stay automatic.</small></div>
                </div>
            </form>
        @endif
    </div>
    <div class="col-md-8">
        @include('netconf::fabric.topology')
    </div>
</div>
