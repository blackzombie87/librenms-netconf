{{-- One card of the trace picture: an endpoint or a device. $card is an item of TracePathDiagram. --}}
@if ($card['type'] === 'endpoint')
    <div class="nt-card nt-host {{ $card['duplicate'] ? 'nt-bad' : '' }}">
        <div class="nt-kind"><i class="fa fa-desktop fa-fw" aria-hidden="true"></i> {{ $card['role'] }}</div>
        <div class="nt-title">{!! str_replace('.', '.<wbr>', e($card['title'])) !!}</div>
        @foreach ($card['sub'] as $sub)<div class="nt-sub">{{ $sub }}</div>@endforeach
        <div class="nt-chips">
            @if ($card['vni'] !== null)<span class="nt-chip nt-vni">VNI {{ $card['vni'] }}</span>@endif
            @if ($card['duplicate'])<span class="nt-chip nt-warn" title="the MAC is learnt on more than one attachment">duplicate</span>@endif
            @if ($card['moves'] > 0)<span class="nt-chip nt-warn" title="the MAC changed its active source">{{ $card['moves'] }} move{{ $card['moves'] === 1 ? '' : 's' }}</span>@endif
        </div>
        <div class="nt-sub" style="margin-top: 5px;">found in {{ $card['source'] }}</div>
    </div>
@else
    <div class="nt-card nt-{{ $card['role'] }} {{ $card['pivot'] !== null ? 'nt-routes' : '' }}">
        <div class="nt-kind">{{ $card['role'] === 'unknown' ? 'device' : $card['role'] }}@if ($card['border']) &middot; border @endif</div>
        <div class="nt-title">{!! str_replace('.', '.<wbr>', e($card['name'])) !!}</div>
        @if ($card['address'] !== null && $card['address'] !== $card['name'])<div class="nt-sub">{{ $card['address'] }}</div>@endif
        @if ($card['pivot'] !== null)
            <div class="nt-pivot">
                <div class="nt-kind"><i class="fa fa-random fa-fw" aria-hidden="true"></i> routes</div>
                <div><span class="nt-if">{{ $card['pivot'] }}</span></div>
                <div class="nt-sub">L3 context {{ $card['context'] ?? 'unknown' }}</div>
            </div>
        @endif
        @if ($card['local'])
            <div class="nt-sub" style="margin-top: 5px;">local switching</div>
            <div><span class="nt-if">{{ $card['in'] }}</span> <i class="fa fa-long-arrow-right" aria-hidden="true"></i> <span class="nt-if">{{ $card['out'] }}</span></div>
        @endif
        @if ($card['gap'])<div class="nt-chips"><span class="nt-chip nt-warn">reached across a gap</span></div>@endif
    </div>
@endif
