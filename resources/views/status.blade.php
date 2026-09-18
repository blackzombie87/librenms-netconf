@extends('layouts.librenmsv1')

@section('title', 'NETCONF')

@section('content')
<div class="container-fluid">
    @include('netconf::status-content')
</div>
@endsection
