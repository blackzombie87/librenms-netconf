{{-- role badges: $roles (role => count), $border (count) --}}
@if ($roles['gateway'])<span class="label label-primary" title="L3 gateways (anycast IRBs)">{{ $roles['gateway'] }} gateway{{ $roles['gateway'] === 1 ? '' : 's' }}</span> @endif
@if ($roles['spine'])<span class="label label-info" title="EVPN session peers without VXLAN endpoint (spines / route reflectors)">{{ $roles['spine'] }} spine{{ $roles['spine'] === 1 ? '' : 's' }}</span> @endif
@if ($roles['leaf'])<span class="label label-success" title="VXLAN endpoints">{{ $roles['leaf'] }} {{ $roles['leaf'] === 1 ? 'leaf' : 'leaves' }}</span> @endif
@if ($roles['unknown'])<span class="label label-default" title="no role evidence">{{ $roles['unknown'] }} unknown</span> @endif
@if ($border)<small class="text-muted" title="members with L3 contexts / type-5 prefixes">{{ $border }} border</small>@endif
