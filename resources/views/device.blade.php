@extends('layouts.librenmsv1')

@section('title', 'NETCONF - ' . $device->displayName())

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><i class="fa fa-terminal fa-fw" aria-hidden="true"></i> <strong>NETCONF</strong> — {!! \LibreNMS\Util\Url::deviceLink($device) !!}
                    <span class="pull-right"><a href="{{ route('netconf.status') }}">all devices</a></span>
                </div>
                <div class="panel-body">
                    @if ($result)
                        <div class="alert alert-{{ $result['type'] }}">
                            <strong>{{ $result['title'] }}</strong>
                            <ul style="margin-bottom: 0;">
                                @foreach ($result['lines'] as $line)<li>{{ $line }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>
                    @endif

                    <table class="table table-condensed">
                        <tr><th style="width: 40%;">Polling</th><td>
                            @if ($enabled)<span class="label label-success">enabled</span>@else<span class="label label-default">disabled</span>@endif
                            @if ($enabled_attrib === null)<small class="text-muted">(global default)</small>@endif
                            @if ($skip_reason)<br><small class="text-warning">{{ $skip_reason }}</small>@endif
                        </td></tr>
                        <tr><th>Matching definitions</th><td>@foreach ($definitions as $d)<span class="label label-info" title="{{ $d['description'] }}">{{ $d['name'] }}</span> @endforeach</td></tr>
                        <tr><th>Stored</th><td>
                            @foreach ($sensor_summary as $class => $n){{ $n }} {{ $class }} sensors, @endforeach
                            {{ $metric_rows }} metric rows, {{ $port_rows }} port rows
                            @if ($metric_rows || $port_rows)<br><a href="{{ route('netconf.device.metrics', $device->device_id) }}">show metric tables</a>@endif
                        </td></tr>
                        @if ($status)
                            <tr><th>Transport</th><td>{{ $status->transport }}</td></tr>
                            <tr><th>Last OK</th><td>{{ $status->last_ok }} <small class="text-muted">{{ $status->last_ok?->diffForHumans() }}</small></td></tr>
                            <tr><th>Polls</th><td>{{ $status->poll_count }}, last {{ $status->last_duration !== null ? sprintf('%.2fs', $status->last_duration) : '' }}</td></tr>
                            @if ($status->consecutive_failures > 0)
                                <tr class="danger"><th>Failures</th><td>{{ $status->consecutive_failures }} consecutive
                                    @if ($status->inBackoff()), back-off until {{ $status->next_attempt }}@endif
                                    <br><small>{{ $status->last_error }}</small></td></tr>
                            @endif
                            @if ($status->last_summary)
                                <tr><th>Last run</th><td><small>@foreach ($status->last_summary as $k => $v){{ $k }}={{ $v }} @endforeach</small></td></tr>
                            @endif
                        @endif
                    </table>

                    @if ($can_admin)
                        <div class="btn-group">
                            <form method="post" action="{{ route('netconf.device.test', $device->device_id) }}" style="display: inline;">@csrf<button class="btn btn-default btn-sm"><i class="fa fa-plug"></i> Test connection</button></form>
                            <form method="post" action="{{ route('netconf.device.discover', $device->device_id) }}" style="display: inline;">@csrf<button class="btn btn-default btn-sm"><i class="fa fa-search"></i> Discover now</button></form>
                            <form method="post" action="{{ route('netconf.device.poll', $device->device_id) }}" style="display: inline;">@csrf<button class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Poll now</button></form>
                        </div>
                        <small class="text-muted">Discover creates/removes sensors, poll records values. Both run in this request and take a few seconds.</small>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><i class="fa fa-key fa-fw" aria-hidden="true"></i> <strong>Credentials for this device</strong></div>
                <div class="panel-body">
                    <table class="table table-condensed">
                        <tr><th>Effective</th><th>Value</th></tr>
                        @foreach ($effective as $k => $v)
                            <tr><td>{{ $k }}</td><td><code>{{ $v }}</code>@if (isset($overrides[$k === 'key_file' ? 'keyfile' : $k])) <small class="text-info">(device override)</small>@endif</td></tr>
                        @endforeach
                    </table>

                    @if ($can_admin)
                        <form method="post" action="{{ route('netconf.device.update', $device->device_id) }}" class="form-horizontal">
                            @csrf
                            <div class="form-group">
                                <label class="col-sm-4 control-label">Polling</label>
                                <div class="col-sm-8">
                                    <select name="enabled" class="form-control">
                                        <option value="inherit" {{ $enabled_attrib === null ? 'selected' : '' }}>global default</option>
                                        <option value="1" {{ $enabled_attrib === '1' ? 'selected' : '' }}>enabled</option>
                                        <option value="0" {{ $enabled_attrib === '0' ? 'selected' : '' }}>disabled</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">Transport</label>
                                <div class="col-sm-8">
                                    <select name="transport" class="form-control">
                                        <option value="">(keep: {{ $overrides['transport'] ?? 'global' }})</option>
                                        <option value="cli">cli — SSH exec "| display xml"</option>
                                        <option value="netconf">netconf — NETCONF subsystem</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">Port</label>
                                <div class="col-sm-8"><input type="number" name="port" class="form-control" min="1" max="65535" placeholder="{{ $overrides['port'] ?? 'global' }}"></div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">Username</label>
                                <div class="col-sm-8"><input type="text" name="username" class="form-control" autocomplete="off" placeholder="{{ $overrides['username'] ?? 'global' }}"></div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">Password</label>
                                <div class="col-sm-8"><input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="{{ isset($overrides['password']) ? 'stored, leave empty to keep' : 'global' }}"></div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">Private key file</label>
                                <div class="col-sm-8"><input type="text" name="keyfile" class="form-control" placeholder="{{ $overrides['keyfile'] ?? 'global' }}"></div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">Key passphrase</label>
                                <div class="col-sm-8"><input type="password" name="key_passphrase" class="form-control" autocomplete="new-password" placeholder="{{ isset($overrides['key_passphrase']) ? 'stored, leave empty to keep' : 'global' }}"></div>
                            </div>
                            @if ($overrides !== [])
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">Remove overrides</label>
                                    <div class="col-sm-8">
                                        @foreach ($overrides as $k => $v)
                                            <label class="checkbox-inline"><input type="checkbox" name="clear[]" value="{{ $k }}"> {{ $k }}</label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <div class="form-group">
                                <div class="col-sm-offset-4 col-sm-8">
                                    <button type="submit" class="btn btn-primary">Save</button>
                                    <small class="text-muted">Empty fields keep their value. Secrets are encrypted and never shown.</small>
                                </div>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
