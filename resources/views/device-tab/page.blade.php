{{-- Section bar plus the selected section. Variables: DevicePageData::section(). --}}
@include('netconf::fold-style')
<x-option-bar name="NETCONF" :options="$sections" :selected="$section"></x-option-bar>
@include('netconf::device-tab.' . $section)
