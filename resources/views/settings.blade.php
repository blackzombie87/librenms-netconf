<div style="margin: 15px;">
    <h4>NETCONF plugin settings</h4>

    <p>
        Global defaults for all devices. Per-device overrides (user, secret, port, transport) are
        stored as device attributes; test a device with
        <code>lnms netconf:test &lt;device&gt;</code> and run ad-hoc commands with
        <code>lnms netconf:run &lt;device&gt; show version</code>.
    </p>

    <form method="post">
        @csrf
        <table class="table table-condensed" style="max-width: 900px;">
            <tr>
                <th style="width: 220px;">Setting</th>
                <th>Value</th>
                <th style="width: 260px;">Currently effective</th>
            </tr>

            <tr>
                <td colspan="3"><strong>Connection</strong></td>
            </tr>
            <tr>
                <td><label for="transport">Transport</label></td>
                <td>
                    <select class="form-control" id="transport" name="settings[transport]">
                        <option value="" {{ ($settings['transport'] ?? '') === '' ? 'selected' : '' }}>(default: auto)</option>
                        <option value="auto" {{ ($settings['transport'] ?? '') === 'auto' ? 'selected' : '' }}>auto &mdash; NETCONF subsystem on the SSH port when offered, else exec</option>
                        <option value="cli" {{ ($settings['transport'] ?? '') === 'cli' ? 'selected' : '' }}>cli &mdash; SSH exec "show … | display xml"</option>
                        <option value="netconf" {{ ($settings['transport'] ?? '') === 'netconf' ? 'selected' : '' }}>netconf &mdash; NETCONF subsystem (RFC 6242)</option>
                    </select>
                </td>
                <td><code>{{ $effective['transport'] }}</code></td>
            </tr>
            <tr>
                <td><label for="port">SSH port</label></td>
                <td><input class="form-control" id="port" type="number" min="1" max="65535" name="settings[port]"
                           value="{{ $settings['port'] ?? '' }}" placeholder="22 (auto, cli) / 830 (netconf)"></td>
                <td><code>{{ $effective['port'] }}</code></td>
            </tr>
            <tr>
                <td><label for="known_hosts">Host key check (known_hosts)</label></td>
                <td><input class="form-control" id="known_hosts" type="text" name="settings[known_hosts]"
                           value="{{ $settings['known_hosts'] ?? '' }}" placeholder="/opt/librenms/.ssh/known_hosts — empty: host keys are not verified"></td>
                <td><code>{{ $effective['known_hosts'] !== '' ? $effective['known_hosts'] : 'not verified' }}</code></td>
            </tr>
            <tr>
                <td><label for="connect_timeout">Connect timeout (s)</label></td>
                <td><input class="form-control" id="connect_timeout" type="number" min="1" name="settings[connect_timeout]"
                           value="{{ $settings['connect_timeout'] ?? '' }}" placeholder="{{ $defaults['connect_timeout'] }}"></td>
                <td><code>{{ $effective['connect_timeout'] }}</code></td>
            </tr>
            <tr>
                <td><label for="command_timeout">Command timeout (s)</label></td>
                <td><input class="form-control" id="command_timeout" type="number" min="1" name="settings[command_timeout]"
                           value="{{ $settings['command_timeout'] ?? '' }}" placeholder="{{ $defaults['command_timeout'] }}"></td>
                <td><code>{{ $effective['command_timeout'] }}</code></td>
            </tr>

            <tr>
                <td colspan="3"><strong>Authentication</strong> (device attributes override these)</td>
            </tr>
            <tr>
                <td><label for="username">Username</label></td>
                <td><input class="form-control" id="username" type="text" name="settings[username]" autocomplete="off"
                           value="{{ $settings['username'] ?? '' }}"></td>
                <td><code>{{ $effective['username'] !== '' ? $effective['username'] : '(none)' }}</code></td>
            </tr>
            <tr>
                <td><label for="password">Password</label></td>
                <td>
                    <input class="form-control" id="password" type="password" name="settings[password]" autocomplete="new-password"
                           value="" placeholder="{{ $secret_state['password'] ? 'leave empty to keep the stored password' : 'not set' }}">
                    @if ($secret_state['password'])
                        <label style="font-weight: normal; margin-top: 4px;">
                            <input type="checkbox" name="settings[password_clear]" value="1"> remove stored password
                        </label>
                    @endif
                </td>
                <td><code>{{ $secret_state['password'] ? 'set (encrypted)' : 'missing' }}</code></td>
            </tr>
            <tr>
                <td><label for="key_file">Private key file</label></td>
                <td><input class="form-control" id="key_file" type="text" name="settings[key_file]"
                           value="{{ $settings['key_file'] ?? '' }}" placeholder="/opt/librenms/.ssh/netconf_ed25519 (readable by the poller user)"></td>
                <td><code>{{ $effective['key_file'] !== '' ? $effective['key_file'] : '(none)' }}</code></td>
            </tr>
            <tr>
                <td><label for="key_passphrase">Key passphrase</label></td>
                <td>
                    <input class="form-control" id="key_passphrase" type="password" name="settings[key_passphrase]" autocomplete="new-password"
                           value="" placeholder="{{ $secret_state['key_passphrase'] ? 'leave empty to keep the stored passphrase' : 'not set' }}">
                    @if ($secret_state['key_passphrase'])
                        <label style="font-weight: normal; margin-top: 4px;">
                            <input type="checkbox" name="settings[key_passphrase_clear]" value="1"> remove stored passphrase
                        </label>
                    @endif
                </td>
                <td><code>{{ $secret_state['key_passphrase'] ? 'set (encrypted)' : 'missing' }}</code></td>
            </tr>
            <tr>
                <td><label for="auth_order">Auth order</label></td>
                <td>
                    <select class="form-control" id="auth_order" name="settings[auth_order]">
                        <option value="" {{ ($settings['auth_order'] ?? '') === '' ? 'selected' : '' }}>(default: key, then password)</option>
                        <option value="key,password" {{ ($settings['auth_order'] ?? '') === 'key,password' ? 'selected' : '' }}>key, then password</option>
                        <option value="password,key" {{ ($settings['auth_order'] ?? '') === 'password,key' ? 'selected' : '' }}>password, then key</option>
                        <option value="key" {{ ($settings['auth_order'] ?? '') === 'key' ? 'selected' : '' }}>key only</option>
                        <option value="password" {{ ($settings['auth_order'] ?? '') === 'password' ? 'selected' : '' }}>password only</option>
                    </select>
                </td>
                <td><code>{{ $effective['auth_order'] }}</code></td>
            </tr>

            <tr>
                <td colspan="3"><strong>Polling</strong></td>
            </tr>
            <tr>
                <td><label for="enable_by_default">Enable for all devices</label></td>
                <td>
                    <input type="hidden" name="settings[enable_by_default]" value="0">
                    <label style="font-weight: normal;">
                        <input type="checkbox" id="enable_by_default" name="settings[enable_by_default]" value="1"
                               {{ filter_var($settings['enable_by_default'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                        poll every matching device unless disabled per device (otherwise opt-in per device)
                    </label>
                </td>
                <td><code>{{ $effective['enable_by_default'] ? 'yes' : 'no' }}</code></td>
            </tr>
            <tr>
                <td><label for="poll_budget">Poll budget per device (s)</label></td>
                <td><input class="form-control" id="poll_budget" type="number" min="1" name="settings[poll_budget]"
                           value="{{ $settings['poll_budget'] ?? '' }}" placeholder="{{ $defaults['poll_budget'] }}"></td>
                <td><code>{{ $effective['poll_budget'] }}</code></td>
            </tr>
            <tr>
                <td><label for="backoff_max">Back-off maximum (polls)</label></td>
                <td><input class="form-control" id="backoff_max" type="number" min="1" name="settings[backoff_max]"
                           value="{{ $settings['backoff_max'] ?? '' }}" placeholder="{{ $defaults['backoff_max'] }}"></td>
                <td><code>{{ $effective['backoff_max'] }}</code></td>
            </tr>
            <tr>
                <td><label for="evpn_fabric">EVPN fabric view</label></td>
                <td>
                    <input type="hidden" name="settings[evpn_fabric]" value="0">
                    <label style="font-weight: normal;">
                        <input type="checkbox" id="evpn_fabric" name="settings[evpn_fabric]" value="1"
                               {{ filter_var($settings['evpn_fabric'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                        collect the per-leaf EVPN tables (definitions with <code>tables:</code>, four extra commands per leaf) and resolve fabrics
                    </label>
                </td>
                <td><code>{{ $effective['evpn_fabric'] ? 'yes' : 'no' }}</code></td>
            </tr>
            <tr>
                <td><label for="evpn_links">ESI peers as neighbours</label></td>
                <td>
                    <input type="hidden" name="settings[evpn_links]" value="0">
                    <label style="font-weight: normal;">
                        <input type="checkbox" id="evpn_links" name="settings[evpn_links]" value="1"
                               {{ filter_var($settings['evpn_links'] ?? $defaults['evpn_links'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                        write the EVPN multihoming peer of every ESI-LAG to the core <code>links</code> table (protocol <code>evpn-esi</code>), so it appears in the device's Neighbours tab and on the map; needs the fabric view
                    </label>
                </td>
                <td><code>{{ $effective['evpn_links'] ? 'yes' : 'no' }}</code></td>
            </tr>
            <tr>
                <td><label for="evpn_mac_moves">MAC mobility limit (moves / hour)</label></td>
                <td><input class="form-control" id="evpn_mac_moves" type="number" min="0" name="settings[evpn_mac_moves]"
                           value="{{ $settings['evpn_mac_moves'] ?? '' }}" placeholder="{{ $defaults['evpn_mac_moves'] }} — 0 turns the check off"></td>
                <td><code>{{ $effective['evpn_mac_moves'] }}</code></td>
            </tr>
            <tr>
                <td><label for="definitions_dir">User definitions directory</label></td>
                <td><input class="form-control" id="definitions_dir" type="text" name="settings[definitions_dir]"
                           value="{{ $settings['definitions_dir'] ?? '' }}" placeholder="storage/app/netconf-definitions"></td>
                <td><code>{{ $effective['definitions_dir'] !== '' ? $effective['definitions_dir'] : '(default)' }}</code></td>
            </tr>
        </table>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    <p style="margin-top: 15px;">
        Secrets are encrypted with the LibreNMS <code>APP_KEY</code> before they are stored and are
        never shown again. With a known_hosts file every device's host key must be listed there
        (<code>ssh-keyscan -p &lt;port&gt; &lt;device&gt; &gt;&gt; known_hosts</code> after checking the
        fingerprint on the console); <code>lnms netconf:test</code> prints the fingerprint.
    </p>
</div>
