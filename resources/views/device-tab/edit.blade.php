{{-- Edit section (admin only): polling switch, transport, port and credential overrides. --}}
<div class="row">
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-heading"><i class="fa fa-key fa-fw" aria-hidden="true"></i> <strong>Settings for this device</strong> <small class="text-muted">— empty fields keep their value, secrets are encrypted and never shown</small></div>
            <div class="panel-body">
                @include('netconf::device-tab.result')

                <table class="table table-condensed">
                    <tr><th style="width: 30%;">Effective</th><th>Value</th></tr>
                    @foreach ($effective as $k => $v)
                        <tr><td>{{ $k }}</td><td><code>{{ $v }}</code>@if (isset($overrides[$k === 'key_file' ? 'keyfile' : $k])) <small class="text-info">(device override)</small>@endif</td></tr>
                    @endforeach
                </table>

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
                                <option value="auto">auto — NETCONF subsystem when offered, else exec</option>
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
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
