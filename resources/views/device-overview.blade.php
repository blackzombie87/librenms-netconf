{{-- Same markup as core's <x-device.overview.panel> (master) so the panel matches its neighbours,
     written out because 26.x has no such component and Blade resolves component tags at compile time. --}}
<div class="panel panel-default tw:mb-5 tw:overflow-hidden tw:rounded-lg tw:border tw:border-gray-300 tw:shadow-sm tw:dark:border-dark-gray-200">
    <div class="panel-heading tw:px-4 tw:py-2.5 tw:bg-neutral-100 tw:border-b tw:border-gray-300 tw:text-neutral-700 tw:dark:bg-dark-gray-200 tw:dark:border-zinc-800 tw:dark:text-dark-white-200">
        <a href="{{ route('netconf.device', $device->device_id) }}">
            <i class="fa fa-terminal fa-lg icon-theme" aria-hidden="true"></i>
            <strong>NETCONF</strong>
        </a>
    </div>
    <div class="tw:p-0 tw:bg-white tw:dark:bg-dark-gray-400">
        @include('netconf::device-overview-body')
    </div>
</div>
