{{-- The trace as a picture: endpoints and devices as cards, the links between them carrying
     the interface on both ends, the VNI, protocol, state and per-port traffic. $diagram is
     TracePathDiagram::build(); the same partial draws a graph trace and a live trace. The
     path wraps onto further rows instead of shrinking, so a long path stays readable. --}}
@include('netconf::fabric.graph-lazy')
@once
    @push('styles')
    <style>
        .nt-path {
            --nt-up: #5cb85c; --nt-down: #d9534f; --nt-unknown: #999999; --nt-wan: #8e6bbf;
            --nt-gateway: #337ab7; --nt-spine: #5bc0de; --nt-leaf: #5cb85c; --nt-host: #777777; --nt-esi: #f0ad4e;
            --nt-card: rgba(128, 128, 128, 0.08); --nt-edge: rgba(128, 128, 128, 0.45); --nt-muted: rgba(128, 128, 128, 0.95);
            display: flex; flex-wrap: wrap; align-items: stretch; row-gap: 14px; margin: 4px 0 12px;
        }
        html.dark .nt-path {
            --nt-up: #7dca7a; --nt-down: #e87a76; --nt-unknown: #aaaaaa; --nt-wan: #b794e0;
            --nt-gateway: #6aaee0; --nt-spine: #7fd0e8; --nt-leaf: #7dca7a; --nt-host: #aaaaaa; --nt-esi: #f0c36a;
            --nt-card: rgba(255, 255, 255, 0.05); --nt-edge: rgba(255, 255, 255, 0.28);
        }
        .nt-step { display: flex; align-items: center; }
        .nt-card {
            width: 190px; box-sizing: border-box; padding: 8px 10px; border-radius: 4px;
            background: var(--nt-card); border: 1px solid var(--nt-edge); border-left: 4px solid var(--nt-unknown);
        }
        .nt-card.nt-leaf { border-left-color: var(--nt-leaf); }
        .nt-card.nt-spine { border-left-color: var(--nt-spine); }
        .nt-card.nt-gateway { border-left-color: var(--nt-gateway); }
        .nt-card.nt-host { border-left-color: var(--nt-host); border-style: dashed; border-left-style: solid; }
        .nt-card.nt-routes { border-color: var(--nt-gateway); box-shadow: 0 0 0 1px var(--nt-gateway) inset; }
        .nt-card.nt-bad { border-color: var(--nt-down); }
        .nt-title { font-weight: 600; overflow-wrap: anywhere; line-height: 1.25; }
        .nt-sub { font-size: 11px; color: var(--nt-muted); overflow-wrap: anywhere; line-height: 1.3; }
        .nt-kind { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: var(--nt-muted); }
        .nt-chips { margin-top: 4px; display: flex; flex-wrap: wrap; gap: 3px; }
        .nt-chip {
            display: inline-block; font-size: 10px; line-height: 1; padding: 2px 5px; border-radius: 8px;
            border: 1px solid var(--nt-edge); color: inherit; white-space: nowrap;
        }
        .nt-chip.nt-esi { border-color: var(--nt-esi); color: var(--nt-esi); }
        .nt-chip.nt-warn { border-color: var(--nt-down); color: var(--nt-down); }
        .nt-chip.nt-vni { border-color: var(--nt-gateway); color: var(--nt-gateway); }
        .nt-chip.nt-live { border-color: var(--nt-up); color: var(--nt-up); }
        .nt-pivot { margin-top: 6px; padding: 4px 6px; border-radius: 3px; background: rgba(51, 122, 183, 0.15); font-size: 11px; }
        .nt-pivot code { font-size: 11px; }
        .nt-link { width: 212px; box-sizing: border-box; padding: 0 6px; text-align: center; font-size: 11px; --nt-tone: var(--nt-up); }
        .nt-link.nt-tone-down { --nt-tone: var(--nt-down); }
        .nt-link.nt-tone-unknown { --nt-tone: var(--nt-unknown); }
        .nt-link.nt-tone-wan { --nt-tone: var(--nt-wan); }
        .nt-link.nt-tone-gap { --nt-tone: var(--nt-unknown); }
        .nt-link.nt-access { --nt-tone: var(--nt-host); }
        .nt-wire { position: relative; height: 0; margin: 9px 0 3px; border-top: 3px solid var(--nt-tone); }
        .nt-wire::after {
            content: ''; position: absolute; right: -2px; top: -7px; border: 5px solid transparent;
            border-left: 7px solid var(--nt-tone); border-right: 0;
        }
        .nt-link.nt-tone-gap .nt-wire, .nt-link.nt-tone-wan .nt-wire, .nt-link.nt-access .nt-wire { border-top-style: dashed; }
        .nt-link.nt-access .nt-wire { border-top-width: 2px; }
        .nt-ifs { display: flex; justify-content: space-between; gap: 6px; }
        .nt-ifs code { font-size: 10px; padding: 0 3px; overflow-wrap: anywhere; text-align: left; }
        .nt-ifs code:last-child { text-align: right; }
        .nt-state { color: var(--nt-tone); font-weight: 600; }
        .nt-spark { display: block; margin: 3px auto 0; max-width: 100%; height: 26px; }
        .nt-overlay { font-size: 12px; margin: 0 0 4px; }
        .nt-overlay .fa { color: var(--nt-muted); }
    </style>
    @endpush
@endonce

@foreach ($diagram['overlays'] as $overlay)
    <div class="nt-overlay">
        <i class="fa fa-long-arrow-right fa-fw" aria-hidden="true"></i>
        VXLAN <span class="nt-chip nt-vni" style="font-size: 11px;">VNI {{ $overlay['vni'] ?? '?' }}</span>
        {{ $overlay['from'] }} <span class="text-muted">&rArr;</span> {{ $overlay['to'] }}
        <span class="text-muted">&mdash; one overlay hop, the devices between them carry it in the underlay</span>
    </div>
