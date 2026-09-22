{{-- The NETCONF panel of the device overview. Core's <x-device.overview.panel> exists from
     26.8 on; Blade resolves component tags when it compiles a view, so the tag lives in its
     own file and older cores get the same markup written out. --}}
@if (\Illuminate\Support\Facades\View::exists('components.device.overview.panel'))
    @include('netconf::device-overview-panel')
@else
    <div class="panel panel-default tw:mb-5 tw:overflow-hidden tw:rounded-lg tw:border tw:border-gray-300 tw:shadow-sm tw:dark:border-dark-gray-200">
        <div class="panel-heading tw:px-4 tw:py-2.5 tw:bg-neutral-100 tw:border-b tw:border-gray-300 tw:text-neutral-700 tw:dark:bg-dark-gray-200 tw:dark:border-zinc-800 tw:dark:text-dark-white-200">
            <a href="{{ $href }}">
                <i class="fa fa-terminal fa-lg icon-theme" aria-hidden="true"></i>
                <strong>NETCONF</strong>
            </a>
        </div>
        <div class="tw:p-0 tw:bg-white tw:dark:bg-dark-gray-400">
            @include('netconf::device-overview-body')
        </div>
    </div>
@endif
