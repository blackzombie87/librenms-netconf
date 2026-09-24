@include('netconf::fold-style')
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <i class="fa fa-terminal fa-fw" aria-hidden="true"></i> <strong>NETCONF devices</strong>
                <span class="pull-right">
                    <a href="{{ route('netconf.definitions') }}">definitions</a>
                    @if (\SafferIt\LibrenmsNetconf\Collect\NetconfService::fabricEnabled())
                        &middot; <a href="{{ route('netconf.fabrics') }}">EVPN fabrics</a>
                    @endif
                    @if ($can_admin)
                        &middot; <a href="{{ url('plugin/settings/netconf') }}">settings</a>
                    @endif
                </span>
            </div>
            <div class="panel-body">
                @if (! $module_ready)
                    <div class="alert alert-warning">The poller module class is not loadable. Run <code>composer dump-autoload</code> / re-install the plugin.</div>
                @endif
                <p class="text-muted">
                    Polling is {{ $default_on ? 'enabled for all devices by default' : 'opt-in per device' }}
                    (transport <code>{{ $settings['transport'] }}</code>, port <code>{{ $settings['port'] }}</code>, user <code>{{ $settings['username'] !== '' ? $settings['username'] : '(none)' }}</code>).
                    Devices that are neither enabled nor have been polled are not listed.
                </p>
                @if ($rows === [])
                    <p>No devices enabled yet. Use <code>lnms netconf:device &lt;device&gt; --enable</code> or the NETCONF page of a device.</p>
                @else
                    <table class="table table-condensed table-hover">
                        <thead>
                            <tr>
                                <th>Device</th>
                                <th>Enabled</th>
                                <th>Transport</th>
                                <th>Definitions</th>
                                <th>Last OK</th>
                                <th>Polls</th>
                                <th>Failures</th>
                                <th>Last result</th>
                                <th>Overrides</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php($st = $row['status'])
                                <tr class="{{ $st && $st->consecutive_failures > 0 ? 'danger' : ($row['enabled'] ? '' : 'text-muted') }}">
                                    <td>
                                        <a href="{{ \SafferIt\LibrenmsNetconf\Support\DevicePage::url($row['device']->device_id) }}">{{ $row['device']->displayName() }}</a>
                                        <small><a href="{{ \LibreNMS\Util\Url::deviceUrl($row['device']) }}" title="device page"><i class="fa fa-external-link" aria-hidden="true"></i></a></small>
                                    </td>
                                    <td>
                                        @if ($row['enabled'])
                                            <span class="label label-success">yes</span>
                                        @else
                                            <span class="label label-default">no</span>
                                        @endif
                                        @if ($row['inherited'])<small class="text-muted">(default)</small>@endif
                                    </td>
                                    <td>{{ $st?->transport }}</td>
                                    <td>
                                        @if ($st?->definitions)
                                            <details class="netconf-fold">
                                                <summary>{{ count($st->definitions) }}</summary>
                                                <small>@foreach ($st->definitions as $name)<code>{{ $name }}</code><br>@endforeach</small>
                                            </details>
                                        @endif
                                    </td>
                                    <td>{{ $st?->last_ok?->diffForHumans() }}</td>
                                    <td>{{ $st?->poll_count }}</td>
                                    <td>
                                        @if ($st && $st->consecutive_failures > 0)
                                            <span class="label label-danger">{{ $st->consecutive_failures }}</span>
                                            @if ($st->inBackoff())<small>back-off until {{ $st->next_attempt->format('H:i:s') }}</small>@endif
                                        @elseif ($st)
                                            0
                                        @endif
                                    </td>
                                    <td>
                                        @if ($st?->last_error)
                                            <span class="text-danger" title="{{ $st->last_error }}">{{ \Illuminate\Support\Str::limit($st->last_error, 60) }}</span>
                                        @elseif ($st?->last_summary)
                                            <small>{{ \SafferIt\LibrenmsNetconf\Support\RunSummary::line($st->last_summary, $st->last_duration) }}</small>
                                            @if (($st->last_summary['commands_failed'] ?? 0) > 0)<i class="fa fa-exclamation-triangle text-danger" title="commands failed in the last run" aria-hidden="true"></i>@endif
                                        @endif
                                    </td>
                                    <td>
                                        @foreach ($row['overrides'] as $k => $v)
                                            <small><code>{{ $k }}</code>=<span>{{ $v }}</span></small>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @if ($can_admin)
            <div class="panel panel-default">
                <div class="panel-heading"><i class="fa fa-toggle-on fa-fw" aria-hidden="true"></i> <strong>Enable per device group or os</strong> <small class="text-muted">— sets <code>netconf_enabled</code> on every device of the selection, like <code>lnms netconf:device --group/--os</code>; discovery creates the sensors afterwards</small></div>
                <div class="panel-body">
                    {{-- nothing is pre-selected: an untouched Apply must not write an attribute on every device --}}
                    <form method="post" action="{{ route('netconf.bulk') }}" class="form-inline" onsubmit="return this.group.value !== '' || this.os.value !== '' || confirm('No device group and no os selected: this applies to every device. Continue?');">
                        @csrf
                        <select name="group" class="form-control">
                            <option value="">any device group</option>
                            @foreach ($bulk_groups as $id => $name)
                                <option value="{{ $id }}" {{ (string) old('group') === (string) $id ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                        <select name="os" class="form-control">
                            <option value="">any os</option>
                            @foreach ($bulk_os as $os)
                                <option value="{{ $os }}" {{ (string) old('os') === $os ? 'selected' : '' }}>{{ $os }}</option>
                            @endforeach
                        </select>
                        <select name="enabled" class="form-control" required>
                            <option value="">&mdash; choose &mdash;</option>
                            <option value="1" {{ old('enabled') === '1' ? 'selected' : '' }}>enable</option>
                            <option value="0" {{ old('enabled') === '0' ? 'selected' : '' }}>disable</option>
                            <option value="inherit" {{ old('enabled') === 'inherit' ? 'selected' : '' }}>global default</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </form>
                    <small class="text-muted">One device at a time: open it and use the <strong>NETCONF</strong> tab &mdash;
                        the tab is offered on every device a definition matches, with an <em>Enable</em> button on its status section
                        (<code>lnms netconf:device &lt;device&gt; --enable</code> does the same).</small>
                    @if ($bulk)
                        @if ($bulk['error'])
                            <div class="alert alert-danger tw:mt-2">{{ $bulk['error'] }}</div>
                        @else
                            <div class="alert alert-success tw:mt-2">
                                {{ $bulk['devices'] }} device(s) match {{ $bulk['selection'] }}, {{ $bulk['changed'] }} changed
                                ({{ ['1' => 'enabled', '0' => 'disabled', 'inherit' => 'global default'][$bulk['action']] ?? $bulk['action'] }}).
                                @if ($bulk['action'] === '1' && $bulk['changed'] > 0)
                                    Run <code>lnms device:discover all -m netconf</code> or wait for the next discovery to create the sensors.
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><i class="fa fa-code fa-fw" aria-hidden="true"></i> <strong>Run a show command</strong> <small class="text-muted">— the reply as the plugin sees it, handy while writing definitions; a single <code>show …</code> without pipes, <code>show configuration</code> excluded</small></div>
                <div class="panel-body">
                    <form method="post" action="{{ route('netconf.run') }}" class="form-inline">
                        @csrf
                        <select name="device_id" class="form-control" required>
                            @foreach ($run_devices as $device)
                                <option value="{{ $device->device_id }}" {{ (int) old('device_id', $run['device_id'] ?? 0) === $device->device_id ? 'selected' : '' }}>{{ $device->displayName() }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="command" class="form-control" style="width: 40%;" placeholder="show evpn instance extensive" value="{{ old('command', $run['command'] ?? '') }}" required>
                        <select name="transport" class="form-control">
                            <option value="">device transport</option>
                            <option value="auto">auto</option>
                            <option value="cli">cli</option>
                            <option value="netconf">netconf</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Run</button>
                    </form>
                    @if ($errors->any())
                        <div class="alert alert-danger tw:mt-2">{{ implode(' ', $errors->all()) }}</div>
                    @endif
                    @if ($run)
                        <div class="tw:mt-4">
                            @if ($run['ok'])
                                <p><strong>{{ $run['device'] }}</strong>: <code>{{ $run['command'] }}</code> — {{ $run['bytes'] }} bytes in {{ sprintf('%.2f', $run['duration']) }}s</p>
                                <pre style="max-height: 600px; overflow: auto;">{{ $run['xml'] }}</pre>
                            @else
                                <div class="alert alert-danger"><strong>{{ $run['device'] }}</strong>: <code>{{ $run['command'] }}</code><br>{{ $run['message'] }}</div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
