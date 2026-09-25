@include('netconf::fabric.mac-search', ['scope_note' => 'searching the ' . $fabric['monitored'] . ' monitored member' . ($fabric['monitored'] === 1 ? '' : 's') . ' of this fabric'])
<p><small>
    <a href="{{ route('netconf.evpn.mac', ['q' => $q]) }}">search all devices instead</a>
    @if ($q !== '') &middot; <a href="{{ route('netconf.fabric', [$fabric['id'], 'trace']) }}?{{ http_build_query(['from' => $q]) }}">trace from here</a>@endif
</small></p>
