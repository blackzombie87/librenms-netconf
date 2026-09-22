{{-- Standalone per-device page: the fallback when the core seam for device tabs is missing.
     Same sections and partials as the tab. --}}
@extends('layouts.librenmsv1')

@section('title', 'NETCONF - ' . $device->displayName())

@section('content')
<div class="container-fluid">
    <h4 class="tw:mt-2 tw:mb-4"><i class="fa fa-terminal fa-fw" aria-hidden="true"></i> NETCONF — {!! \LibreNMS\Util\Url::deviceLink($device) !!}</h4>
    @include('netconf::device-tab.page')
</div>
@endsection
