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
        <span class="btn-group btn-group-xs" style="margin-left: 10px;" role="group">
            <a class="btn btn-default" href="{{ request()->fullUrlWithQuery(['focus' => null, 'collapse' => count($collapsed) === count($eg['groups']) ? [] : array_column($eg['groups'], 'key')]) }}">{{ count($collapsed) === count($eg['groups']) && $eg['groups'] !== [] ? 'expand all sites' : 'collapse all sites' }}</a>
            @if ($eg['counts']['outside'] > 0)
                <a class="btn btn-default {{ $view['outside'] ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['focus' => null, 'outside' => $view['outside'] ? null : 1]) }}">sessions out of the fabric ({{ $eg['counts']['outside'] }})</a>
            @endif
            <a class="btn btn-default {{ $view['attached'] !== null ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['focus' => null, 'attached' => $view['attached'] !== null ? null : 1]) }}">attached devices {{ $view['attached'] === null ? '' : '(' . count($view['attached']) . ')' }}</a>
        </span>
        <span class="pull-right btn-group btn-group-xs" role="group">
            <button type="button" class="btn btn-default" id="eagle-out" title="zoom out">&minus;</button>
            <button type="button" class="btn btn-default" id="eagle-in" title="zoom in">+</button>
            <button type="button" class="btn btn-default" id="eagle-fit" title="fit the whole picture">fit</button>
            <button type="button" class="btn btn-default" id="eagle-actual" title="draw at layout size and scroll">1:1</button>
            <a class="btn btn-default" href="{{ route('netconf.fabric', [$fabric['id'], 'overview']) }}" id="eagle-reset" title="forget the stored camera and every view flag">reset</a>
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
        html.dark .netconf-eagle .eg-chip-text { fill: #e8e8e8; }
        html.dark .netconf-eagle .eg-chip-danger { fill: #4a2a2a; }
        html.dark .netconf-eagle .eg-chip-warning { fill: #4a3b24; }
        html.dark .netconf-eagle .eg-chip-info { fill: #23384a; }
        html.dark .netconf-eagle .eg-chip-muted { fill: #33373d; }
        html.dark .netconf-eagle .eg-down-dot { fill: #e87a76; }
        html.dark .netconf-eagle .eg-outside-dot { fill: #b794e0; }
                                                                            </style>

    <div id="eagle-frame">
    <div id="eagle-view" data-fabric="{{ $fabric['id'] }}" data-w="{{ $eg['width'] }}" data-h="{{ $eg['height'] }}">
        <svg id="eagle-svg" width="{{ $eg['width'] }}" height="{{ $eg['height'] }}" viewBox="0 0 {{ $eg['width'] }} {{ $eg['height'] }}" xmlns="http://www.w3.org/2000/svg">
            @foreach ($eg['groups'] as $g)
                <a href="{{ $toggleSite($g['key']) }}" class="eg-site">
                    <rect class="eg-site-box {{ $g['state'] === 'down' ? 'eg-site-down' : ($g['state'] === 'warning' ? 'eg-site-warning' : '') }}" x="{{ $g['x'] }}" y="{{ $g['y'] }}" width="{{ $g['w'] }}" height="{{ $g['h'] }}" rx="6" stroke-dasharray="4 3">
                        <title>{{ $g['label'] ?? 'no location' }} — {{ count($g['members']) }} member{{ count($g['members']) === 1 ? '' : 's' }}, click to {{ $g['collapsed'] ? 'expand' : 'collapse' }}</title>
                    </rect>
                    {{-- the caption is cut to the box: a location is free text and the ones that
                         exist are long enough to run across the next compound --}}
                    <text class="eg-caption" x="{{ $g['x'] + 6 }}" y="{{ $g['y'] + 14 }}" font-size="11">{{ $g['collapsed'] ? '▸' : '▾' }} {{ $g['caption'] }}<title>{{ $g['label'] ?? 'no location' }}</title></text>
                </a>
            @endforeach

            @foreach ($eg['edges'] as $e)
                <g class="eg-edge" data-focus="{{ $e['id'] }}">
                    <path d="{{ $e['path'] }}" fill="none" stroke-width="{{ $e['width'] }}" @if ($e['dash'] !== '') stroke-dasharray="{{ $e['dash'] }}" @endif class="{{ $e['strokeClass'] }} {{ $e['highlight'] ? 'eg-hl' : '' }}">
                        <title>{{ $e['title'] }}</title>
                    </path>
                    @if (($e['label'] ?? '') !== '')
                        <text class="eg-muted" x="{{ $e['lx'] }}" y="{{ $e['ly'] }}" font-size="9" text-anchor="middle">{{ $e['label'] }}</text>
                    @endif
                </g>
            @endforeach

            @foreach ($eg['nodes'] as $id => $n)
                <g class="eg-card" data-focus="{{ $n['kind'] === 'member' ? 'member:' . $n['ip'] : $id }}">
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
            <span><i class="eg-swatch eg-key-trunk"></i> trunk</span>
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
        // three functions below only decide how many screen pixels one user unit gets.
        var NARROW = 720;
        function narrow() { return wrap.clientWidth < NARROW; }
        function sizeActual() { svg.setAttribute('width', W); svg.setAttribute('height', H); }
        function sizeFitWidth() {
            var w = Math.max(1, wrap.clientWidth);
            svg.setAttribute('width', w);
            svg.setAttribute('height', Math.max(1, Math.round(H * w / W)));
        }
        function sizeGrow() {
            // smaller than the container: grow by CSS size, never by shrinking the viewBox --
            // that is what turns a two-node lab fabric into a postage stamp
            var scale = Math.min(wrap.clientWidth / W, wrap.clientHeight / H);
            svg.setAttribute('width', Math.floor(W * scale));
            svg.setAttribute('height', Math.floor(H * scale));
        }
        function autoSize() {
            // Under 720 px of frame the whole fabric goes in the frame even though the 11 px
            // labels then render smaller. A phone showing the bottom-left corner of an 1,112 px
            // drawing is not a picture of a fabric; `1:1` is how to get those pixels back.
            // At 720 px and above the rule is the old one: never shrink on first paint.
            if (narrow()) {
                sizeFitWidth();
            } else if (W <= wrap.clientWidth && H <= wrap.clientHeight) {
                sizeGrow();
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
        if (!restored) { autoSize(); }

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
        document.getElementById('eagle-reset').addEventListener('click', function () { try { localStorage.removeItem(KEY); } catch (e) {} });
        // the two keys the vis map left behind: it is gone, and nothing reads them again
        try { localStorage.removeItem('netconf-topology-' + wrap.dataset.fabric); localStorage.removeItem('netconf-topology-' + wrap.dataset.fabric + '-view'); } catch (e) {}

        wrap.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            var r = svg.getBoundingClientRect();
            zoom(ev.deltaY > 0 ? 1.1 : 1 / 1.1, (ev.clientX - r.left) / r.width, (ev.clientY - r.top) / r.height);
        }, { passive: false });

        var drag = null;
        wrap.addEventListener('mousedown', function (ev) {
            if (ev.target.closest('.eg-card, .eg-edge')) { return; }
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
