{{-- Traffic of one ESI-LAG: the sum over the AE of every PE, plus one graph per PE so a quiet
     leg is visible instead of hidden inside a healthy sum. $row is an EsiMatrix row.

     No image here carries a `src`. `Url::graphPopup()` would emit one immediately and four
     more inside its overlib string, and a closed <details> or a hidden inspector panel is
     still in the first response -- 46 segments would be well over a hundred graph requests on
     a page nobody has asked a traffic question on yet. `promote(root)` copies `data-src` to
     `src` inside one panel, and only when that panel is opened. --}}
@php($esi = (string) $row['esi'])
@php($sides = array_map(fn ($s) => ['device_id' => (int) $s['device_id'], 'ifname' => $s['ifname'] === null ? null : (string) $s['ifname'], 'port_id' => $s['port_id'] === null ? null : (int) $s['port_id'], 'esi' => $esi], array_values($row['sides'])))
@php($ports = \SafferIt\LibrenmsNetconf\Fabric\View\EsiTrafficPorts::select($sides))
@php($thumb = fn (string $type, string $id) => ['type' => $type, 'id' => $id, 'from' => '-1d', 'width' => 300, 'height' => 80, 'legend' => 'no'])
@php($periods = fn (string $type, string $id) => array_map(fn ($from) => route('graph', ['type' => $type, 'id' => $id, 'from' => $from, 'width' => 340, 'height' => 100, 'legend' => 'yes']), ['-1d', '-1w', '-1mo', '-1y']))

@if ($ports['excluded'])
    <p class="text-muted"><small>An anycast gateway segment on an IRB: no member ports and no traffic of its own.</small></p>
@elseif ($ports['port_ids'] === [])
    <p class="text-muted"><small>No core port on any side, so there is nothing to graph.</small></p>
@else
    @if (count($ports['port_ids']) > 1)
        @php($id = implode(',', $ports['port_ids']))
        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('multiport_bits', ['id' => $id]) }}">
            <img alt="ESI-LAG {{ $esi }} total" data-src="{{ route('graph', $thumb('multiport_bits', $id)) }}" data-popup='@json($periods('multiport_bits', $id))'>
        </a>
    @endif
    @foreach ($ports['per_pe'] as $deviceId => $portId)
        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('port_bits', ['id' => $portId]) }}">
            <img alt="PE {{ $deviceId }} port {{ $portId }}" data-src="{{ route('graph', $thumb('port_bits', (string) $portId)) }}" data-popup='@json($periods('port_bits', (string) $portId))'>
        </a>
    @endforeach
    <p class="text-muted" style="margin-top: 4px;"><small>
        @if (count($ports['port_ids']) > 1)
            The first graph sums the aggregated interfaces {{ implode(', ', $ports['port_ids']) }} &mdash; that sum is the virtual LAG, and <em>in</em> is traffic from the attached device into the fabric.
            Both legs of a single-active segment stay in the sum; the quiet one is visible because its own graph is flat.
        @else
            One PE has a core port, so this is that port and not a sum.
        @endif
        @if ($ports['skipped'] !== [])
            Omitted, no <code>ports</code> row yet:
            @foreach ($ports['skipped'] as $s){{ $nodes->name(collect($row['sides'])->firstWhere('device_id', $s['device_id'])['vtep_ip'] ?? '') }} {{ $s['ifname'] }}@if (! $loop->last), @endif @endforeach.
        @endif
    </small></p>
@endif

@once
    @push('scripts')
    <script type="text/javascript">
    (function () {
        // set src inside ONE root only; a call on the inspector row would fetch every panel's
        // graphs, which is the case this partial exists to avoid
        window.netconfPromote = function (root) {
            if (!root) { return; }
            Array.prototype.forEach.call(root.querySelectorAll('img[data-src]'), function (img) {
                img.src = img.dataset.src;
                delete img.dataset.src;
                var urls = [];
                try { urls = JSON.parse(img.dataset.popup || '[]'); } catch (e) {}
                if (!urls.length || typeof overlib !== 'function') { return; }
                var html = "<div style=\"display:grid;grid-template-columns:repeat(2,max-content);\">" +
                    urls.map(function (u) { return '<img src="' + u + '" style="border:0;">'; }).join('') + '</div>';
                img.onmouseover = function () { overlib(html, CAPTION, img.alt, FGCOLOR, '#e5e5e5'); };
                img.onmouseout = function () { return nd(); };
            });
        };
        document.addEventListener('DOMContentLoaded', function () {
            Array.prototype.forEach.call(document.querySelectorAll('details.esi-traffic'), function (el) {
                el.addEventListener('toggle', function (ev) { if (ev.target.open) { window.netconfPromote(ev.target); } });
            });
            var focus = new URL(window.location.href).searchParams.get('focus') || '';
            if (focus.indexOf('esi:') !== 0) { return; }   // member: and edge: panels have no graphs
            var panel = document.querySelector('#eagle-inspector [data-focus="' + (window.CSS && CSS.escape ? CSS.escape(focus) : focus) + '"]');
            window.netconfPromote(panel);
        });
    })();
    </script>
    @endpush
@endonce
