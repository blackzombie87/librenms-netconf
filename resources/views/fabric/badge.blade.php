{{-- the "EVPN fabric" cell of a device: $badge (DeviceBadge::forDevice()). Fabric pages need
     global read, so a device-only viewer gets the figures as text without links --}}
@php($fabricLinks = \Illuminate\Support\Facades\Gate::allows('global-read'))
@php($tab = fn (string $tab) => $fabricLinks ? route('netconf.fabric', [$badge['fabric_id'], $tab]) : null)
@if ($fabricLinks)<a href="{{ route('netconf.fabric', $badge['fabric_id']) }}"><strong>{{ $badge['fabric_name'] }}</strong></a>@else<strong>{{ $badge['fabric_name'] }}</strong>@endif
<small class="text-muted">({{ $badge['members'] }} members)</small>
@php($class = match ($badge['role']) { 'gateway' => 'primary', 'spine' => 'info', 'leaf' => 'success', default => 'default' })
<span class="label label-{{ $class }}">{{ $badge['role'] }}</span>
@if ($badge['border'])<span class="label label-warning" title="L3 contexts / type-5 prefixes">border</span>@endif
<br><small>
    VTEP <code>{{ $badge['vtep_ip'] }}</code>@if ($badge['router_id'] && $badge['router_id'] !== $badge['vtep_ip']), router-id {{ $badge['router_id'] }}@endif
    @foreach ([['vnis', $badge['vnis'] . ' VNIs' . ($badge['irbs'] ? ' (' . $badge['irbs'] . ' IRB)' : '')], ['esis', $badge['esis'] . ' ESI-LAGs, DF for ' . $badge['esis_df']], ['bgp', $badge['neighbors'] . ' EVPN neighbours'], ['tunnels', $badge['tunnels'] . ' tunnels']] as [$tabId, $text])
        &middot; @if ($tab($tabId))<a href="{{ $tab($tabId) }}">{{ $text }}</a>@else{{ $text }}@endif
    @endforeach
</small>
