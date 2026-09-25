{{-- The eagle SVG and its viewport. Server-rendered geometry ($eagle = EagleLayout::place());
     the script only moves a viewBox, so there is no second layout engine and no new library. --}}
@php($eg = $eagle)
@php($collapsed = $view['collapse'])
{{-- toolbar links keep every other parameter and are built with the query builder, so a
     location that itself contains a comma stays one value (`collapse[]` is never split) --}}
@php($toggleSite = fn (string $key) => request()->fullUrlWithQuery(['topo' => 'eagle', 'focus' => null, 'collapse' => array_values(in_array($key, $collapsed, true) ? array_diff($collapsed, [$key]) : [...$collapsed, $key])]))
<div class="netconf-eagle">
    <div class="nt-bar">
        <span class="btn-group btn-group-xs" role="group">
            <a class="btn btn-default active" href="{{ request()->fullUrlWithQuery(['topo' => null]) }}">eagle view</a>
            <a class="btn btn-default" href="{{ request()->fullUrlWithQuery(['topo' => 'interactive', 'collapse' => null, 'outside' => null, 'attached' => null, 'focus' => null]) }}">interactive map</a>
        </span>
        <span class="text-muted" style="margin-left: 10px;">
            {{ $eg['counts']['sites'] }} site{{ $eg['counts']['sites'] === 1 ? '' : 's' }}@if ($eg['counts']['collapsed'] > 0), {{ $eg['counts']['collapsed'] }} collapsed @endif
        </span>
        <span class="btn-group btn-group-xs" style="margin-left: 10px;" role="group">
            <a class="btn btn-default" href="{{ request()->fullUrlWithQuery(['topo' => 'eagle', 'focus' => null, 'collapse' => count($collapsed) === count($eg['groups']) ? [] : array_column($eg['groups'], 'key')]) }}">{{ count($collapsed) === count($eg['groups']) && $eg['groups'] !== [] ? 'expand all sites' : 'collapse all sites' }}</a>
            @if ($eg['counts']['outside'] > 0)
                <a class="btn btn-default {{ $view['outside'] ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['topo' => 'eagle', 'focus' => null, 'outside' => $view['outside'] ? null : 1]) }}">sessions out of the fabric ({{ $eg['counts']['outside'] }})</a>
            @endif
            <a class="btn btn-default {{ $view['attached'] !== null ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['topo' => 'eagle', 'focus' => null, 'attached' => $view['attached'] !== null ? null : 1]) }}">attached devices {{ $view['attached'] === null ? '' : '(' . count($view['attached']) . ')' }}</a>
        </span>
        <span class="pull-right btn-group btn-group-xs" role="group">
            <button type="button" class="btn btn-default" id="eagle-out" title="zoom out">&minus;</button>
            <button type="button" class="btn btn-default" id="eagle-in" title="zoom in">+</button>
            <button type="button" class="btn btn-default" id="eagle-fit" title="fit the whole picture">fit</button>
            <a class="btn btn-default" href="{{ route('netconf.fabric', [$fabric['id'], 'overview']) }}?topo=eagle" id="eagle-reset" title="forget the stored camera and every view flag">reset</a>
        </span>
    </div>

    <p class="text-muted" style="margin: 4px 0;"><small>
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#5cb85c" stroke-width="3"/></svg> underlay up (solid)
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#d9534f" stroke-width="3" stroke-dasharray="5 3"/></svg> down (dashed)
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#999" stroke-width="2" stroke-dasharray="1 4"/></svg> no session state (dotted)
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#3d5a80" stroke-width="3"/></svg> cross-site
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#8e6bbf" stroke-width="2" stroke-dasharray="7 3"/></svg> via WAN
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#337ab7" stroke-width="1" stroke-dasharray="3 3"/></svg> overlay
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#d9534f" stroke-width="1.5" stroke-dasharray="6 2 2 2"/></svg> overlay fault
        <svg width="26" height="8"><line x1="0" y1="4" x2="26" y2="4" stroke="#f0ad4e" stroke-width="2"/></svg> ESI-LAG
    </small></p>

    <style>
        #eagle-view { overflow: auto; max-height: 72vh; min-height: 420px; border: 1px solid #ddd; border-radius: 4px; background: #fff; }
        #eagle-view svg { display: block; max-width: none; height: auto; font-family: inherit; }
        #eagle-view svg text { font-family: inherit; }
        .eg-card { cursor: pointer; }
        .eg-card:hover rect { stroke-width: 2.5; }
        .eg-edge { cursor: pointer; }
        .eg-hl { stroke: #337ab7 !important; stroke-width: 4 !important; }
        .eg-sel rect { stroke: #337ab7 !important; stroke-width: 3 !important; }
        .netconf-eagle .nt-bar { margin-bottom: 6px; }
    </style>

    <div id="eagle-view" data-fabric="{{ $fabric['id'] }}" data-w="{{ $eg['width'] }}" data-h="{{ $eg['height'] }}">
        <svg id="eagle-svg" width="{{ $eg['width'] }}" height="{{ $eg['height'] }}" viewBox="0 0 {{ $eg['width'] }} {{ $eg['height'] }}" xmlns="http://www.w3.org/2000/svg">
            @foreach ($eg['groups'] as $g)
                <a href="{{ $toggleSite($g['key']) }}" class="eg-site">
                    <rect x="{{ $g['x'] }}" y="{{ $g['y'] }}" width="{{ $g['w'] }}" height="{{ $g['h'] }}" rx="6" fill="rgba(0,0,0,0.03)" stroke="{{ $g['state'] === 'down' ? '#d9534f' : ($g['state'] === 'warning' ? '#f0ad4e' : '#ccc') }}" stroke-dasharray="4 3">
                        <title>{{ $g['label'] ?? 'no location' }} — {{ count($g['members']) }} member{{ count($g['members']) === 1 ? '' : 's' }}, click to {{ $g['collapsed'] ? 'expand' : 'collapse' }}</title>
                    </rect>
                    {{-- the caption is cut to the box: a location is free text and the ones that
                         exist are long enough to run across the next compound --}}
                    <text x="{{ $g['x'] + 6 }}" y="{{ $g['y'] + 14 }}" font-size="11" fill="#777">{{ $g['collapsed'] ? '▸' : '▾' }} {{ $g['caption'] }}<title>{{ $g['label'] ?? 'no location' }}</title></text>
                </a>
            @endforeach

            @foreach ($eg['edges'] as $e)
                <g class="eg-edge" data-focus="{{ $e['id'] }}">
                    <path d="{{ $e['path'] }}" fill="none" stroke="{{ $e['stroke'] }}" stroke-width="{{ $e['width'] }}" @if ($e['dash'] !== '') stroke-dasharray="{{ $e['dash'] }}" @endif class="{{ $e['highlight'] ? 'eg-hl' : '' }}">
                        <title>{{ $e['title'] }}</title>
                    </path>
                    @if (($e['label'] ?? '') !== '')
                        <text x="{{ $e['lx'] }}" y="{{ $e['ly'] }}" font-size="9" fill="#666" text-anchor="middle">{{ $e['label'] }}</text>
                    @endif
                </g>
            @endforeach

            @foreach ($eg['nodes'] as $id => $n)
                <g class="eg-card" data-focus="{{ $n['kind'] === 'member' ? 'member:' . $n['ip'] : $id }}">
                    <rect x="{{ $n['x'] }}" y="{{ $n['y'] }}" width="{{ $n['w'] }}" height="{{ $n['h'] }}" rx="5" fill="{{ $n['fill'] }}" stroke="{{ $n['stroke'] }}" stroke-width="{{ $n['highlight'] ? 3 : 1.5 }}" @if ($n['dashed']) stroke-dasharray="4 3" @endif>
                        <title>{{ $n['title'] }}</title>
                    </rect>
                    @if ($n['kind'] === 'attached')
                        <text x="{{ $n['x'] + 2 }}" y="{{ $n['y'] + 12 }}" font-size="9" fill="#555">{{ \Illuminate\Support\Str::limit($n['name'], 10, '…') }}</text>
                    @elseif ($n['kind'] === 'outside')
                        <circle cx="{{ $n['x'] + 3 }}" cy="{{ $n['y'] + 3 }}" r="3" fill="#8e6bbf"/>
                    @else
                        <text x="{{ $n['x'] + $n['w'] / 2 }}" y="{{ $n['y'] + 17 }}" font-size="11" font-weight="bold" text-anchor="middle" fill="#222">{{ \Illuminate\Support\Str::limit($n['name'], 22, '…') }}</text>
                        <text x="{{ $n['x'] + $n['w'] / 2 }}" y="{{ $n['y'] + 31 }}" font-size="10" text-anchor="middle" fill="#666">{{ $n['ip'] }}@if ($n['border']) · border @endif</text>
                        @if (($n['summary'] ?? null) !== null)
                            <text x="{{ $n['x'] + $n['w'] / 2 }}" y="{{ $n['y'] + 46 }}" font-size="9" text-anchor="middle" fill="#777">{{ implode(' · ', $n['summary']) }}</text>
                        @else
                            @foreach ($n['chips'] as $i => $chip)
                                @php($cx = $n['x'] + 6 + $i * 76)
                                <rect x="{{ $cx }}" y="{{ $n['y'] + 38 }}" width="72" height="14" rx="3" fill="{{ $chip['class'] === 'danger' ? '#f7dcdb' : ($chip['class'] === 'warning' ? '#fcefdc' : ($chip['class'] === 'info' ? '#deeefb' : '#eeeeee')) }}"/>
                                <text x="{{ $cx + 36 }}" y="{{ $n['y'] + 48 }}" font-size="9" text-anchor="middle" fill="#444">{{ \Illuminate\Support\Str::limit($chip['text'], 13, '…') }}</text>
                            @endforeach
                        @endif
                        @if ($n['status'] === false)<circle cx="{{ $n['x'] + $n['w'] - 8 }}" cy="{{ $n['y'] + 8 }}" r="4" fill="#d9534f"/>@endif
                    @endif
                </g>
            @endforeach
        </svg>
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

        var stored = null;
        try { stored = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
        if (stored && typeof stored.viewBox === 'string') {
            var p = stored.viewBox.split(' ').map(Number);
            if (p.length === 4 && p.every(function (n) { return isFinite(n); }) && p[2] > 0 && p[3] > 0) {
                box = { x: p[0], y: p[1], w: p[2], h: p[3] };
                apply();
            }
        } else if (W <= wrap.clientWidth && H <= wrap.clientHeight) {
            // smaller than the container: grow by CSS size, never by shrinking the viewBox --
            // that is what turns a two-node lab fabric into a postage stamp
            var scale = Math.min(wrap.clientWidth / W, wrap.clientHeight / H);
            svg.setAttribute('width', Math.floor(W * scale));
            svg.setAttribute('height', Math.floor(H * scale));
        }

        function zoom(factor, cx, cy) {
            var nw = box.w * factor, nh = box.h * factor;
            box.x += (box.w - nw) * cx;
            box.y += (box.h - nh) * cy;
            box.w = nw; box.h = nh;
            apply(); store();
        }
        document.getElementById('eagle-in').addEventListener('click', function () { zoom(1 / 1.2, 0.5, 0.5); });
        document.getElementById('eagle-out').addEventListener('click', function () { zoom(1.2, 0.5, 0.5); });
        document.getElementById('eagle-fit').addEventListener('click', function () { box = { x: 0, y: 0, w: W, h: H }; apply(); store(); });
        document.getElementById('eagle-reset').addEventListener('click', function () { try { localStorage.removeItem(KEY); } catch (e) {} });

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

        var initial = new URL(window.location.href).searchParams.get('focus');
        if (initial) { select(initial); }
    })();
    </script>
    @endpush
@endonce
