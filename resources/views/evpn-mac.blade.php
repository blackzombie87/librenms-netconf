@extends('layouts.librenmsv1')

@section('title', 'EVPN MAC search')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <i class="fa fa-search fa-fw" aria-hidden="true"></i> <strong>EVPN MAC search</strong>
                    <span class="pull-right"><a href="{{ route('netconf.fabrics') }}">fabrics</a> &middot; <a href="{{ route('netconf.status') }}">NETCONF devices</a></span>
                </div>
                <div class="panel-body">
                    @if (! $fabric_enabled)
                        <div class="alert alert-warning">The <em>EVPN fabric view</em> setting is off: the MAC database is not being collected.</div>
                    @endif
                    @include('netconf::fabric.mac-search', ['scope_note' => 'searching every device'])
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
