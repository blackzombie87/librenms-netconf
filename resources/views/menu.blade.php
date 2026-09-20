<a href="{{ route('netconf.status') }}">
    <i class="fa fa-terminal fa-fw fa-lg" aria-hidden="true"></i>
    NETCONF
</a>
@if (\SafferIt\LibrenmsNetconf\Collect\NetconfService::fabricEnabled())
    <a href="{{ route('netconf.fabrics') }}">
        <i class="fa fa-sitemap fa-fw fa-lg" aria-hidden="true"></i>
        EVPN fabrics
    </a>
@endif
