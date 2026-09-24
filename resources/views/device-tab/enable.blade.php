{{-- Onboarding panel (plan §9.2 W2): the one web path that enables a single device. Admin only,
     rendered while the device is not enabled; it posts the same form the edit section posts. --}}
<div class="panel panel-info">
    <div class="panel-heading">
        <i class="fa fa-plug fa-fw" aria-hidden="true"></i>
        <strong>NETCONF is not enabled for this device</strong>
    </div>
    <div class="panel-body">
        @if ($definitions === [])
            <p>No shipped definition matches this device, so enabling it would collect nothing.
                <a href="{{ \SafferIt\LibrenmsNetconf\Support\PluginRoutes::to('netconf.definitions') }}">See the definitions</a>.</p>
        @else
            @php
                $shown = array_slice($definitions, 0, 4);
                $names = implode(', ', array_map(fn ($d) => $d['name'], $shown));
                $more = count($definitions) - count($shown);
            @endphp
            <p>
                {{ count($definitions) }} definition(s) match this device
                (<code>{{ $names }}</code>{{ $more > 0 ? ", and $more more" : '' }}).
                Enabling records them as sensors and metrics on the next discovery.
            </p>
        @endif

        @if ($onboarding['attrib'] === '0')
            <p><small class="text-warning">Polling is switched off on this device specifically; the global default does not apply.</small></p>
        @endif

        <table class="table table-condensed tw:mb-2">
            <tr>
                <th style="width: 30%;">Login</th>
                <td>
                    @if (isset($onboarding['credentials']['error']))
                        <span class="text-danger">{{ $onboarding['credentials']['error'] }}</span>
                    @else
                        @if ($onboarding['credentials']['username'])
                            <code>{{ $onboarding['credentials']['username'] }}</code>&#64;{{ $onboarding['credentials']['host'] }}:{{ $onboarding['credentials']['port'] }}
                        @else
                            {{ $onboarding['credentials']['host'] }}:{{ $onboarding['credentials']['port'] }}
                            <span class="text-warning">no username configured</span>
                        @endif
                        <small class="text-muted">
                            transport {{ $onboarding['credentials']['transport'] }},
                            password {{ $onboarding['credentials']['password'] }},
                            key {{ $onboarding['credentials']['key_file'] }}
                            ({{ $onboarding['has_overrides'] ? 'per-device override' : 'global settings' }})
                        </small>
                    @endif
                </td>
            </tr>
        </table>

        <form method="post" action="{{ route('netconf.device.update', $device->device_id) }}" class="tw:inline">
            @csrf
            <input type="hidden" name="enabled" value="1">
            <button class="btn btn-primary btn-sm"><i class="fa fa-check" aria-hidden="true"></i> Enable NETCONF for this device</button>
        </form>
        <a href="{{ $sections['edit']['link'] ?? '#' }}" class="btn btn-default btn-sm"><i class="fa fa-gear" aria-hidden="true"></i> Credentials first</a>
        <small class="text-muted">Then use <em>Test connection</em> and <em>Discover now</em> below. For a fleet, use the
            device group / os form on the <a href="{{ \SafferIt\LibrenmsNetconf\Support\PluginRoutes::to('netconf.status') }}">NETCONF status page</a>
            or <code>lnms netconf:device --group/--os --enable</code>.</small>
    </div>
</div>
