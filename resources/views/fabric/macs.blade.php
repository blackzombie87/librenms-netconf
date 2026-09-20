@include('netconf::fabric.mac-search', ['scope_note' => 'searching the ' . $fabric['monitored'] . ' monitored member' . ($fabric['monitored'] === 1 ? '' : 's') . ' of this fabric'])
<p><small><a href="{{ route('netconf.evpn.mac', ['q' => $q]) }}">search all devices instead</a></small></p>