@endforeach

<div class="nt-path">
    @foreach ($diagram['items'] as $item)
        @if ($item['type'] === 'endpoint')
            <div class="nt-step">
                <div class="nt-card nt-host {{ $item['duplicate'] ? 'nt-bad' : '' }}">
                    <div class="nt-kind"><i class="fa fa-desktop fa-fw" aria-hidden="true"></i> {{ $item['role'] }}</div>
                    <div class="nt-title">{!! str_replace('.', '.<wbr>', e($item['title'])) !!}</div>
                    @foreach ($item['sub'] as $sub)<div class="nt-sub">{{ $sub }}</div>@endforeach
                    <div class="nt-chips">
                        @if ($item['vni'] !== null)<span class="nt-chip nt-vni">VNI {{ $item['vni'] }}</span>@endif
                        @if ($item['duplicate'])<span class="nt-chip nt-warn" title="the MAC is learnt on more than one attachment">duplicate</span>@endif
                        @if ($item['moves'] > 0)<span class="nt-chip nt-warn" title="the MAC changed its active source">{{ $item['moves'] }} move{{ $item['moves'] === 1 ? '' : 's' }}</span>@endif
                    </div>
                    <div class="nt-sub" style="margin-top: 4px;">found in: {{ $item['source'] }}</div>
                </div>
            </div>
        @elseif ($item['type'] === 'station')
            <div class="nt-step">
                <div class="nt-card nt-{{ $item['role'] }} {{ $item['pivot'] !== null ? 'nt-routes' : '' }}">
                    <div class="nt-kind">{{ $item['role'] === 'unknown' ? 'device' : $item['role'] }}@if ($item['border']) &middot; border @endif</div>
                    <div class="nt-title">{!! str_replace('.', '.<wbr>', e($item['name'])) !!}</div>
                    @if ($item['address'] !== null && $item['address'] !== $item['name'])<div class="nt-sub">{{ $item['address'] }}</div>@endif
                    @if ($item['pivot'] !== null)
                        <div class="nt-pivot"><i class="fa fa-random fa-fw" aria-hidden="true"></i> routes <code>{{ $item['pivot'] }}</code>
                            <div class="nt-sub">L3 context {{ $item['context'] ?? 'unknown' }}</div>
                        </div>
                    @endif
                    @if ($item['local'])
                        <div class="nt-sub" style="margin-top: 4px;">local switching: <code>{{ $item['in'] }}</code> &rarr; <code>{{ $item['out'] }}</code></div>
                    @endif
                    @if ($item['gap'])<div class="nt-chips"><span class="nt-chip nt-warn">reached across a gap</span></div>@endif
                </div>
            </div>
        @else
            <div class="nt-step">
                <div class="nt-link nt-tone-{{ $item['tone'] }} {{ $item['kind'] === 'access' ? 'nt-access' : '' }}">
                    @if ($item['kind'] === 'underlay')
                        <div>
                            @if ($item['vni'] !== null)<span class="nt-chip nt-vni">VNI {{ $item['vni'] }}</span>@endif
                            <span>{{ $item['protocol'] }}</span>
                            @if ($item['ecmp'] > 1)<span class="nt-chip nt-warn">{{ $item['ecmp'] }}-way ECMP</span>@endif
                            @if ($item['live'])<span class="nt-chip nt-live" title="read from this device's forwarding table">live</span>@endif
                            @if ($item['wan'])<span class="nt-chip">WAN</span>@endif
                        </div>
                    @elseif ($item['kind'] === 'access')
                        <div>
                            <span class="nt-kind">access</span>
                            @if ($item['esi'] !== null)<span class="nt-chip nt-esi" title="Ethernet segment {{ $item['esi'] }}">ESI{{ $item['df'] ? ' · DF' : '' }}</span>@endif
                        </div>
                    @endif
                    <div class="nt-wire"></div>
                    @if ($item['kind'] === 'access')
                        <div><code>{{ $item['right_if'] ?? '?' }}</code></div>
                    @elseif ($item['kind'] === 'underlay')
                        <div class="nt-ifs"><code title="{{ $item['left_if'] }}">{{ $item['left_if'] ?? '?' }}</code><code title="{{ $item['right_if'] }}">{{ $item['right_if'] ?? '?' }}</code></div>
                        <div class="nt-state">{{ $item['state'] ?? 'no session state' }}</div>
                    @else
                        <div class="nt-state">{{ $item['state'] }}</div>
                    @endif
                    @foreach ($item['port_ids'] as $portId)
                        @php($periods = array_map(fn ($from) => route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => $from, 'width' => 340, 'height' => 100, 'legend' => 'yes']), ['-1d', '-1w', '-1mo', '-1y']))
                        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('port_bits', ['id' => $portId]) }}">
                            <img class="nt-spark" alt="port {{ $portId }}" data-src="{{ route('graph', ['type' => 'port_bits', 'id' => $portId, 'from' => '-1d', 'width' => 160, 'height' => 26, 'legend' => 'no']) }}" data-popup='@json($periods)'>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</div>

@foreach ($diagram['alternatives'] as $alternative)
    <details style="margin-bottom: 8px;">
        <summary class="text-muted"><small>{{ count($alternative['paths']) }} equal-hop paths{{ $alternative['vni'] !== null ? ' in VNI ' . $alternative['vni'] : '' }}; the one above is the first</small></summary>
        <ul style="margin-top: 4px;">@foreach ($alternative['paths'] as $path)<li><small>{{ $path }}</small></li>@endforeach</ul>
    </details>
@endforeach
