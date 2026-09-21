@extends('layouts.librenmsv1')

@section('title', 'EVPN fabrics')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <i class="fa fa-sitemap fa-fw" aria-hidden="true"></i> <strong>EVPN fabrics</strong>
                    <span class="pull-right">
                        <a href="{{ route('netconf.evpn.mac') }}">MAC search</a>
                        &middot; <a href="{{ route('netconf.status') }}">NETCONF devices</a>
                        @if ($can_admin)
                            &middot; <a href="{{ url('plugin/settings/netconf') }}">settings</a>
                        @endif
                    </span>
                </div>
                <div class="panel-body">
                    @if (! $fabric_enabled)
                        <div class="alert alert-warning">The <em>EVPN fabric view</em> setting is off: the fabric tables are not collected and the fabrics are not recomputed. The data below is what was resolved while it was on.</div>
                    @endif
                    <p class="text-muted">
                        A fabric is one connected component of the EVPN graph: VXLAN endpoints, their EVPN neighbours, ESI peers, overlay BGP sessions and confirmed underlay links.
                        Members without a monitored device are counted as unknown VTEPs; add them to LibreNMS with the plugin enabled to fill the gaps.
                    </p>
                    @if ($fabrics === [])
                        <p>No fabrics resolved yet. Poll a leaf with the fabric view enabled, or run <code>lnms netconf:fabric --resolve</code>.</p>
                    @else
                        <table class="table table-condensed table-hover">
                            <thead>
                                <tr>
                                    <th>Fabric</th>
                                    <th>Key</th>
                                    <th class="text-right">Members</th>
                                    <th>Roles</th>
                                    <th class="text-right">Instances</th>
                                    <th class="text-right">VNIs</th>
                                    <th class="text-right">ESIs</th>
                                    <th class="text-right">MACs local / remote</th>
                                    <th>Health</th>
                                    <th>Checks</th>
                                    <th>Last seen</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($fabrics as $f)
                                    <tr class="{{ $f['checks']['critical'] > 0 ? 'danger' : ($f['issues'] > 0 || $f['checks']['warning'] > 0 ? 'warning' : '') }}">
                                        <td>
                                            <a href="{{ route('netconf.fabric', $f['id']) }}"><strong>{{ $f['name'] }}</strong></a>
                                            @if ($f['notes'])<br><small class="text-muted">{{ \Illuminate\Support\Str::limit($f['notes'], 80) }}</small>@endif
                                        </td>
                                        <td><code>{{ $f['key'] }}</code></td>
                                        <td class="text-right">{{ $f['members'] }} <small class="text-muted">({{ $f['monitored'] }} monitored)</small></td>
                                        <td>@include('netconf::fabric.roles', ['roles' => $f['roles'], 'border' => $f['border']])</td>
                                        <td class="text-right">{{ $f['totals']['instances'] }}</td>
                                        <td class="text-right">{{ $f['totals']['vnis'] }}</td>
                                        <td class="text-right">{{ $f['totals']['esis'] }}</td>
                                        <td class="text-right">{{ number_format($f['totals']['local_macs']) }} / {{ number_format($f['totals']['remote_macs']) }}</td>
                                        <td>@include('netconf::fabric.health', ['health' => $f['health'], 'fabric_id' => $f['id']])</td>
                                        <td>@include('netconf::fabric.checks-badge', ['checks' => $f['checks'], 'fabric_id' => $f['id']])</td>
                                        <td><small>{{ $f['last_seen'] ? \Carbon\Carbon::parse($f['last_seen'])->diffForHumans() : '' }}</small></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
