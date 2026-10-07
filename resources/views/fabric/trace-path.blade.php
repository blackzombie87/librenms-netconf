{{-- The trace as a picture: endpoints and devices as cards, the links between them carrying
     the interface on both ends, the VNI, protocol, state and the traffic of the port. $diagram
     is TracePathDiagram::build(); the same partial draws a graph trace and a live trace.

     The path is a row of units, each a card and the link that leaves it (the last card has no
     link), and the units wrap as units: a row never ends in the middle of a link, and a card is
     never separated from the link that leaves it. A unit takes the room it is given between a
     minimum and a maximum, so a path of seven cards fits one row from about 1,500 px. --}}
@include('netconf::fabric.graph-lazy')
@once
    @push('styles')
    <style>
        .nt-path {
            --nt-up: #5cb85c; --nt-down: #d9534f; --nt-unknown: #999999; --nt-wan: #8e6bbf;
            --nt-gateway: #337ab7; --nt-spine: #5bc0de; --nt-leaf: #5cb85c; --nt-host: #777777; --nt-esi: #f0ad4e;
            --nt-card: rgba(128, 128, 128, 0.08); --nt-edge: rgba(128, 128, 128, 0.45); --nt-muted: rgba(128, 128, 128, 0.95);
            --nt-pill: rgba(128, 128, 128, 0.16);
            display: flex; flex-wrap: wrap; align-items: stretch; gap: 14px 0; margin: 6px 0 14px;
        }
        html.dark .nt-path {
            --nt-up: #7dca7a; --nt-down: #e87a76; --nt-unknown: #aaaaaa; --nt-wan: #b794e0;
            --nt-gateway: #6aaee0; --nt-spine: #7fd0e8; --nt-leaf: #7dca7a; --nt-host: #aaaaaa; --nt-esi: #f0c36a;
            --nt-card: rgba(255, 255, 255, 0.05); --nt-edge: rgba(255, 255, 255, 0.28); --nt-pill: rgba(255, 255, 255, 0.1);
        }
        .nt-unit { display: flex; align-items: stretch; flex: 1 1 262px; min-width: 262px; }
        .nt-unit.nt-last { flex: 0 0 150px; min-width: 150px; }
        .nt-card {
            flex: 0 0 150px; width: 150px; box-sizing: border-box; padding: 8px 10px; border-radius: 4px;
            background: var(--nt-card); border: 1px solid var(--nt-edge); border-left: 4px solid var(--nt-unknown);
        }
        .nt-card.nt-leaf { border-left-color: var(--nt-leaf); }
        .nt-card.nt-spine { border-left-color: var(--nt-spine); }
        .nt-card.nt-gateway { border-left-color: var(--nt-gateway); }
        .nt-card.nt-host { border-left-color: var(--nt-host); border-style: dashed; border-left-style: solid; }
        .nt-card.nt-routes { border-color: var(--nt-gateway); box-shadow: 0 0 0 1px var(--nt-gateway) inset; }
        .nt-card.nt-bad { border-color: var(--nt-down); }
        .nt-title { font-weight: 600; font-size: 12px; overflow-wrap: anywhere; line-height: 1.25; margin-top: 1px; }
        .nt-sub { font-size: 11px; color: var(--nt-muted); overflow-wrap: anywhere; line-height: 1.3; }
        .nt-kind { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: var(--nt-muted); line-height: 1.2; }
        .nt-chips { margin-top: 5px; display: flex; flex-wrap: wrap; gap: 3px; }
        .nt-chip {
            display: inline-block; font-size: 10px; line-height: 1; padding: 2px 6px; border-radius: 8px;
            border: 1px solid var(--nt-edge); color: inherit; white-space: nowrap;
        }
        .nt-chip.nt-esi { border-color: var(--nt-esi); color: var(--nt-esi); }
        .nt-chip.nt-warn { border-color: var(--nt-down); color: var(--nt-down); }
        .nt-chip.nt-vni { border-color: var(--nt-gateway); color: var(--nt-gateway); }
        .nt-chip.nt-live { border-color: var(--nt-up); color: var(--nt-up); }
        .nt-if {
            display: inline-block; max-width: 100%; box-sizing: border-box; padding: 1px 5px; border-radius: 3px;
            background: var(--nt-pill); font: 10px/1.4 Menlo, Consolas, monospace; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis; vertical-align: top;
        }
        .nt-pivot { margin-top: 7px; padding: 5px 6px; border-radius: 3px; background: rgba(51, 122, 183, 0.16); }
        .nt-pivot .nt-if { margin: 2px 0; background: rgba(51, 122, 183, 0.22); }

        .nt-link {
            flex: 1 1 auto; min-width: 112px; box-sizing: border-box; padding: 0 7px; text-align: center; font-size: 11px;
            display: flex; flex-direction: column; justify-content: center; --nt-tone: var(--nt-up);
        }
        .nt-link.nt-tone-down { --nt-tone: var(--nt-down); }
        .nt-link.nt-tone-unknown { --nt-tone: var(--nt-unknown); }
        .nt-link.nt-tone-wan { --nt-tone: var(--nt-wan); }
        .nt-link.nt-tone-gap { --nt-tone: var(--nt-unknown); }
        .nt-link.nt-access { --nt-tone: var(--nt-host); }
        .nt-meta { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 3px; min-height: 14px; }
        .nt-wire { position: relative; height: 0; margin: 7px 3px 6px 0; border-top: 3px solid var(--nt-tone); }
        .nt-wire::after {
            content: ''; position: absolute; right: -3px; top: -6px; border: 4.5px solid transparent;
            border-left: 7px solid var(--nt-tone); border-right: 0;
        }
        .nt-link.nt-tone-gap .nt-wire, .nt-link.nt-tone-wan .nt-wire, .nt-link.nt-access .nt-wire { border-top-style: dashed; }
        .nt-link.nt-access .nt-wire { border-top-width: 2px; }
        .nt-ifs { display: flex; flex-direction: column; gap: 2px; }
        .nt-ifs .nt-if:first-child { align-self: flex-start; }
        .nt-ifs .nt-if:last-child { align-self: flex-end; }
        .nt-ifs.nt-one .nt-if { align-self: center; }
        .nt-state { color: var(--nt-tone); font-weight: 600; margin-top: 3px; }
        .nt-spark { display: block; margin: 4px auto 0; max-width: 100%; max-height: 40px; width: auto; height: auto; }

        .nt-overlay { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; font-size: 12px; margin: 0 0 4px; }
        .nt-overlay .fa { color: var(--nt-muted); }
        .nt-note { font-size: 11px; color: var(--nt-muted); margin: 0 0 6px; }
        .nt-more { margin: 0 0 6px; font-size: 12px; }
        .nt-more > summary { display: block; cursor: pointer; color: var(--nt-muted); outline: none; list-style: none; }
        .nt-more > summary::-webkit-details-marker { display: none; }
        .nt-more > summary::before { content: '\25B8'; display: inline-block; width: 1em; }
        .nt-more[open] > summary::before { content: '\25BE'; }
        .nt-more > summary:hover { text-decoration: underline; }
        .nt-more[open] > summary { margin-bottom: 4px; }
        .nt-more ul { margin: 0 0 0 4px; padding-left: 16px; }
    </style>
    @endpush
