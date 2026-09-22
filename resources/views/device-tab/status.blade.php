{{-- Status section: polling state, last run, fabric membership, what is stored, and the
     admin actions. Credentials are on the edit section. --}}
<div class="row">
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-body">
                @include('netconf::device-tab.result')

                <table class="table table-condensed">
                    <tr><th style="width: 30%;">Polling</th><td>
                        @if ($enabled)<span class="label label-success">enabled</span>@else<span class="label label-default">disabled</span>@endif
                        @if ($enabled_attrib === null)<small class="text-muted">(global default)</small>@endif
                        @if ($skip_reason)<br><small class="text-warning">{{ $skip_reason }}</small>@endif
                    </td></tr>
                    @if ($status)
                        <tr><th>Transport</th><td>{{ $status->transport }}</td></tr>
                        <tr><th>Last OK</th><td>{{ $status->last_ok }} <small class="text-muted">{{ $status->last_ok?->diffForHumans() }}</small></td></tr>
                        <tr><th>Polls</th><td>{{ $status->poll_count }}</td></tr>
                        @if ($status->consecutive_failures > 0)
                            <tr class="danger"><th>Failures</th><td>{{ $status->consecutive_failures }} consecutive
                                @if ($status->inBackoff()), back-off until {{ $status->next_attempt }}@endif
                                <br><small>{{ $status->last_error }}</small></td></tr>
                        @endif
                        @if ($status->last_summary)
                            <tr><th>Last run</th><td>
                                {{ \SafferIt\LibrenmsNetconf\Support\RunSummary::line($status->last_summary, $status->last_duration) }}
                                <details class="netconf-fold tw:inline-block">
                                    <summary><small>details</small></summary>
                                    <small class="text-muted">@foreach ($status->last_summary as $k => $v){{ $k }}={{ $v }} @endforeach</small>
                                </details>
                            </td></tr>
                        @endif
                    @endif
                    @if ($fabric_badge)
                        <tr><th>EVPN fabric</th><td>@include('netconf::fabric.badge', ['badge' => $fabric_badge])</td></tr>
                    @endif
                    <tr><th>Definitions</th><td>
                        @if ($definitions === [])
                            <span class="text-muted">none match this device</span>
                        @else
                            <details class="netconf-fold">
                                <summary>{{ count($definitions) }} matching</summary>
                                <ul class="tw:mb-0">
                                    @foreach ($definitions as $d)<li><code>{{ $d['name'] }}</code> <small class="text-muted">{{ $d['description'] }}</small></li>@endforeach
                                </ul>
                            </details>
                        @endif
                    </td></tr>
                    <tr><th>Stored</th><td>
                        @if ($sensor_summary === [])
                            no sensors
                        @else
                            <a href="{{ route('device', [$device->device_id, 'health']) }}">{{ array_sum($sensor_summary) }} sensors</a>
                            <small class="text-muted">(@foreach ($sensor_summary as $class => $n){{ $n }} {{ $class }}@if (! $loop->last), @endif @endforeach)</small>
                        @endif
                        @if ($metric_rows) &middot; <a href="{{ $sections['metrics']['link'] }}">{{ $metric_rows }} metric rows</a>@endif
                        @if ($port_rows) &middot; <a href="{{ $sections['metrics']['link'] }}">{{ $port_rows }} port rows</a>@endif
                        @if ($fabric_badge && $fabric_badge['esis'] > 0 && isset($sections['esi'])) &middot; <a href="{{ $sections['esi']['link'] }}">{{ $fabric_badge['esis'] }} ESI-LAGs</a>@endif
                    </td></tr>
                </table>

                @if ($can_admin)
                    <div class="btn-group">
                        <form method="post" action="{{ route('netconf.device.test', $device->device_id) }}" class="tw:inline">@csrf<button class="btn btn-default btn-sm"><i class="fa fa-plug"></i> Test connection</button></form>
                        <form method="post" action="{{ route('netconf.device.discover', $device->device_id) }}" class="tw:inline">@csrf<button class="btn btn-default btn-sm"><i class="fa fa-search"></i> Discover now</button></form>
                        <form method="post" action="{{ route('netconf.device.poll', $device->device_id) }}" class="tw:inline">@csrf<button class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Poll now</button></form>
                    </div>
                    <small class="text-muted">Discover creates and removes sensors, poll records values. Both run in this request and take a few seconds.</small>
                @endif
            </div>
        </div>
    </div>
</div>
