{{-- the "EVPN fabric" cell of a device: $badge (DeviceBadge::forDevice()) --}}
<a href="{{ route('netconf.fabric', $badge['fabric_id']) }}"><strong>{{ $badge['fabric_name'] }}</strong></a>
<small class="text-muted">({{ $badge['members'] }} members)</small>
@php($class = match ($badge['role']) { 'gateway' => 'primary', 'spine' => 'info', 'leaf' => 'success', default => 'default' })
<span class="label label-{{ $class }}">{{ $badge['role'] }}</span>
@if ($badge['border'])<span class="label label-warning" title="L3 contexts / type-5 prefixes">border</span>@endif
<br><small>
    VTEP <code>{{ $badge['vtep_ip'] }}</code>@if ($badge['router_id'] && $badge['router_id'] !== $badge['vtep_ip']), router-id {{ $badge['router_id'] }}@endif
    &middot; <a href="{{ route('netconf.fabric', [$badge['fabric_id'], 'vnis']) }}">{{ $badge['vnis'] }} VNIs</a>@if ($badge['irbs']) ({{ $badge['irbs'] }} IRB)@endif
    &middot; <a href="{{ route('netconf.fabric', [$badge['fabric_id'], 'esis']) }}">{{ $badge['esis'] }} ESI-LAGs</a>, DF for {{ $badge['esis_df'] }}
    &middot; <a href="{{ route('netconf.fabric', [$badge['fabric_id'], 'bgp']) }}">{{ $badge['neighbors'] }} EVPN neighbours</a>
    &middot; <a href="{{ route('netconf.fabric', [$badge['fabric_id'], 'tunnels']) }}">{{ $badge['tunnels'] }} tunnels</a>
</small>