@endonce

@foreach ($diagram['overlays'] as $overlay)
    <div class="nt-overlay">
        <i class="fa fa-exchange fa-fw" aria-hidden="true"></i>
        <span>VXLAN</span>
        <span class="nt-chip nt-vni">VNI {{ $overlay['vni'] ?? '?' }}</span>
        <strong>{{ $overlay['from'] }}</strong>
        <i class="fa fa-long-arrow-right" aria-hidden="true"></i>
        <strong>{{ $overlay['to'] }}</strong>
    </div>
@endforeach
@if ($diagram['overlays'] !== [])
    <p class="nt-note">The overlay is one hop per tunnel; the devices between its two ends carry it in the underlay.</p>
@endif

@php($items = $diagram['items'])
@php($count = count($items))
<div class="nt-path">
    {{-- items alternate card, link, card, …; a unit is a card with the link after it --}}
    @for ($i = 0; $i < $count; $i += 2)
        @php($card = $items[$i])
        @php($link = $items[$i + 1] ?? null)
        <div class="nt-unit {{ $link === null ? 'nt-last' : '' }}">
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

            @if ($link !== null)
                <div class="nt-link nt-tone-{{ $link['tone'] }} {{ $link['kind'] === 'access' ? 'nt-access' : '' }}">
                    <div class="nt-meta">
                        @if ($link['kind'] === 'underlay')
                            @if ($link['vni'] !== null)<span class="nt-chip nt-vni">VNI {{ $link['vni'] }}</span>@endif
                            <span>{{ $link['protocol'] }}</span>
                            @if ($link['ecmp'] > 1)<span class="nt-chip nt-warn">{{ $link['ecmp'] }}-way ECMP</span>@endif
                            @if ($link['live'])<span class="nt-chip nt-live" title="read from this device's forwarding table">live</span>@endif
                            @if ($link['wan'])<span class="nt-chip">WAN</span>@endif
                        @elseif ($link['kind'] === 'access')
                            <span class="nt-kind">access</span>
                            @if ($link['esi'] !== null)<span class="nt-chip nt-esi" title="Ethernet segment {{ $link['esi'] }}">ESI{{ $link['df'] ? ' · DF' : '' }}</span>@endif
                        @endif
                    </div>
                    <div class="nt-wire"></div>
                    @if ($link['kind'] === 'underlay')
                        <div class="nt-ifs"><span class="nt-if" title="{{ $link['left_if'] }}">{{ $link['left_if'] ?? '?' }}</span><span class="nt-if" title="{{ $link['right_if'] }}">{{ $link['right_if'] ?? '?' }}</span></div>
                        <div class="nt-state">{{ $link['state'] ?? 'no session state' }}</div>
                    @elseif ($link['kind'] === 'access')
                        <div class="nt-ifs nt-one"><span class="nt-if" title="{{ $link['right_if'] }}">{{ $link['right_if'] ?? '?' }}</span></div>
                    @else
                        <div class="nt-state">{{ $link['state'] }}</div>
                    @endif
                    {{-- one graph for the link: the port on the near end; hovering it shows the periods --}}
                    @if ($link['port_ids'] !== [])
                        @php($portId = $link['port_ids'][0])
                        @php($periods = array_map(fn ($from) => route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => $from, 'width' => 340, 'height' => 100, 'legend' => 'yes']), ['-1d', '-1w', '-1mo', '-1y']))
                        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('port_bits', ['id' => $portId]) }}">
                            <img class="nt-spark" alt="port {{ $portId }}" data-src="{{ route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => '-1d', 'width' => 120, 'height' => 24, 'legend' => 'no']) }}" data-popup='@json($periods)'>
                        </a>
                    @endif
                </div>
            @endif
        </div>
    @endfor
</div>

@foreach ($diagram['alternatives'] as $alternative)
    <details class="nt-more">
        <summary>{{ count($alternative['paths']) }} equal-hop paths{{ $alternative['vni'] !== null ? ' in VNI ' . $alternative['vni'] : '' }}; the one above is the first</summary>
        <ul>@foreach ($alternative['paths'] as $path)<li>{{ $path }}</li>@endforeach</ul>
    </details>
@endforeach
