{{-- The NETCONF device tab (plan §8 U2). Found by core as `device.tabs.netconf` because the
     plugin adds this directory to the default view path; core renders the device header and
     the tab bar, $data comes from NetconfTab::data(). --}}
@extends('layouts.librenmsv1')

@section('content')
<x-device.page :device="$device">
    @include('netconf::device-tab.page', $data)
</x-device.page>
@endsection
