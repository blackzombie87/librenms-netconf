@extends('layouts.librenmsv1')

@section('title', $fabric['name'])

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <i class="fa fa-sitemap fa-fw" aria-hidden="true"></i> <strong>{{ $fabric['name'] }}</strong>
                    <small class="text-muted">key <code>{{ $fabric['key'] }}</code> &middot; {{ $fabric['members'] }} members, {{ $fabric['monitored'] }} NETCONF-polled{{ $fabric['devices'] > $fabric['monitored'] ? sprintf(' (%d in LibreNMS without EVPN data)', $fabric['devices'] - $fabric['monitored']) : '' }}</small>
                    <span class="pull-right"><a href="{{ route('netconf.fabrics') }}">all fabrics</a></span>
                </div>
                <div class="panel-body">
                    @if ($result)
                        <div class="alert alert-{{ $result['type'] }}">{{ $result['text'] }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>
                    @endif
                    @if (! $fabric_enabled)
                        <div class="alert alert-warning">The <em>EVPN fabric view</em> setting is off: this data is not being refreshed.</div>
                    @endif

                    <ul class="nav nav-tabs" style="margin-bottom: 15px;">
                        @foreach ($tabs as $id => $label)
                            <li class="{{ $tab === $id ? 'active' : '' }}"><a href="{{ route('netconf.fabric', [$fabric['id'], $id]) }}">{{ $label }}</a></li>
                        @endforeach
                    </ul>

                    @include("netconf::fabric.$tab")
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
