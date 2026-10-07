{{-- The eagle SVG and its viewport. Server-rendered geometry ($eagle = EagleLayout::place());
     the script only moves a viewBox, so there is no second layout engine and no new library. --}}
@php($eg = $eagle)
@php($collapsed = $view['collapse'])
{{-- toolbar links keep every other parameter and are built with the query builder, so a
     location that itself contains a comma stays one value (`collapse[]` is never split) --}}
@php($toggleSite = fn (string $key) => request()->fullUrlWithQuery(['focus' => null, 'collapse' => array_values(in_array($key, $collapsed, true) ? array_diff($collapsed, [$key]) : [...$collapsed, $key])]))
<div class="netconf-eagle">
    <div class="nt-bar">
        <span class="text-muted">
            {{ $eg['counts']['sites'] }} site{{ $eg['counts']['sites'] === 1 ? '' : 's' }}@if ($eg['counts']['collapsed'] > 0), {{ $eg['counts']['collapsed'] }} collapsed @endif
        </span>
        <label class="checkbox-inline" style="margin-left: 10px;" title="While on, releasing a drag eases the underlay neighbours of what was moved. Off by default; it does not run on load and it does not run on reset.">
            <input type="checkbox" id="eagle-gravity"> gravity
        </label>
        <span class="btn-group btn-group-xs" style="margin-left: 10px;" role="group">
            <a class="btn btn-default" href="{{ request()->fullUrlWithQuery(['focus' => null, 'collapse' => count($collapsed) === count($eg['groups']) ? [] : array_column($eg['groups'], 'key')]) }}">{{ count($collapsed) === count($eg['groups']) && $eg['groups'] !== [] ? 'expand all sites' : 'collapse all sites' }}</a>
            @if ($eg['counts']['outside'] > 0)
                <a class="btn btn-default {{ $view['outside'] ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['focus' => null, 'outside' => $view['outside'] ? null : 1]) }}">sessions out of the fabric ({{ $eg['counts']['outside'] }})</a>
            @endif
            <a class="btn btn-default {{ $view['attached'] !== null ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['focus' => null, 'attached' => $view['attached'] !== null ? null : 1]) }}">attached devices {{ $view['attached'] === null ? '' : '(' . count($view['attached']) . ')' }}</a>
            <a class="btn btn-default {{ $view['links'] ? 'active' : '' }}" title="Draw every underlay link and every ESI-LAG pair on its own, instead of one line per pair of sites" href="{{ request()->fullUrlWithQuery(['focus' => null, 'links' => $view['links'] ? null : 1]) }}">all links</a>
        </span>
        <span class="pull-right btn-group btn-group-xs" role="group">
            <button type="button" class="btn btn-default" id="eagle-out" title="zoom out">&minus;</button>
            <button type="button" class="btn btn-default" id="eagle-in" title="zoom in">+</button>
            <button type="button" class="btn btn-default" id="eagle-fit" title="fit the whole picture">fit</button>
            <button type="button" class="btn btn-default" id="eagle-actual" title="draw at layout size and scroll">1:1</button>
            <a class="btn btn-default" href="{{ route('netconf.fabric', [$fabric['id'], 'overview']) }}" id="eagle-reset" title="forget the saved arrangement, the stored camera and every view flag">reset</a>
        </span>
    </div>

    {{-- Every colour in the picture is a class here, because core sets class="dark" on <html>
         and a presentation attribute written by PHP cannot follow that. Dashes and widths are
         not theme and stay attributes. The frame is transparent, so the page surface shows
         through instead of a white island in a dark theme. --}}
    <style>
        #eagle-view { overflow: auto; max-height: 72vh; min-height: 420px; border: 1px solid #ddd; border-radius: 4px; background: transparent; }
        #eagle-view svg { display: block; max-width: none; height: auto; font-family: inherit; }
        #eagle-view svg text { font-family: inherit; }
        .eg-card { cursor: pointer; }
        .eg-card:hover rect { stroke-width: 2.5; }
        .eg-edge { cursor: pointer; }
        .eg-hl { stroke: #337ab7 !important; stroke-width: 4 !important; }
        .eg-sel rect { stroke: #337ab7 !important; stroke-width: 3 !important; }
        {{-- the zoom group is pull-right, and an uncleared float escapes the bar: the frame
             below then wraps beside it and measures 172 px on a phone --}}
        .netconf-eagle .nt-bar { margin-bottom: 6px; }
        .netconf-eagle .nt-bar::after { content: ''; display: table; clear: both; }

        .netconf-eagle {
            --eg-up: #5cb85c; --eg-down: #d9534f; --eg-unknown: #999999; --eg-cross: #3d5a80;
            --eg-wan: #8e6bbf; --eg-overlay: #337ab7; --eg-fault: #d9534f; --eg-esi: #f0ad4e;
            --eg-attached: #999999;
        }
        html.dark .netconf-eagle {
            --eg-up: #7dca7a; --eg-down: #e87a76; --eg-unknown: #aaaaaa; --eg-cross: #8fb4d9;
            --eg-wan: #b794e0; --eg-overlay: #6aaee0; --eg-fault: #e87a76; --eg-esi: #f0c36a;
            --eg-attached: #aaaaaa;
        }
        .netconf-eagle .eg-gateway { fill: #dbe9f6; }
        .netconf-eagle .eg-spine { fill: #e3f1fa; }
        .netconf-eagle .eg-leaf { fill: #e6f4e6; }
        .netconf-eagle .eg-unknown { fill: #f2f2f2; }
        .netconf-eagle .eg-far { fill: #f7f7f7; }
        .netconf-eagle .eg-outside { fill: #f7f7f7; }
        .netconf-eagle .eg-attached { fill: #ffffff; }
        .netconf-eagle .eg-site-box { fill: rgba(0,0,0,0.03); stroke: #ccc; }
        .netconf-eagle .eg-site-down { stroke: #d9534f; }
        .netconf-eagle .eg-site-warning { stroke: #f0ad4e; }
        .netconf-eagle .eg-card-ok { stroke: #5a5a5a; }
        .netconf-eagle .eg-card-down { stroke: #d9534f; }
        .netconf-eagle .eg-card-stale { stroke: #f0ad4e; }
        .netconf-eagle .eg-card-unmonitored { stroke: #999999; }
        .netconf-eagle .eg-text { fill: #222; }
        .netconf-eagle .eg-muted { fill: #666; }
        .netconf-eagle .eg-caption { fill: #777; }
        .netconf-eagle .eg-note { fill: #777; }
        .netconf-eagle .eg-note-danger { fill: #d9534f; }
        {{-- an edge label sits on the line it names: a halo in the page colour keeps it readable
             where it crosses a neighbouring lane --}}
        .netconf-eagle { --eg-halo: #ffffff; }
        html.dark .netconf-eagle { --eg-halo: #2b3139; }
        .netconf-eagle .eg-edge text { paint-order: stroke; stroke: var(--eg-halo); stroke-width: 3px; stroke-linejoin: round; }
        .netconf-eagle .eg-chip-text { fill: #444; }
        .netconf-eagle .eg-chip-danger { fill: #f7dcdb; }
        .netconf-eagle .eg-chip-warning { fill: #fcefdc; }
        .netconf-eagle .eg-chip-info { fill: #deeefb; }
        .netconf-eagle .eg-chip-muted { fill: #eeeeee; }
        .netconf-eagle .eg-down-dot { fill: #d9534f; }
        .netconf-eagle .eg-outside-dot { fill: #8e6bbf; }
        .netconf-eagle .eg-stroke-up { stroke: var(--eg-up); }
        .netconf-eagle .eg-stroke-down { stroke: var(--eg-down); }
        .netconf-eagle .eg-stroke-unknown { stroke: var(--eg-unknown); }
        .netconf-eagle .eg-stroke-cross { stroke: var(--eg-cross); }
        .netconf-eagle .eg-stroke-wan { stroke: var(--eg-wan); }
        .netconf-eagle .eg-stroke-overlay { stroke: var(--eg-overlay); }
        .netconf-eagle .eg-stroke-fault { stroke: var(--eg-fault); }
        .netconf-eagle .eg-stroke-esi { stroke: var(--eg-esi); }
        .netconf-eagle .eg-stroke-attached { stroke: var(--eg-attached); }

        {{-- The key is pinned to the frame, not to the scroller and not to the viewBox: inside
             `#eagle-view` it would scroll away with the picture, and inside the SVG it would
             zoom with it, which is the failure the viewport was added to prevent. Swatches are
             CSS borders and gradients, because Tailwind's preflight sets `svg { display: block }`
             and stacked each of the old <svg> swatches onto its own row -- 224 px of them. --}}
        #eagle-frame { position: relative; }
        #eagle-key { position: absolute; left: 8px; bottom: 8px; z-index: 2; font-size: 11px; line-height: 1.7; }
        #eagle-key-body { background: rgba(255, 255, 255, 0.92); border: 1px solid #ddd; border-radius: 4px; padding: 4px 8px; margin-top: 4px; max-width: 280px; }
        #eagle-key-body span { white-space: nowrap; margin-right: 8px; }
        #eagle-key .eg-swatch { display: inline-block; width: 26px; height: 0; border-top: 3px solid var(--eg-key); vertical-align: middle; margin-right: 2px; }
        #eagle-key .eg-swatch-dash { display: inline-block; width: 26px; vertical-align: middle; margin-right: 2px; }
        #eagle-key .eg-key-up { --eg-key: var(--eg-up); }
        #eagle-key .eg-key-down { --eg-key: var(--eg-down); height: 3px; background: repeating-linear-gradient(to right, var(--eg-key) 0 5px, transparent 5px 8px); }
        #eagle-key .eg-key-unknown { --eg-key: var(--eg-unknown); height: 2px; background: repeating-linear-gradient(to right, var(--eg-key) 0 1px, transparent 1px 5px); }
        #eagle-key .eg-key-cross { --eg-key: var(--eg-cross); }
        #eagle-key .eg-key-wan { --eg-key: var(--eg-wan); height: 3px; background: repeating-linear-gradient(to right, var(--eg-key) 0 7px, transparent 7px 10px); }
        #eagle-key .eg-key-overlay { --eg-key: var(--eg-overlay); height: 1px; background: repeating-linear-gradient(to right, var(--eg-key) 0 3px, transparent 3px 6px); }
        #eagle-key .eg-key-fault { --eg-key: var(--eg-fault); height: 2px; background: repeating-linear-gradient(to right, var(--eg-key) 0 6px, transparent 6px 8px, var(--eg-key) 8px 10px, transparent 10px 12px); }
        #eagle-key .eg-key-esi { --eg-key: var(--eg-esi); border-top-width: 2px; }
        #eagle-key .eg-key-trunk { --eg-key: var(--eg-up); border-top-width: 4px; }

        html.dark #eagle-view { border-color: #555; }
        html.dark #eagle-key-body { background: rgba(39, 43, 48, 0.92); border-color: #555; }
        html.dark .netconf-eagle .eg-gateway { fill: #24384a; }
        html.dark .netconf-eagle .eg-spine { fill: #1e3344; }
        html.dark .netconf-eagle .eg-leaf { fill: #1e3324; }
        html.dark .netconf-eagle .eg-unknown { fill: #2c3036; }
        html.dark .netconf-eagle .eg-far { fill: #2a2e33; }
        html.dark .netconf-eagle .eg-outside { fill: #2a2e33; }
        html.dark .netconf-eagle .eg-attached { fill: #2c3036; }
        html.dark .netconf-eagle .eg-site-box { fill: rgba(255,255,255,0.04); stroke: #666; }
        html.dark .netconf-eagle .eg-site-down { stroke: #e87a76; }
        html.dark .netconf-eagle .eg-site-warning { stroke: #f0c36a; }
        html.dark .netconf-eagle .eg-card-ok { stroke: #c5c5c5; }
        html.dark .netconf-eagle .eg-card-down { stroke: #e87a76; }
        html.dark .netconf-eagle .eg-card-stale { stroke: #f0c36a; }
        html.dark .netconf-eagle .eg-card-unmonitored { stroke: #aaaaaa; }
        html.dark .netconf-eagle .eg-text { fill: #e8e8e8; }
        html.dark .netconf-eagle .eg-muted { fill: #b5b5b5; }
        html.dark .netconf-eagle .eg-caption { fill: #c8c8c8; }
        html.dark .netconf-eagle .eg-note { fill: #a8a8a8; }
        html.dark .netconf-eagle .eg-note-danger { fill: #e87a76; }
        html.dark .netconf-eagle .eg-chip-text { fill: #e8e8e8; }
        html.dark .netconf-eagle .eg-chip-danger { fill: #4a2a2a; }
        html.dark .netconf-eagle .eg-chip-warning { fill: #4a3b24; }
        html.dark .netconf-eagle .eg-chip-info { fill: #23384a; }
        html.dark .netconf-eagle .eg-chip-muted { fill: #33373d; }
        html.dark .netconf-eagle .eg-down-dot { fill: #e87a76; }
        html.dark .netconf-eagle .eg-outside-dot { fill: #b794e0; }
                                                                            </style>

    {{-- the saved arrangement, embedded rather than fetched: it was validated against this very
         place() before it was stored, and the client only reads numbers out of it --}}
    <script type="application/json" id="eagle-layout">@json($layout ?? null)</script>
    <div id="eagle-frame">
    <div id="eagle-view" data-fabric="{{ $fabric['id'] }}" data-w="{{ $eg['width'] }}" data-h="{{ $eg['height'] }}"
         data-layout-url="{{ route('netconf.fabric.layout', [$fabric['id']]) }}" data-csrf="{{ csrf_token() }}">
        <svg id="eagle-svg" width="{{ $eg['width'] }}" height="{{ $eg['height'] }}" viewBox="0 0 {{ $eg['width'] }} {{ $eg['height'] }}" xmlns="http://www.w3.org/2000/svg">
            @foreach ($eg['groups'] as $g)
                <a href="{{ $toggleSite($g['key']) }}" class="eg-site" data-key="{{ $g['key'] }}" data-members="{{ implode(' ', $g['members']) }}" data-home-x="{{ $g['x'] }}" data-home-y="{{ $g['y'] }}" data-home-w="{{ $g['w'] }}" data-home-h="{{ $g['h'] }}">
                    <rect class="eg-site-box {{ $g['state'] === 'down' ? 'eg-site-down' : ($g['state'] === 'warning' ? 'eg-site-warning' : '') }}" x="{{ $g['x'] }}" y="{{ $g['y'] }}" width="{{ $g['w'] }}" height="{{ $g['h'] }}" rx="6" stroke-dasharray="4 3">
                        <title>{{ $g['label'] ?? 'no location' }} — {{ count($g['members']) }} member{{ count($g['members']) === 1 ? '' : 's' }}, click to {{ $g['collapsed'] ? 'expand' : 'collapse' }}</title>
                    </rect>
                    {{-- the caption is cut to the box: a location is free text and the ones that
                         exist are long enough to run across the next compound --}}
                    <text class="eg-caption" x="{{ $g['x'] + 6 }}" y="{{ $g['y'] + 14 }}" font-size="11">{{ $g['collapsed'] ? '▸' : '▾' }} {{ $g['caption'] }}<title>{{ $g['label'] ?? 'no location' }}</title></text>
                    @if (($g['note'] ?? '') !== '')
                        <text class="eg-note {{ $g['note_class'] === 'danger' ? 'eg-note-danger' : '' }}" x="{{ $g['x'] + $g['w'] - 6 }}" y="{{ $g['y'] + 14 }}" font-size="9" text-anchor="end">{{ $g['note'] }}<title>links inside this site and its ESI-LAG pairs; the ones that are not up or degraded are drawn</title></text>
                    @endif
                </a>
            @endforeach

            @foreach ($eg['edges'] as $e)
                <g class="eg-edge" data-focus="{{ $e['id'] }}" data-kind="{{ $e['kind'] }}" data-shape="{{ $e['shape'] }}" data-a="{{ $e['a'] }}" data-b="{{ $e['b'] }}" data-tier-a="{{ $e['tier_a'] }}" data-tier-b="{{ $e['tier_b'] }}" data-site-a="{{ $e['site_a'] }}" data-site-b="{{ $e['site_b'] }}"@if (isset($e['labels'])) data-labels='@json($e['labels'])'@endif>
                    <path d="{{ $e['path'] }}" fill="none" stroke-width="{{ $e['width'] }}" @if ($e['dash'] !== '') stroke-dasharray="{{ $e['dash'] }}" @endif class="{{ $e['strokeClass'] }} {{ $e['highlight'] ? 'eg-hl' : '' }}">
                        <title>{{ $e['title'] }}</title>
                    </path>
                    @if (($e['label'] ?? '') !== '' || isset($e['labels']))
                        <text class="eg-muted" x="{{ $e['lx'] }}" y="{{ $e['ly'] }}" font-size="9" text-anchor="middle">{{ $e['label'] }}</text>
                    @endif
                </g>
            @endforeach

            @foreach ($eg['nodes'] as $id => $n)
                <g class="eg-card" data-focus="{{ $n['kind'] === 'member' ? 'member:' . $n['ip'] : $id }}" data-id="{{ $id }}" data-kind="{{ $n['kind'] }}" data-site="{{ $n['site'] ?? '' }}" data-tier="{{ $n['tier'] }}" data-home-x="{{ $n['x'] }}" data-home-y="{{ $n['y'] }}"@if (($n['anchor'] ?? null) !== null) data-anchor="{{ $n['anchor'] }}"@endif>
                    <rect class="{{ $n['fillClass'] }} {{ $n['strokeClass'] }}" x="{{ $n['x'] }}" y="{{ $n['y'] }}" width="{{ $n['w'] }}" height="{{ $n['h'] }}" rx="5" stroke-width="{{ $n['highlight'] ? 3 : 1.5 }}" @if ($n['dashed']) stroke-dasharray="4 3" @endif>
                        <title>{{ $n['title'] }}</title>
                    </rect>
                    @if ($n['kind'] === 'attached')
                        <text class="eg-muted" x="{{ $n['x'] + 2 }}" y="{{ $n['y'] + 12 }}" font-size="9">{{ \Illuminate\Support\Str::limit($n['name'], 10, '…') }}</text>
                    @elseif ($n['kind'] === 'outside')
                        <circle class="eg-outside-dot" cx="{{ $n['x'] + 3 }}" cy="{{ $n['y'] + 3 }}" r="3"/>
                    @else
                        <text class="eg-text" x="{{ $n['x'] + $n['w'] / 2 }}" y="{{ $n['y'] + 17 }}" font-size="11" font-weight="bold" text-anchor="middle">{{ \Illuminate\Support\Str::limit($n['name'], 22, '…') }}</text>
                        <text class="eg-muted" x="{{ $n['x'] + $n['w'] / 2 }}" y="{{ $n['y'] + 31 }}" font-size="10" text-anchor="middle">{{ $n['ip'] }}@if ($n['border']) · border @endif</text>
                        @if (($n['summary'] ?? null) !== null)
                            <text class="eg-caption" x="{{ $n['x'] + $n['w'] / 2 }}" y="{{ $n['y'] + 46 }}" font-size="9" text-anchor="middle">{{ implode(' · ', $n['summary']) }}</text>
                        @else
                            @foreach ($n['chips'] as $i => $chip)
                                @php($cx = $n['x'] + 6 + $i * 76)
                                <rect class="eg-chip-{{ $chip['class'] }}" x="{{ $cx }}" y="{{ $n['y'] + 38 }}" width="72" height="14" rx="3"/>
                                <text class="eg-chip-text" x="{{ $cx + 36 }}" y="{{ $n['y'] + 48 }}" font-size="9" text-anchor="middle">{{ \Illuminate\Support\Str::limit($chip['text'], 13, '…') }}</text>
                            @endforeach
                        @endif
                        @if ($n['status'] === false)<circle class="eg-down-dot" cx="{{ $n['x'] + $n['w'] - 8 }}" cy="{{ $n['y'] + 8 }}" r="4"/>@endif
                    @endif
                </g>
            @endforeach
        </svg>
    </div>
    <div id="eagle-key">
        <button type="button" class="btn btn-default btn-xs" id="eagle-key-toggle" aria-expanded="true">key</button>
        <div id="eagle-key-body">
            <span><i class="eg-swatch eg-key-up"></i> underlay up</span>
            <span><i class="eg-swatch-dash eg-key-down"></i> down</span>
            <span><i class="eg-swatch-dash eg-key-unknown"></i> no session state</span>
            <span><i class="eg-swatch eg-key-cross"></i> cross-site</span>
            <span><i class="eg-swatch-dash eg-key-wan"></i> via WAN</span>
            <span><i class="eg-swatch-dash eg-key-overlay"></i> overlay</span>
            <span><i class="eg-swatch-dash eg-key-fault"></i> overlay fault</span>
            <span><i class="eg-swatch eg-key-esi"></i> ESI-LAG</span>
            <span><i class="eg-swatch eg-key-trunk"></i> trunk, or several links between two sites (thicker = more)</span>
        </div>
    </div>
    </div>
</div>
@once
    @push('scripts')
    <script type="text/javascript">
    (function () {
        var wrap = document.getElementById('eagle-view');
        var svg = document.getElementById('eagle-svg');
        if (!wrap || !svg) { return; }
        var W = parseInt(wrap.dataset.w, 10), H = parseInt(wrap.dataset.h, 10);
        var KEY = 'netconf-topology-' + wrap.dataset.fabric + '-eagle';
        var box = { x: 0, y: 0, w: W, h: H };

        function apply() { svg.setAttribute('viewBox', box.x + ' ' + box.y + ' ' + box.w + ' ' + box.h); }
        function store() { try { localStorage.setItem(KEY, JSON.stringify({ viewBox: box.x + ' ' + box.y + ' ' + box.w + ' ' + box.h })); } catch (e) {} }

        // The CSS size of the SVG, which is not the camera: the viewBox is the camera and the
        // rules below only decide how many screen pixels one user unit gets.
        var NARROW = 720;
        function narrow() { return frameW() < NARROW; }
        function frameW() { return Math.max(1, wrap.clientWidth); }
        function frameH() { return Math.max(1, wrap.clientHeight); }

        /**
         * The element is the window the picture is seen through, so it always covers the frame.
         * Sized to the drawing instead, it leaves a strip of the frame the picture can never be
         * panned into and clips against its own edge -- which reads as a border down the middle
         * of the page. Growing the window does not scale the drawing: `preserveAspectRatio`
         * fits the viewBox by the smaller of the two ratios, and the axis that decided the
         * scale is the one already up against the frame.
         */
        function setSize(w, h) {
            svg.setAttribute('width', Math.max(Math.round(w), frameW()));
            svg.setAttribute('height', Math.max(Math.round(h), frameH()));
        }
        function sizeActual() {
            // 1:1 is one user unit per CSS pixel. On a frame wider than the drawing the viewBox
            // is therefore the window and not the picture, with the picture centred in it
            setSize(W, H);
            var w = parseFloat(svg.getAttribute('width')), h = parseFloat(svg.getAttribute('height'));
            box = { x: (W - w) / 2, y: (H - h) / 2, w: w, h: h };
            apply();
        }
        function sizeFitWidth() { setSize(frameW(), H * frameW() / W); }
        function sizeGrow() {
            // smaller than the container: grow by CSS size, never by shrinking the viewBox --
            // that is what turns a two-node lab fabric into a postage stamp
            var scale = Math.min(frameW() / W, frameH() / H);
            setSize(W * scale, H * scale);
        }
        function autoSize() {
            // Under 720 px of frame the whole fabric goes in the frame even though the 11 px
            // labels then render smaller. A phone showing the bottom-left corner of an 1,112 px
            // drawing is not a picture of a fabric; `1:1` is how to get those pixels back.
            // At 720 px and above the rule is the old one: never shrink on first paint.
            if (narrow()) {
                sizeFitWidth();
            } else if (W <= frameW() && H <= frameH()) {
                sizeGrow();
            } else {
                setSize(W, H);
            }
        }

        var stored = null;
        try { stored = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
        var restored = false;
        if (stored && typeof stored.viewBox === 'string') {
            var p = stored.viewBox.split(' ').map(Number);
            if (p.length === 4 && p.every(function (n) { return isFinite(n); }) && p[2] > 0 && p[3] > 0) {
                // the operator's own camera, at every width
                box = { x: p[0], y: p[1], w: p[2], h: p[3] };
                apply();
                restored = true;
            }
        }
        if (restored) { setSize(W, H); } else { autoSize(); }

        // a frame that changes width would leave the same unusable strip
        window.addEventListener('resize', function () { if (restored) { setSize(W, H); } else { autoSize(); } });

        function zoom(factor, cx, cy) {
            var nw = box.w * factor, nh = box.h * factor;
            box.x += (box.w - nw) * cx;
            box.y += (box.h - nh) * cy;
            box.w = nw; box.h = nh;
            apply(); store();
        }
        document.getElementById('eagle-in').addEventListener('click', function () { zoom(1 / 1.2, 0.5, 0.5); });
        document.getElementById('eagle-out').addEventListener('click', function () { zoom(1.2, 0.5, 0.5); });
        document.getElementById('eagle-fit').addEventListener('click', function () { box = { x: 0, y: 0, w: W, h: H }; apply(); autoSize(); store(); });
        document.getElementById('eagle-actual').addEventListener('click', function () { box = { x: 0, y: 0, w: W, h: H }; apply(); sizeActual(); store(); });
        document.getElementById('eagle-reset').addEventListener('click', function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
            // back to EagleLayout::place(): the row goes, the camera goes, and the link that
            // follows clears collapse, outside, attached and focus with the query string
            send({ reset: true });
        });
        // the two keys the vis map left behind: it is gone, and nothing reads them again
        try { localStorage.removeItem('netconf-topology-' + wrap.dataset.fabric); localStorage.removeItem('netconf-topology-' + wrap.dataset.fabric + '-view'); } catch (e) {}

        wrap.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            var r = svg.getBoundingClientRect();
            zoom(ev.deltaY > 0 ? 1.1 : 1 / 1.1, (ev.clientX - r.left) / r.width, (ev.clientY - r.top) / r.height);
        }, { passive: false });

        var drag = null;
        wrap.addEventListener('mousedown', function (ev) {
            if (ev.target.closest('.eg-card, .eg-edge, .eg-site')) { return; }
            drag = { x: ev.clientX, y: ev.clientY, bx: box.x, by: box.y };
            ev.preventDefault();
        });
        window.addEventListener('mousemove', function (ev) {
            if (!drag) { return; }
            var r = svg.getBoundingClientRect();
            box.x = drag.bx - (ev.clientX - drag.x) * box.w / r.width;
            box.y = drag.by - (ev.clientY - drag.y) * box.h / r.height;
            apply();
        });
        window.addEventListener('mouseup', function () { if (drag) { drag = null; store(); } });


        // ================= the picture as a model, and the router that keeps it honest =======
        // Constants below are EagleLayout's. A path the browser draws has to be one the server
        // would have drawn for the same rects, or a reload would jump.
        var CARD_W = 156, SITE_PAD = 10, SITE_HEADER = 20, MARGIN = 24;
        var CLEARANCE = 4, INSIDE_TOL = 1, EDGE_CHAR_W = 4.8;

        var nodes = {}, groups = {}, links = [];

        function num(el, name) { return parseFloat(el.getAttribute(name)) || 0; }

        Array.prototype.forEach.call(svg.querySelectorAll('.eg-card'), function (g) {
            var rect = g.querySelector('rect');
            nodes[g.dataset.id] = {
                el: g, rect: rect, kind: g.dataset.kind, site: g.dataset.site || null, tier: g.dataset.tier || '',
                anchor: g.dataset.anchor || null,
                hx: parseInt(g.dataset.homeX, 10), hy: parseInt(g.dataset.homeY, 10),
                x: parseInt(g.dataset.homeX, 10), y: parseInt(g.dataset.homeY, 10),
                w: num(rect, 'width'), h: num(rect, 'height'),
            };
        });
        Array.prototype.forEach.call(svg.querySelectorAll('.eg-site'), function (a) {
            var rect = a.querySelector('rect');
            groups[a.dataset.key] = {
                el: a, rect: rect, caption: a.querySelector('.eg-caption'), note: a.querySelector('.eg-note'),
                members: (a.dataset.members || '').split(' ').filter(Boolean),
                hx: parseInt(a.dataset.homeX, 10), hy: parseInt(a.dataset.homeY, 10),
                hw: parseInt(a.dataset.homeW, 10), hh: parseInt(a.dataset.homeH, 10),
                x: parseInt(a.dataset.homeX, 10), y: parseInt(a.dataset.homeY, 10),
                w: parseInt(a.dataset.homeW, 10), h: parseInt(a.dataset.homeH, 10),
            };
        });
        Array.prototype.forEach.call(svg.querySelectorAll('.eg-edge'), function (g) {
            links.push({
                el: g, path: g.querySelector('path'), text: g.querySelector('text'),
                kind: g.dataset.kind, shape: g.dataset.shape,
                a: g.dataset.a, b: g.dataset.b, siteA: g.dataset.siteA || null, siteB: g.dataset.siteB || null,
                // a bundle carries its label in the long form and the short ones, in order
                labels: (function () { try { return JSON.parse(g.dataset.labels || 'null') || []; } catch (x) { return []; } })(),
                label: g.querySelector('text') ? g.querySelector('text').textContent : '',
            });
            var added = links[links.length - 1];
            if (added.labels.length) { added.label = added.labels[0]; }
        });

        function rectOf(id) {
            var n = nodes[id];
            if (n) { return { x: n.x, y: n.y, w: n.w, h: n.h }; }
            var g = groups[id];
            return g ? { x: g.x, y: g.y, w: g.w, h: g.h } : null;
        }
        // where an edge lands: its own card, or the header of the site it was collapsed into
        function anchorOf(id) {
            var n = nodes[id];
            if (n) { return { x: n.x + n.w / 2, top: n.y, bottom: n.y + n.h }; }
            var g = groups[id];
            return g ? { x: g.x + g.w / 2, top: g.y, bottom: g.y + SITE_HEADER } : null;
        }

        /* BEGIN grid-router */
        // ---- the router: GridRouter.php, line for line. Integer coordinates and integer costs, the
        // same expansion order and the same tie-break, because a drag runs this on the picture the
        // server drew with that code and a line nobody touched must not move.
        var GridRouter = (function () {
            var C = 4, LANE_PITCH = 6, MAX_LANES = 16, BEND = 30, USED_FACTOR = 4, HUG_FACTOR = 4, TOL = 1;
            var PORTS_X = 5, PORTS_Y = 3, PORT_INSET = 10, PORT_INSET_SIDE = 24;

            function trunc(v) { return v < 0 ? Math.ceil(v) : Math.floor(v); }
            function round(v) { return v < 0 ? -Math.round(-v) : Math.round(v); }

            function between(rects, i, j, from, to, lo, hi, horizontal) {
                for (var k = 0; k < rects.length; k++) {
                    if (k === i || k === j) { continue; }
                    var t = rects[k];
                    if (horizontal) {
                        if (t.x < to && t.x + t.w > from && t.y < hi && t.y + t.h > lo) { return true; }
                    } else if (t.y < to && t.y + t.h > from && t.x < hi && t.x + t.w > lo) { return true; }
                }
                return false;
            }
            function lanes(set, start, end) {
                if (end < start) { return; }
                var n = Math.min(MAX_LANES, trunc((end - start) / LANE_PITCH) + 1);
                var first = start + trunc((end - start - (n - 1) * LANE_PITCH) / 2);
                for (var i = 0; i < n; i++) { set[first + i * LANE_PITCH] = true; }
            }
            function sortedKeys(set) {
                return Object.keys(set).map(Number).sort(function (a, b) { return a - b; });
            }

            function gridLines(rects, bounds) {
                var xs = {}, ys = {};
                var walls = [
                    { x: bounds.x - 1, y: bounds.y, w: 1, h: bounds.h },
                    { x: bounds.x + bounds.w, y: bounds.y, w: 1, h: bounds.h },
                    { x: bounds.x, y: bounds.y - 1, w: bounds.w, h: 1 },
                    { x: bounds.x, y: bounds.y + bounds.h, w: bounds.w, h: 1 },
                ];
                var all = rects.concat(walls);
                for (var i = 0; i < all.length; i++) {
                    for (var j = 0; j < all.length; j++) {
                        if (i === j) { continue; }
                        var a = all[i], b = all[j], lo, hi;
                        if (a.x + a.w <= b.x) {
                            lo = Math.max(a.y, b.y); hi = Math.min(a.y + a.h, b.y + b.h);
                            if (hi > lo && !between(all, i, j, a.x + a.w, b.x, lo, hi, true)) { lanes(xs, a.x + a.w + C, b.x - C); }
                        }
                        if (a.y + a.h <= b.y) {
                            lo = Math.max(a.x, b.x); hi = Math.min(a.x + a.w, b.x + b.w);
                            if (hi > lo && !between(all, i, j, a.y + a.h, b.y, lo, hi, false)) { lanes(ys, a.y + a.h + C, b.y - C); }
                        }
                    }
                }
                return { xs: sortedKeys(xs), ys: sortedKeys(ys) };
            }

            function shared(used, line, a, b) {
                var sum = 0, list = used[line] || [];
                for (var i = 0; i < list.length; i++) {
                    var overlap = Math.min(b, list[i][1]) - Math.max(a, list[i][0]);
                    if (overlap > 0) { sum += overlap; }
                }
                return sum;
            }
            function spread(start, length, max, inset) {
                var span = length - 2 * inset;
                var n = Math.max(1, Math.min(max, trunc(Math.max(0, span) / 28) + 1));
                if (n === 1) { return [start + trunc(length / 2)]; }
                var out = [];
                for (var i = 0; i < n; i++) { out.push(start + inset + trunc(span * i / (n - 1))); }
                return out;
            }
            function sideOf(rect, p) {
                if (p[0] >= rect.x + rect.w) { return 'right'; }
                if (p[0] <= rect.x) { return 'left'; }
                if (p[1] >= rect.y + rect.h) { return 'bottom'; }
                return 'top';
            }
            function ends(rect, fixed, used) {
                var ports = [], i;
                if (fixed) {
                    ports.push([fixed, sideOf(rect, fixed)]);
                } else {
                    var px = spread(rect.x, rect.w, PORTS_X, PORT_INSET), py = spread(rect.y, rect.h, PORTS_Y, PORT_INSET_SIDE);
                    for (i = 0; i < px.length; i++) { ports.push([[px[i], rect.y], 'top']); ports.push([[px[i], rect.y + rect.h], 'bottom']); }
                    for (i = 0; i < py.length; i++) { ports.push([[rect.x, py[i]], 'left']); ports.push([[rect.x + rect.w, py[i]], 'right']); }
                }
                var extraX = {}, extraY = {}, list = [];
                ports.forEach(function (entry) {
                    var port = entry[0], side = entry[1], x = port[0], y = port[1], node, dir;
                    if (side === 'top') { node = [x, y - C]; dir = 0; }
                    else if (side === 'right') { node = [x + C, y]; dir = 1; }
                    else if (side === 'bottom') { node = [x, y + C]; dir = 2; }
                    else { node = [x - C, y]; dir = 3; }
                    extraX[x] = true; extraY[y] = true; extraX[node[0]] = true; extraY[node[1]] = true;
                    var sh = dir % 2 === 0 ? shared(used, 'v:' + x, Math.min(y, node[1]), Math.max(y, node[1])) : shared(used, 'h:' + y, Math.min(x, node[0]), Math.max(x, node[0]));
                    list.push({ port: port, node: node, dir: dir, cost: C + USED_FACTOR * sh });
                });
                return { ends: list, xs: Object.keys(extraX).map(Number), ys: Object.keys(extraY).map(Number) };
            }
            function uniqueSorted(list) {
                var seen = {}, out = [];
                list.forEach(function (v) { if (!seen[v]) { seen[v] = true; out.push(v); } });
                return out.sort(function (a, b) { return a - b; });
            }
            function distance(x, y, r) {
                return Math.max(r.x - x, 0, x - (r.x + r.w)) + Math.max(r.y - y, 0, y - (r.y + r.h));
            }
            function blockedSegment(axis, line, a, b, rects) {
                for (var i = 0; i < rects.length; i++) {
                    var r = rects[i];
                    if (axis === 0) {
                        if (line > r.y + TOL && line < r.y + r.h - TOL && a < r.x + r.w - TOL && b > r.x + TOL) { return true; }
                    } else if (line > r.x + TOL && line < r.x + r.w - TOL && a < r.y + r.h - TOL && b > r.y + TOL) { return true; }
                }
                return false;
            }
            function hugging(axis, line, a, b, rects) {
                var sum = 0;
                for (var i = 0; i < rects.length; i++) {
                    var r = rects[i];
                    if (axis === 0) {
                        if (line === r.y - C || line === r.y + r.h + C) { sum += Math.max(0, Math.min(b, r.x + r.w) - Math.max(a, r.x)); }
                    } else if (line === r.x - C || line === r.x + r.w + C) { sum += Math.max(0, Math.min(b, r.y + r.h) - Math.max(a, r.y)); }
                }
                return sum;
            }
            function simplify(points) {
                var out = [];
                points.forEach(function (p) {
                    var n = out.length;
                    if (n > 0 && out[n - 1][0] === p[0] && out[n - 1][1] === p[1]) { return; }
                    if (n > 1) {
                        var a = out[n - 2], b = out[n - 1];
                        if ((a[0] === b[0] && b[0] === p[0]) || (a[1] === b[1] && b[1] === p[1])) { out[n - 1] = p; return; }
                    }
                    out.push(p);
                });
                return out;
            }
            function facing(from, to) {
                var dx = (to.x + to.w / 2) - (from.x + from.w / 2), dy = (to.y + to.h / 2) - (from.y + from.h / 2);
                var mid = function (r, which) {
                    if (which === 'right') { return [r.x + r.w, round(r.y + r.h / 2)]; }
                    if (which === 'left') { return [r.x, round(r.y + r.h / 2)]; }
                    if (which === 'bottom') { return [round(r.x + r.w / 2), r.y + r.h]; }
                    return [round(r.x + r.w / 2), r.y];
                };
                if (Math.abs(dx) >= Math.abs(dy)) { return dx >= 0 ? [mid(from, 'right'), mid(to, 'left')] : [mid(from, 'left'), mid(to, 'right')]; }
                return dy >= 0 ? [mid(from, 'bottom'), mid(to, 'top')] : [mid(from, 'top'), mid(to, 'bottom')];
            }
            function fallback(from, to, ports) {
                var p = ports || facing(from, to), mid = round((p[0][1] + p[1][1]) / 2);
                return simplify([p[0], [p[0][0], mid], [p[1][0], mid], p[1]]);
            }

            // a binary heap on (f, insertion order)
            function Heap() { this.items = []; }
            Heap.prototype.less = function (a, b) { return a[0] < b[0] || (a[0] === b[0] && a[1] < b[1]); };
            Heap.prototype.push = function (f, seq, state) {
                var items = this.items, i = items.length;
                items.push([f, seq, state]);
                while (i > 0) {
                    var p = (i - 1) >> 1;
                    if (!this.less(items[i], items[p])) { break; }
                    var t = items[i]; items[i] = items[p]; items[p] = t;
                    i = p;
                }
            };
            Heap.prototype.pop = function () {
                var items = this.items;
                if (!items.length) { return null; }
                var top = items[0], last = items.pop();
                if (items.length) {
                    items[0] = last;
                    var n = items.length, i = 0;
                    while (true) {
                        var l = 2 * i + 1, r = l + 1, m = i;
                        if (l < n && this.less(items[l], items[m])) { m = l; }
                        if (r < n && this.less(items[r], items[m])) { m = r; }
                        if (m === i) { break; }
                        var t = items[i]; items[i] = items[m]; items[m] = t;
                        i = m;
                    }
                }
                return top;
            };

            function route(from, to, obstacles, grid, used, ports) {
                var blockers = obstacles.concat([from, to]);
                var starts = ends(from, ports ? ports[0] : null, used);
                var goals = ends(to, ports ? ports[1] : null, used);
                var xs = uniqueSorted(grid.xs.concat(starts.xs, goals.xs));
                var ys = uniqueSorted(grid.ys.concat(starts.ys, goals.ys));
                var xIndex = {}, yIndex = {};
                xs.forEach(function (v, i) { xIndex[v] = i; });
                ys.forEach(function (v, i) { yIndex[v] = i; });
                var nx = xs.length, ny = ys.length, total = nx * ny * 4;
                var dxs = [0, 1, 0, -1], dys = [-1, 0, 1, 0];

                var goalAt = {};
                goals.ends.forEach(function (end, index) {
                    var key = xIndex[end.node[0]] * ny + yIndex[end.node[1]];
                    (goalAt[key] = goalAt[key] || []).push(index);
                });

                var heap = new Heap(), cost = {}, came = {}, startOf = {}, closed = {}, seq = 0;
                var h = function (x, y) { return distance(x, y, to); };
                starts.ends.forEach(function (end, index) {
                    var state = (xIndex[end.node[0]] * ny + yIndex[end.node[1]]) * 4 + end.dir;
                    if (cost[state] === undefined || end.cost < cost[state]) {
                        cost[state] = end.cost;
                        came[state] = -1;
                        startOf[state] = index;
                        heap.push(end.cost + h(end.node[0], end.node[1]), seq++, state);
                    }
                });

                var memo = {};
                var blocked = function (axis, line, a, b) {
                    var key = axis + ':' + line + ':' + a + ':' + b;
                    if (memo[key] === undefined) { memo[key] = blockedSegment(axis, line, a, b, blockers); }
                    return memo[key];
                };

                var final = null, finalEnd = null, expanded = 0, item;
                while ((item = heap.pop()) !== null) {
                    var state = item[2];
                    if (closed[state]) { continue; }
                    closed[state] = true;
                    if (state >= total) { finalEnd = goals.ends[state - total]; final = came[state]; break; }
                    if (++expanded > 60000) { break; }
                    var node = Math.floor(state / 4), dir = state % 4, g = cost[state];
                    var ix = Math.floor(node / ny), iy = node % ny;

                    var here = goalAt[node] || [];
                    for (var gi = 0; gi < here.length; gi++) {
                        var end = goals.ends[here[gi]];
                        var ng0 = g + end.cost + (end.dir === (dir + 2) % 4 ? 0 : BEND);
                        var goalState = total + here[gi];
                        if (cost[goalState] === undefined || ng0 < cost[goalState]) {
                            cost[goalState] = ng0; came[goalState] = state; heap.push(ng0, seq++, goalState);
                        }
                    }

                    for (var d = 0; d < 4; d++) {
                        if (d === (dir + 2) % 4) { continue; }
                        var jx = ix + dxs[d], jy = iy + dys[d];
                        if (jx < 0 || jy < 0 || jx >= nx || jy >= ny) { continue; }
                        var a, b, sh;
                        if (d % 2 === 0) {
                            a = Math.min(ys[iy], ys[jy]); b = Math.max(ys[iy], ys[jy]);
                            if (blocked(1, xs[ix], a, b)) { continue; }
                            sh = shared(used, 'v:' + xs[ix], a, b) + HUG_FACTOR * hugging(1, xs[ix], a, b, blockers);
                        } else {
                            a = Math.min(xs[ix], xs[jx]); b = Math.max(xs[ix], xs[jx]);
                            if (blocked(0, ys[iy], a, b)) { continue; }
                            sh = shared(used, 'h:' + ys[iy], a, b) + HUG_FACTOR * hugging(0, ys[iy], a, b, blockers);
                        }
                        var ng = g + (b - a) + USED_FACTOR * sh + (d === dir ? 0 : BEND);
                        var next = (jx * ny + jy) * 4 + d;
                        if (cost[next] === undefined || ng < cost[next]) {
                            cost[next] = ng; came[next] = state;
                            heap.push(ng + h(xs[jx], ys[jy]), seq++, next);
                        }
                    }
                }
                if (final === null) { return fallback(from, to, ports); }

                var points = [], at = final, startEnd;
                while (true) {
                    var nd = Math.floor(at / 4);
                    points.push([xs[Math.floor(nd / ny)], ys[nd % ny]]);
                    var parent = came[at];
                    if (parent < 0) { startEnd = starts.ends[startOf[at]]; break; }
                    at = parent;
                }
                points.reverse();
                var path = simplify([startEnd.port].concat(points, [finalEnd.port]));
                for (var i = 1; i < path.length; i++) {
                    if (path[i][1] === path[i - 1][1] && path[i][0] !== path[i - 1][0]) {
                        (used['h:' + path[i][1]] = used['h:' + path[i][1]] || []).push([Math.min(path[i][0], path[i - 1][0]), Math.max(path[i][0], path[i - 1][0])]);
                    } else if (path[i][0] === path[i - 1][0] && path[i][1] !== path[i - 1][1]) {
                        (used['v:' + path[i][0]] = used['v:' + path[i][0]] || []).push([Math.min(path[i][1], path[i - 1][1]), Math.max(path[i][1], path[i - 1][1])]);
                    }
                }
                return path;
            }
            return { lines: gridLines, route: route, facing: facing };
        })();
        /* END grid-router */

        // ---- geometry shared with EagleLayout: ports, clean paths, labels
        function side(r, which) {
            if (which === 'right') { return [r.x + r.w, Math.round(r.y + r.h / 2)]; }
            if (which === 'left') { return [r.x, Math.round(r.y + r.h / 2)]; }
            if (which === 'bottom') { return [Math.round(r.x + r.w / 2), r.y + r.h]; }
            return [Math.round(r.x + r.w / 2), r.y];
        }
        function ports(from, to) {
            var dx = (to.x + to.w / 2) - (from.x + from.w / 2);
            var dy = (to.y + to.h / 2) - (from.y + from.h / 2);
            if (Math.abs(dx) >= Math.abs(dy)) {
                return dx >= 0 ? [side(from, 'right'), side(to, 'left')] : [side(from, 'left'), side(to, 'right')];
            }
            return dy >= 0 ? [side(from, 'bottom'), side(to, 'top')] : [side(from, 'top'), side(to, 'bottom')];
        }
        function segmentClean(a, b, obstacles) {
            var length = Math.sqrt(Math.pow(b[0] - a[0], 2) + Math.pow(b[1] - a[1], 2));
            var samples = [];
            for (var d = 0; d <= length; d += 1) {
                var t = length > 0 ? d / length : 0;
                samples.push([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t]);
            }
            samples.push(b);
            for (var i = 0; i < samples.length; i++) {
                for (var j = 0; j < obstacles.length; j++) {
                    var r = obstacles[j], p = samples[i];
                    var inside = Math.min(p[0] - r.x, r.x + r.w - p[0], p[1] - r.y, r.y + r.h - p[1]);
                    if (inside > INSIDE_TOL) { return false; }
                }
            }
            return true;
        }
        function pathClean(points, obstacles) {
            for (var i = 1; i < points.length; i++) {
                if (points[i][0] === points[i - 1][0] && points[i][1] === points[i - 1][1]) { continue; }
                if (!segmentClean(points[i - 1], points[i], obstacles)) { return false; }
            }
            return true;
        }
        function inset(r, by) { return { x: r.x + by, y: r.y + by, w: Math.max(1, r.w - 2 * by), h: Math.max(1, r.h - 2 * by) }; }

        // The routing context of the picture as it is now: what a line has to miss, the grid it
        // may run on, and the spans the edges routed so far already took.
        function context() {
            var all = [], loose = {}, group = {};
            Object.keys(groups).forEach(function (k) { group[k] = { x: groups[k].x, y: groups[k].y, w: groups[k].w, h: groups[k].h }; all.push(group[k]); });
            Object.keys(nodes).forEach(function (id) {
                var n = nodes[id];
                if ((n.kind === 'member' || n.kind === 'far') && !n.site) { loose[id] = { x: n.x, y: n.y, w: n.w, h: n.h }; all.push(loose[id]); }
            });
            var left = all.length ? Math.min.apply(null, all.map(function (r) { return r.x; })) : 0;
            var top = all.length ? Math.min.apply(null, all.map(function (r) { return r.y; })) : 0;
            var right = all.length ? Math.max.apply(null, all.map(function (r) { return r.x + r.w; })) : W;
            var bottom = all.length ? Math.max.apply(null, all.map(function (r) { return r.y + r.h; })) : H;
            var bounds = { x: Math.min(0, left - MARGIN), y: Math.min(0, top - MARGIN) };
            bounds.w = Math.max(W, right + MARGIN) - bounds.x;
            bounds.h = Math.max(H, bottom + MARGIN) - bounds.y;
            return { group: group, loose: loose, bounds: bounds, grid: GridRouter.lines(all, bounds), used: {} };
        }

        function route(from, to, obstacles, ctx, fixed) {
            return GridRouter.route(from, to, obstacles, ctx.grid, ctx.used, fixed || null);
        }

        // ---- inside one compound
        function onPerimeter(r, p) {
            if (p[1] <= r.y) { return p[0] - r.x; }
            if (p[0] >= r.x + r.w) { return r.w + p[1] - r.y; }
            if (p[1] >= r.y + r.h) { return r.w + r.h + r.x + r.w - p[0]; }
            return 2 * r.w + r.h + r.y + r.h - p[1];
        }
        function walk(r, from, to) {
            var perimeter = 2 * r.w + 2 * r.h;
            var a = Math.round(onPerimeter(r, from)), b = Math.round(onPerimeter(r, to));
            var clockwise = ((b - a) % perimeter + perimeter) % perimeter;
            var corners = {};
            corners[0] = [r.x, r.y];
            corners[r.w] = [r.x + r.w, r.y];
            corners[r.w + r.h] = [r.x + r.w, r.y + r.h];
            corners[2 * r.w + r.h] = [r.x, r.y + r.h];
            var order = clockwise <= perimeter - clockwise ? 1 : -1;
            var steps = order === 1 ? clockwise : perimeter - clockwise;
            var out = [];
            for (var i = 1; i < steps; i++) {
                var at = (((a + order * i) % perimeter) + perimeter) % perimeter;
                if (corners[at]) { out.push(corners[at]); }
            }
            return out;
        }

        function padRoute(rect, from, to, siblings) {
            var box = inset(rect, CLEARANCE);
            var p = ports(from, to);
            var exit = function (port, own) {
                var order = ['right', 'bottom', 'left', 'top'];
                var at = port[0] >= own.x + own.w ? 0 : port[1] >= own.y + own.h ? 1 : port[0] <= own.x ? 2 : 3;
                for (var i = 0; i < 4; i++) {
                    var which = order[(at + i) % 4], q = side(own, which);
                    var hit = which === 'right' ? [box.x + box.w, q[1]] : which === 'left' ? [box.x, q[1]]
                        : which === 'bottom' ? [q[0], box.y + box.h] : [q[0], box.y];
                    if (pathClean([q, hit], siblings)) { return [q, hit]; }
                }
                var q0 = side(own, order[at]);
                return [q0, order[at] === 'right' ? [box.x + box.w, q0[1]] : order[at] === 'left' ? [box.x, q0[1]]
                    : order[at] === 'bottom' ? [q0[0], box.y + box.h] : [q0[0], box.y]];
            };
            var s = exit(p[0], from), d = exit(p[1], to);
            return [s[0], s[1]].concat(walk(box, s[1], d[1]), [d[1], d[0]]);
        }

        function polyline(points) {
            var out = '', previous = null;
            points.forEach(function (p) {
                var v = [Math.round(p[0]), Math.round(p[1])];
                if (previous && v[0] === previous[0] && v[1] === previous[1]) { return; }
                out += (out === '' ? 'M' : ' L') + v[0] + ' ' + v[1];
                previous = v;
            });
            return out;
        }

        // ---- labels: the middle of a segment that holds the text, not on top of another label
        function labelRect(x, y, label) {
            var w = Math.ceil(label.length * EDGE_CHAR_W) + 8;
            return { x: x - Math.floor(w / 2), y: y - 9, w: w, h: 12 };
        }
        function labelOn(points, label, taken) {
            if (label === '') { return [0, 0, false]; }
            var need = labelRect(0, 0, label).w, candidates = [], i;
            for (i = 1; i < points.length; i++) {
                var dx = Math.abs(points[i][0] - points[i - 1][0]), dy = Math.abs(points[i][1] - points[i - 1][1]);
                var horizontal = dy === 0;
                if (horizontal ? dx < need + 8 : dy < 30) { continue; }
                candidates.push([(horizontal ? 100000 : 0) + dx + dy, i, Math.round((points[i][0] + points[i - 1][0]) / 2), Math.round((points[i][1] + points[i - 1][1]) / 2)]);
            }
            candidates.sort(function (a, b) { return (b[0] - a[0]) || (a[1] - b[1]); });
            for (i = 0; i < candidates.length; i++) {
                var rect = labelRect(candidates[i][2], candidates[i][3], label), clear = true;
                for (var j = 0; j < (taken || []).length; j++) {
                    var t = taken[j];
                    if (rect.x < t.x + t.w + 2 && t.x < rect.x + rect.w + 2 && rect.y < t.y + t.h + 2 && t.y < rect.y + rect.h + 2) { clear = false; break; }
                }
                if (clear) { return [candidates[i][2], candidates[i][3], true]; }
            }
            return [0, 0, false];
        }

        // ---- draw
        function moveNode(n) {
            var dx = n.x - n.hx, dy = n.y - n.hy;
            if (dx || dy) { n.el.setAttribute('transform', 'translate(' + dx + ' ' + dy + ')'); } else { n.el.removeAttribute('transform'); }
        }
        function moveGroup(g) {
            g.rect.setAttribute('x', g.x); g.rect.setAttribute('y', g.y);
            g.rect.setAttribute('width', g.w); g.rect.setAttribute('height', g.h);
            var dx = g.x - g.hx, dy = g.y - g.hy;
            if (g.caption) {
                if (dx || dy) { g.caption.setAttribute('transform', 'translate(' + dx + ' ' + dy + ')'); } else { g.caption.removeAttribute('transform'); }
            }
            // the note is right-aligned in the header, so it follows the box's right edge
            if (g.note) { g.note.setAttribute('x', g.x + g.w - 6); g.note.setAttribute('y', g.y + 14); }
        }
        function resizeGroup(g) {
            var own = g.members.map(function (id) { return nodes[id]; }).filter(Boolean);
            if (!own.length) { return; }
            var left = Math.min.apply(null, own.map(function (n) { return n.x; }));
            var top = Math.min.apply(null, own.map(function (n) { return n.y; }));
            var right = Math.max.apply(null, own.map(function (n) { return n.x + n.w; }));
            var bottom = Math.max.apply(null, own.map(function (n) { return n.y + n.h; }));
            // the members' bounding box plus the pad, with the header kept above it
            g.x = left - SITE_PAD; g.w = right - left + 2 * SITE_PAD;
            g.y = top - SITE_PAD - SITE_HEADER; g.h = bottom - top + 2 * SITE_PAD + SITE_HEADER;
        }

        function obstaclesFor(ctx, sites, cards) {
            var out = [];
            Object.keys(ctx.group).forEach(function (k) { if (sites.indexOf(k) < 0) { out.push(ctx.group[k]); } });
            Object.keys(ctx.loose).forEach(function (k) { if (cards.indexOf(k) < 0) { out.push(ctx.loose[k]); } });
            return out;
        }
        function siblingsOf(site, a, b) {
            return Object.keys(nodes).filter(function (id) {
                return nodes[id].site === site && nodes[id].kind === 'member' && id !== a && id !== b;
            }).map(function (id) { return { x: nodes[id].x, y: nodes[id].y, w: nodes[id].w, h: nodes[id].h }; });
        }
        function centreDistance(a, b) {
            return Math.abs(2 * a.x + a.w - 2 * b.x - b.w) + Math.abs(2 * a.y + a.h - 2 * b.y - b.h);
        }

        // One pass for the edges that need no router (arcs, brackets, a short connector, stubs), then
        // the routed ones, the short ones first -- the order EagleLayout::applyRoutes() uses, because
        // the order decides who gets the direct lane.
        function redraw() {
            Object.keys(nodes).forEach(function (id) {
                var n = nodes[id];
                // an outside dot and an attached card are not routed and are not saved: they
                // simply stick to the card they hang from
                if (n.anchor && nodes[n.anchor]) { n.x = n.hx + (nodes[n.anchor].x - nodes[n.anchor].hx); n.y = n.hy + (nodes[n.anchor].y - nodes[n.anchor].hy); }
                moveNode(n);
            });
            Object.keys(groups).forEach(function (k) { moveGroup(groups[k]); });

            var ctx = context(), taken = [], pending = [];
            links.slice().sort(function (a, b) {
                return a.el.dataset.focus < b.el.dataset.focus ? -1 : a.el.dataset.focus > b.el.dataset.focus ? 1 : 0;
            }).forEach(function (e) {
                var request = drawEdge(e, taken);
                if (request) { request.edge = e; request.id = e.el.dataset.focus; pending.push(request); }
            });
            pending.sort(function (a, b) {
                var d = centreDistance(a.from, a.to) - centreDistance(b.from, b.to);
                return d || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
            });
            pending.forEach(function (request) {
                var points = request.pad
                    ? padRoute(request.pad, request.from, request.to, request.siblings)
                    : route(request.from, request.to, request.obstacles(ctx), ctx, request.ports);
                request.edge.path.setAttribute('d', polyline(points));
                place(request.edge, points, taken);
            });
        }

        // the label of a routed edge, where no other label is
        function place(e, points, taken) {
            if (!e.text) { return; }
            var variants = e.labels.length ? e.labels : [e.label], at = [0, 0, false], shown = '';
            for (var i = 0; i < variants.length; i++) {
                at = labelOn(points, variants[i], taken);
                if (at[2]) { shown = variants[i]; taken.push(labelRect(at[0], at[1], shown)); break; }
            }
            e.text.setAttribute('x', at[0]); e.text.setAttribute('y', at[1]);
            e.text.textContent = shown;
        }
        // the label of an edge that is not routed: shown or not, in the place it has
        function fixedLabel(e, x, y, show, taken) {
            if (!e.text) { return; }
            e.text.setAttribute('x', x); e.text.setAttribute('y', y);
            e.text.textContent = show ? e.label : '';
            if (show && e.label !== '') { taken.push(labelRect(x, y, e.label)); }
        }

        /**
         * Draw an edge that needs no router and return null, or return what the router needs.
         */
        function drawEdge(e, taken) {
            var pa = anchorOf(e.a), pb = anchorOf(e.b);
            if (!pa || !pb) { return null; }

            if (e.shape === 'hanger' || e.shape === 'stub') {
                var target = e.shape === 'stub' ? [pa.x, pa.bottom + 20] : [pb.x, pb.top];
                e.path.setAttribute('d', polyline([[pa.x, pa.bottom], target]));
                return null;
            }
            if (e.kind === 'overlay' || e.kind === 'asymmetric' || e.kind === 'missing') {
                // exempt: a fault or a partial mesh is a mark, and a gutter would hide it
                e.path.setAttribute('d', pa.top === pb.top
                    ? 'M' + pa.x + ' ' + pa.top + ' Q' + Math.round((pa.x + pb.x) / 2) + ' ' + (pa.top - Math.round(30 + Math.sqrt(Math.abs(pa.x - pb.x)) * 3)) + ' ' + pb.x + ' ' + pb.top
                    : polyline([[pa.x, pa.top < pb.top ? pa.bottom : pa.top], [pb.x, pa.top < pb.top ? pb.top : pb.bottom]]));
                fixedLabel(e, Math.round((pa.x + pb.x) / 2), Math.min(pa.top, pb.top) - 8, e.label !== '', taken);
                return null;
            }
            if (e.kind === 'esi') { return drawEsi(e, taken); }

            var from = rectOf(e.a), to = rectOf(e.b);
            if (!from || !to) { return null; }

            if (e.kind === 'trunk') {
                var box = groups[e.b];
                var fixed = [[Math.round(from.x + from.w / 2), from.y + from.h], [Math.round(box.x + box.w / 2), box.y]];
                var blockers = function (ctx) { return obstaclesFor(ctx, [e.siteA, e.b], [e.a]); };
                // one straight stroke while nothing is in the way, else routed with the same end points
                if (pathClean(fixed, obstaclesFor({ group: groupRects(), loose: looseRects() }, [e.siteA, e.b], [e.a]))) {
                    e.path.setAttribute('d', polyline(fixed));
                    var t = labelOn(fixed, e.label, taken);
                    fixedLabel(e, t[0], t[1], t[2], taken);
                    return null;
                }
                return { from: from, to: to, ports: fixed, obstacles: blockers };
            }

            // inside one site: a short connector between two cards that face each other with
            // nothing between, else the walk round the compound; never an arc
            if (e.siteA && e.siteA === e.siteB) {
                var siblings = siblingsOf(e.siteA, e.a, e.b), p = ports(from, to);
                if ((p[0][0] === p[1][0] || p[0][1] === p[1][1]) && pathClean(p, siblings)) {
                    e.path.setAttribute('d', polyline(p));
                    if (e.text) { e.text.textContent = ''; }
                    return null;
                }
                var group = { x: groups[e.siteA].x, y: groups[e.siteA].y, w: groups[e.siteA].w, h: groups[e.siteA].h };
                return { from: from, to: to, pad: group, siblings: siblings, obstacles: null };
            }

            return { from: from, to: to, ports: null, obstacles: function (ctx) { return obstaclesFor(ctx, [e.siteA, e.siteB], [e.a, e.b]); } };
        }
        function groupRects() {
            var out = {};
            Object.keys(groups).forEach(function (k) { out[k] = { x: groups[k].x, y: groups[k].y, w: groups[k].w, h: groups[k].h }; });
            return out;
        }
        function looseRects() {
            var out = {};
            Object.keys(nodes).forEach(function (id) {
                var n = nodes[id];
                if ((n.kind === 'member' || n.kind === 'far') && !n.site) { out[id] = { x: n.x, y: n.y, w: n.w, h: n.h }; }
            });
            return out;
        }

        /**
         * After a drag there is no reserved band and `row` / `col` are the server's grid, so the
         * shape is recomputed from the rects as they are now. A bracket the operator has pulled
         * apart stops being one rather than retargeting whatever card is underneath.
         */
        function drawEsi(e, taken) {
            var from = rectOf(e.a), to = rectOf(e.b);
            if (!from || !to) { return null; }
            var L = from.x <= to.x ? from : to, R = from.x <= to.x ? to : from;
            var gap = R.x - (L.x + L.w);
            var overlap = Math.min(L.y + L.h, R.y + R.h) - Math.max(L.y, R.y);
            var sameSite = e.siteA && e.siteA === e.siteB;

            if (sameSite && overlap >= 8 && gap >= -8 && gap <= CARD_W) {
                var bottom = Math.max(L.y + L.h, R.y + R.h), leg = bottom + 12;
                var bracket = [[L.x + L.w / 2, bottom], [L.x + L.w / 2, leg], [R.x + R.w / 2, leg], [R.x + R.w / 2, bottom]].map(function (p) { return [Math.round(p[0]), Math.round(p[1])]; });
                if (pathClean(bracket, siblingsOf(e.siteA, e.a, e.b))) {
                    e.path.setAttribute('d', polyline(bracket));
                    fixedLabel(e, Math.round((bracket[0][0] + bracket[3][0]) / 2), leg - 3, e.label.length * EDGE_CHAR_W <= Math.abs(bracket[0][0] - bracket[3][0]) - 8, taken);
                    return null;
                }
            }
            if (sameSite) {
                var upper = from.y <= to.y ? from : to, lower = from.y <= to.y ? to : from;
                var y = upper.y + upper.h + 12;
                var segment = [[upper.x + upper.w / 2, upper.y + upper.h], [upper.x + upper.w / 2, y], [lower.x + lower.w / 2, y], [lower.x + lower.w / 2, lower.y]].map(function (p) { return [Math.round(p[0]), Math.round(p[1])]; });
                var siblings = siblingsOf(e.siteA, e.a, e.b);
                if (pathClean(segment, siblings)) {
                    e.path.setAttribute('d', polyline(segment));
                    var at = labelOn(segment, e.label, taken);
                    fixedLabel(e, at[0], at[1], at[2], taken);
                    return null;
                }
                return { from: from, to: to, pad: { x: groups[e.siteA].x, y: groups[e.siteA].y, w: groups[e.siteA].w, h: groups[e.siteA].h }, siblings: siblings, obstacles: null };
            }
            return { from: from, to: to, ports: null, obstacles: function (ctx) { return obstaclesFor(ctx, [e.siteA, e.siteB], [e.a, e.b]); } };
        }

        // ================= the saved arrangement ============================================
        function document_() {
            var ids = Object.keys(nodes).filter(function (id) { return nodes[id].kind === 'member' || nodes[id].kind === 'far'; });
            // a collapsed site still lists its members, and their coordinates are kept even
            // though no card is in the DOM, so collapsing never changes this set
            Object.keys(groups).forEach(function (k) {
                groups[k].members.forEach(function (id) { if (ids.indexOf(id) < 0) { ids.push(id); } });
            });
            ids.sort();
            var out = { v: 1, members: ids, nodes: {}, groups: {} };
            ids.forEach(function (id) {
                var n = nodes[id] || offstage[id];
                if (n) { out.nodes[id] = { x: Math.round(n.x), y: Math.round(n.y) }; }
            });
            Object.keys(groups).forEach(function (k) { out.groups[k] = { x: Math.round(groups[k].x), y: Math.round(groups[k].y) }; });
            return out;
        }
        var offstage = {};
        function send(body) {
            try {
                fetch(wrap.dataset.layoutUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': wrap.dataset.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                    // reset posts and then follows its own href: without this the navigation
                    // cancels the request and the row survives the click
                    keepalive: true,
                });
            } catch (e) {}
        }
        function save() { send(document_()); }

        (function applySaved() {
            var holder = document.getElementById('eagle-layout');
            var saved = null;
            try { saved = JSON.parse(holder ? holder.textContent : 'null'); } catch (e) {}
            if (!saved || saved.v !== 1 || !saved.nodes) { return; }
            var finite = function (p) { return p && isFinite(p.x) && isFinite(p.y); };
            Object.keys(saved.nodes).forEach(function (id) {
                if (!finite(saved.nodes[id])) { return; }
                if (nodes[id]) { nodes[id].x = saved.nodes[id].x; nodes[id].y = saved.nodes[id].y; }
                else { offstage[id] = { x: saved.nodes[id].x, y: saved.nodes[id].y }; }
            });
            Object.keys(groups).forEach(function (k) { resizeGroup(groups[k]); });
            Object.keys(saved.groups || {}).forEach(function (k) {
                // a collapsed compound has no member cards to size it, so it keeps its own point
                if (groups[k] && finite(saved.groups[k]) && !groups[k].members.some(function (id) { return nodes[id]; })) {
                    groups[k].x = saved.groups[k].x; groups[k].y = saved.groups[k].y;
                }
            });
            redraw();   // applying what the server sent is not a save
        })();

        // ================= drag ==============================================================
        var THRESHOLD = 4;   // screen pixels; everything else here is user units
        var grab = null, pinned = {}, dragged = false;

        function units(ev, start) {
            var r = svg.getBoundingClientRect();
            return [(ev.clientX - start.cx) * box.w / r.width, (ev.clientY - start.cy) * box.h / r.height];
        }
        function moved(ev, start) { return Math.hypot(ev.clientX - start.cx, ev.clientY - start.cy) > THRESHOLD; }

        svg.addEventListener('mousedown', function (ev) {
            var card = ev.target.closest('.eg-card');
            var site = ev.target.closest('.eg-site');
            if (!card && !site) { return; }
            // no preventDefault here: in current browsers it suppresses the later click, and the
            // site caption is the <a> that collapses the site
            var id = card ? card.dataset.id : site.dataset.key;
            dragged = false;
            grab = { id: id, isCard: !!card, cx: ev.clientX, cy: ev.clientY, dragged: false, start: {} };
            var move = card ? [id] : [id].concat(Object.keys(nodes).filter(function (k) { return nodes[k].site === id; }));
            move.forEach(function (k) { if (nodes[k]) { grab.start[k] = [nodes[k].x, nodes[k].y]; } });
            if (!card) { grab.startGroup = [groups[id].x, groups[id].y]; }
        });

        window.addEventListener('mousemove', function (ev) {
            if (!grab) { return; }
            if (!grab.dragged && !moved(ev, grab)) { return; }
            grab.dragged = true;
            dragged = true;
            var d = units(ev, grab);
            Object.keys(grab.start).forEach(function (k) { nodes[k].x = grab.start[k][0] + d[0]; nodes[k].y = grab.start[k][1] + d[1]; });
            if (grab.isCard) {
                // a member never leaves its site; the compound grows to hold it
                if (nodes[grab.id].site) { resizeGroup(groups[nodes[grab.id].site]); }
            } else {
                groups[grab.id].x = grab.startGroup[0] + d[0];
                groups[grab.id].y = grab.startGroup[1] + d[1];
            }
            scheduleRedraw();
        });

        // the router runs on every edge, so a burst of mouse events is one redraw per frame
        var redrawQueued = false;
        function scheduleRedraw() {
            if (redrawQueued) { return; }
            redrawQueued = true;
            requestAnimationFrame(function () { redrawQueued = false; redraw(); });
        }

        window.addEventListener('mouseup', function () {
            if (!grab) { return; }
            var done = grab;
            grab = null;
            if (!done.dragged) { return; }
            pinned = {};
            Object.keys(done.start).forEach(function (k) { pinned[k] = true; });
            growCanvas();
            if (document.getElementById('eagle-gravity').checked) { settle(); } else { redraw(); save(); }
        });

        // a drag that left the picture bigger than the server drew it grows the canvas rather
        // than clipping; it never shrinks below the server size
        function growCanvas() {
            var right = W, bottom = H;
            Object.keys(nodes).forEach(function (id) { right = Math.max(right, nodes[id].x + nodes[id].w + MARGIN); bottom = Math.max(bottom, nodes[id].y + nodes[id].h + MARGIN); });
            Object.keys(groups).forEach(function (k) { right = Math.max(right, groups[k].x + groups[k].w + MARGIN); bottom = Math.max(bottom, groups[k].y + groups[k].h + MARGIN); });
            if (right > W || bottom > H) {
                W = Math.round(right); H = Math.round(bottom);
                wrap.dataset.w = W; wrap.dataset.h = H;
            }
        }

        // ================= gravity ===========================================================
        function neighbours() {
            var out = [];
            links.forEach(function (e) {
                if (e.kind === 'esi' || e.kind === 'attached' || e.kind === 'overlay' || e.kind === 'asymmetric' || e.kind === 'missing') { return; }
                // a trunk or a bundle does not end on a card: it expands to the members of the site
                // (or the card) at each end, or the spine and the sites would never move
                if (e.kind === 'trunk' || e.shape === 'bundle') {
                    var left = groups[e.a] ? groups[e.a].members : [e.a], right = groups[e.b] ? groups[e.b].members : [e.b];
                    left.forEach(function (l) { right.forEach(function (r) { if (nodes[l] && nodes[r]) { out.push([l, r]); } }); });
                    return;
                }
                if (nodes[e.a] && nodes[e.b]) { out.push([e.a, e.b]); }
            });
            return out;
        }

        function settle() {
            var pairs = neighbours();
            var velocity = {};
            Object.keys(nodes).forEach(function (id) { velocity[id] = [0, 0]; });
            var band = {};
            Object.keys(nodes).forEach(function (id) {
                var t = nodes[id].tier;
                if (!t) { return; }
                band[t] = band[t] || [];
                band[t].push(nodes[id].hy + nodes[id].h / 2);
            });
            Object.keys(band).forEach(function (t) { band[t] = band[t].reduce(function (a, b) { return a + b; }, 0) / band[t].length; });

            var frame = 0;
            (function step() {
                var snapshot = {};
                Object.keys(nodes).forEach(function (id) { snapshot[id] = [nodes[id].x + nodes[id].w / 2, nodes[id].y + nodes[id].h / 2]; });
                var before = {};
                Object.keys(nodes).forEach(function (id) { before[id] = [nodes[id].x, nodes[id].y]; });

                // 1. spring, on the unpinned neighbours of the pinned set only. The rest length
                // is the distance between the two home centres, so a settle does not ratchet
                var touched = {};
                pairs.forEach(function (pair) {
                    [[pair[0], pair[1]], [pair[1], pair[0]]].forEach(function (p) {
                        var self = nodes[p[0]], other = nodes[p[1]];
                        if (!self || !other || pinned[p[0]] || !pinned[p[1]]) { return; }
                        var rest = Math.hypot((other.hx + other.w / 2) - (self.hx + self.w / 2), (other.hy + other.h / 2) - (self.hy + self.h / 2));
                        var dx = snapshot[p[1]][0] - snapshot[p[0]][0], dy = snapshot[p[1]][1] - snapshot[p[0]][1];
                        var current = Math.hypot(dx, dy);
                        var ux = current > 0 ? dx / current : 0, uy = current > 0 ? dy / current : 0;
                        velocity[p[0]][0] = 0.65 * velocity[p[0]][0] + 0.08 * (current - rest) * ux;
                        velocity[p[0]][1] = 0.65 * velocity[p[0]][1] + 0.08 * (current - rest) * uy;
                        touched[p[0]] = true;
                    });
                });
                Object.keys(touched).forEach(function (id) { nodes[id].x += velocity[id][0]; nodes[id].y += velocity[id][1]; });

                // 2. separate overlapping cards, on the shallower axis
                var cards = Object.keys(nodes).filter(function (id) { return nodes[id].kind === 'member' || nodes[id].kind === 'far'; });
                for (var i = 0; i < cards.length; i++) {
                    for (var j = i + 1; j < cards.length; j++) {
                        var a = nodes[cards[i]], b = nodes[cards[j]];
                        var ox = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
                        var oy = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
                        if (ox <= 0 || oy <= 0) { continue; }
                        var axis = ox <= oy ? 0 : 1, depth = ox <= oy ? ox : oy;
                        var pa = pinned[cards[i]], pb = pinned[cards[j]];
                        if (pa && pb) { continue; }
                        var away = axis === 0 ? (a.x < b.x ? -1 : 1) : (a.y < b.y ? -1 : 1);
                        if (!pa && pb) { axis === 0 ? a.x += away * depth : a.y += away * depth; }
                        else if (pa && !pb) { axis === 0 ? b.x -= away * depth : b.y -= away * depth; }
                        else {
                            var first = cards[i] > cards[j] ? a : b, second = first === a ? b : a;
                            if (axis === 0) { first.x += depth / 2; second.x -= depth / 2; } else { first.y += depth / 2; second.y -= depth / 2; }
                        }
                    }
                }

                // 3. pull every unpinned neighbour back toward its own tier band. A spine the
                // operator dragged into the leaves is pinned and stays there
                Object.keys(touched).forEach(function (id) {
                    var n = nodes[id];
                    if (pinned[id] || band[n.tier] === undefined) { return; }
                    n.y += 0.05 * (band[n.tier] - (n.y + n.h / 2));
                });

                Object.keys(groups).forEach(function (k) { resizeGroup(groups[k]); });
                redraw();

                var motion = 0;
                Object.keys(nodes).forEach(function (id) { motion += Math.abs(nodes[id].x - before[id][0]) + Math.abs(nodes[id].y - before[id][1]); });
                frame++;
                if (motion < 0.5 || frame >= 40) { growCanvas(); redraw(); save(); return; }
                requestAnimationFrame(step);
            })();
        }

        function select(focus) {
            Array.prototype.forEach.call(document.querySelectorAll('#eagle-inspector [data-focus]'), function (el) {
                el.hidden = el.dataset.focus !== focus;
            });
            var empty = document.getElementById('eagle-inspector-empty');
            if (empty) { empty.hidden = !!focus; }
            Array.prototype.forEach.call(svg.querySelectorAll('.eg-sel'), function (el) { el.classList.remove('eg-sel'); });
            if (!focus) { return; }
            var node = svg.querySelector('[data-focus="' + (window.CSS && CSS.escape ? CSS.escape(focus) : focus) + '"]');
            if (node) { node.classList.add('eg-sel'); }
            var panel = document.querySelector('#eagle-inspector [data-focus="' + (window.CSS && CSS.escape ? CSS.escape(focus) : focus) + '"]');
            if (panel && window.netconfPromote) { window.netconfPromote(panel); }
        }

        svg.addEventListener('click', function (ev) {
            // a gesture over 4 screen px was a drag, not a selection, and the site caption is an
            // <a>: preventDefault only once the pointer has actually moved, or collapse breaks
            if (dragged) { ev.preventDefault(); return; }
            var target = ev.target.closest('.eg-card, .eg-edge');
            if (!target) { return; }
            var focus = target.dataset.focus;
            var url = new URL(window.location.href);
            url.searchParams.set('focus', focus);
            history.replaceState(null, '', url.toString());
            select(focus);
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') { return; }
            var url = new URL(window.location.href);
            url.searchParams.delete('focus');
            history.replaceState(null, '', url.toString());
            select('');
        });

        // collapsed by default on a phone, where an open key would cover the cards it explains
        var keyBody = document.getElementById('eagle-key-body');
        var keyToggle = document.getElementById('eagle-key-toggle');
        function showKey(open) { keyBody.hidden = !open; keyToggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        showKey(!window.matchMedia || window.matchMedia('(min-width: 600px)').matches);
        keyToggle.addEventListener('click', function () { showKey(keyBody.hidden); });

        var initial = new URL(window.location.href).searchParams.get('focus');
        if (initial) { select(initial); }
    })();
    </script>
    @endpush
@endonce
