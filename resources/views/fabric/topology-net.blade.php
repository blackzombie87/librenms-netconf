{{-- Interactive topology (vis-network, shipped by LibreNMS): $graph = Topology::graph() --}}
<div id="nt-net-wrap" hidden>
    <div class="nt-bar">
        <label class="checkbox-inline"><input type="checkbox" data-nt-layer="underlay" checked> underlay</label>
        <label class="checkbox-inline"><input type="checkbox" data-nt-layer="overlay" {{ $graph['overlay_default'] ? 'checked' : '' }}> overlay neighbours <small class="text-muted">({{ $graph['overlay_pairs'] }})</small></label>
        <label class="checkbox-inline"><input type="checkbox" data-nt-layer="esi" checked> ESI pairs</label>
        @if ($graph['outside'] > 0)
            <label class="checkbox-inline"><input type="checkbox" data-nt-layer="outside"> sessions out of the fabric <small class="text-muted">({{ $graph['outside'] }})</small></label>
        @endif
        <label class="checkbox-inline"><input type="checkbox" id="nt-labels"> link labels</label>
        <label class="checkbox-inline"><input type="checkbox" id="nt-physics" checked> gravity</label>
        <span class="pull-right">
            <button type="button" class="btn btn-default btn-xs" id="nt-cluster">collapse sites</button>
            <button type="button" class="btn btn-default btn-xs" id="nt-reset">reset layout</button>
            <button type="button" class="btn btn-default btn-xs" id="nt-static-on">static picture</button>
        </span>
    </div>
    @if ($graph['mesh']['complete'])
        <p class="text-muted" style="margin: 4px 0;"><small>
            The EVPN overlay is a <strong>full mesh</strong> of all {{ $graph['mesh']['members'] }} members ({{ $graph['mesh']['pairs'] }} pairs, every one listed from both sides),
            so its arcs are off — switch them on to see them, they carry no information here beyond this sentence.
        </small></p>
    @elseif ($graph['mesh']['asymmetric'] > 0)
        <p class="text-muted" style="margin: 4px 0;"><small>
            {{ $graph['mesh']['pairs'] }} EVPN neighbour pairs, <span class="text-danger">{{ $graph['mesh']['asymmetric'] }} listed by one side only</span> (red).
        </small></p>
    @endif
    <div id="nt-net"></div>
    <p class="text-muted" style="margin-top: 6px;"><small>
        Drag a member to pull its neighbours with it; scroll to zoom, drag the background to pan, click a member to open its device page.
        <em>Collapse sites</em> folds each location into one node (click it to open it again) — the way to keep a large fabric readable.
        The arrangement is remembered in this browser per fabric; <em>reset layout</em> forgets it.
    </small></p>
</div>
@once
    @push('scripts')
        <script type="text/javascript" src="{{ asset('js/vis-network.min.js') }}"></script>
        <script type="text/javascript">
        (function () {
            var data = @json($graph);
            var wrap = document.getElementById('nt-net-wrap');
            var container = document.getElementById('nt-net');
            var staticWrap = document.getElementById('nt-static-wrap');
            if (!window.vis || !container) { return; }   // core without vis-network: the SVG stays

            var STORE = 'netconf-topology-' + {{ (int) $fabric['id'] }};
            var COLOURS = {
                underlay_up: '#5cb85c', underlay_down: '#d9534f', underlay_unknown: '#999',
                wan: '#8e6bbf', lldp: '#999', overlay: '#337ab7', overlay_odd: '#d9534f', esi: '#f0ad4e'
            };
            var ROLE_FILL = { gateway: '#dbe9f6', spine: '#e3f1fa', leaf: '#e6f4e6', stub: '#f7f7f7', outside: '#efe7f7' };

            var saved = {};
            try { saved = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { saved = {}; }

            var nodes = new vis.DataSet(data.nodes.map(function (n) {
                var pos = saved[n.id];
                return {
                    id: n.id,
                    label: (n.role === 'stub' || n.role === 'outside') ? n.name : n.name + '\n' + n.ip,
                    title: n.role === 'stub' ? n.ip + ' — several members peer with this address, and it is not a monitored fabric member'
                        : n.role === 'outside' ? n.ip + ' — a session out of the fabric'
                        : n.name + ' (' + n.ip + ') — ' + n.role + (n.border ? ', border' : '')
                            + (n.monitored ? (n.collected ? '' : ' — no EVPN data') : ' — not monitored')
                            + (n.down ? ' — device down' : ''),
                    site: n.site || '_stub',   // NOT vis's `group`: that hands the node vis's own
                                               // palette when a cluster is opened again, and the
                                               // colour here means the role, not the location
                    // a far end several members peer with is a fabric node nobody monitors, and
                    // is drawn like one (dashed box); a far end only one member has is an
                    // outside session and stays a dot
                    stub: n.role === 'stub' || n.role === 'outside',
                    shape: n.role === 'outside' ? 'dot' : 'box',
                    size: n.role === 'outside' ? 6 : undefined,
                    font: { size: n.role === 'outside' ? 10 : 12, multi: false, color: n.role === 'stub' ? '#666' : '#222' },
                    hidden: n.role === 'outside',   // a transit or IX peer of a border router is
                                                    // not fabric underlay (plan §10.12)
                    color: {
                        background: ROLE_FILL[n.role] || '#f2f2f2',
                        border: n.down ? '#d9534f' : (n.monitored ? (n.collected ? '#5a5a5a' : '#f0ad4e') : '#999'),
                        highlight: { background: '#fff8dc', border: '#337ab7' }
                    },
                    borderWidth: n.down ? 3 : (n.role === 'stub' ? 1 : 1.5),
                    shapeProperties: { borderDashes: n.monitored ? false : [4, 3] },
                    url: n.url,
                    x: pos ? pos.x : n.x, y: pos ? pos.y : n.y
                };
            }));

            var edges = new vis.DataSet(data.edges.map(function (e, i) {
                var colour = e.kind === 'overlay' ? (e.up ? COLOURS.overlay : COLOURS.overlay_odd)
                    : (e.kind === 'wan' || e.kind === 'outside') ? COLOURS.wan
                    : e.kind === 'lldp' ? COLOURS.lldp
                    : e.kind === 'esi' ? COLOURS.esi
                    : (e.up === false ? COLOURS.underlay_down : (e.up === true ? COLOURS.underlay_up : COLOURS.underlay_unknown));
                return {
                    id: i, from: e.from, to: e.to, kind: e.kind, title: e.title, rawLabel: e.label,
                    color: { color: colour, highlight: colour, opacity: e.kind === 'overlay' ? 0.5 : 0.9 },
                    width: e.kind === 'overlay' ? 1 : (e.kind === 'lldp' ? 1.5 : 2.5),
                    dashes: e.kind === 'overlay' ? [3, 3] : ((e.kind === 'wan' || e.kind === 'outside') ? [6, 3] : (e.kind === 'lldp' ? [2, 3] : false)),
                    smooth: { enabled: e.kind === 'overlay', type: 'curvedCW', roundness: 0.15 },
                    font: { size: 9, color: '#666', strokeWidth: 3, strokeColor: '#fff', align: 'middle' },
                    hidden: e.kind === 'overlay' ? !{{ $graph['overlay_default'] ? 'true' : 'false' }} : e.kind === 'outside'
                };
            }));

            var network = new vis.Network(container, { nodes: nodes, edges: edges }, {
                interaction: { hover: true, tooltipDelay: 120, navigationButtons: false, multiselect: true },
                physics: {
                    solver: 'forceAtlas2Based',
                    forceAtlas2Based: { gravitationalConstant: -120, centralGravity: 0.006, springLength: 170, springConstant: 0.05, damping: 0.5, avoidOverlap: 1 },
                    stabilization: { iterations: 160, fit: true },
                    minVelocity: 0.6
                },
                nodes: { widthConstraint: { maximum: 150 }, margin: 6 },
                edges: { selectionWidth: 2 },
                groups: {}
            });

            network.on('click', function (params) {
                if (params.nodes.length !== 1) { return; }
                var node = nodes.get(params.nodes[0]);
                if (node && node.url && !network.isCluster(params.nodes[0])) { window.location = node.url; }
            });
            network.on('dragEnd', savePositions);
            network.on('stabilizationIterationsDone', savePositions);

            function savePositions() {
                try { localStorage.setItem(STORE, JSON.stringify(network.getPositions())); } catch (e) { /* private mode */ }
            }

            document.querySelectorAll('[data-nt-layer]').forEach(function (box) {
                box.addEventListener('change', function () {
                    var kinds = box.dataset.ntLayer === 'underlay' ? ['underlay', 'wan', 'lldp'] : [box.dataset.ntLayer];
                    var shown = edges.get().filter(function (e) { return kinds.indexOf(e.kind) !== -1; });
                    edges.update(shown.map(function (e) { return { id: e.id, hidden: !box.checked }; }));
                    // a stub is a line plus the dot at its end: hide or show them together
                    var ends = {};
                    shown.forEach(function (e) {
                        [e.from, e.to].forEach(function (id) {
                            var node = nodes.get(id);
                            if (node && node.stub) { ends[id] = true; }
                        });
                    });
                    nodes.update(Object.keys(ends).map(function (id) { return { id: id, hidden: !box.checked }; }));
                });
            });
            document.getElementById('nt-labels').addEventListener('change', function () {
                var on = this.checked;
                edges.update(edges.get().map(function (e) { return { id: e.id, label: on ? e.rawLabel : undefined }; }));
            });
            document.getElementById('nt-physics').addEventListener('change', function () {
                network.setOptions({ physics: { enabled: this.checked } });
            });
            document.getElementById('nt-reset').addEventListener('click', function () {
                try { localStorage.removeItem(STORE); } catch (e) { /* ignore */ }
                network.setOptions({ physics: { enabled: true } });
                document.getElementById('nt-physics').checked = true;
                nodes.update(data.nodes.map(function (n) { return { id: n.id, x: n.x, y: n.y }; }));
                network.stabilize(160);
                network.fit();
            });

            var clusters = [];
            var clusterButton = document.getElementById('nt-cluster');
            clusterButton.addEventListener('click', function () {
                if (clusters.length) {
                    // only the ids we made: vis logs "Node does not exist" for anything else
                    clusters.forEach(function (id) { if (network.isCluster(id)) { network.openCluster(id); } });
                    clusters = [];
                    clusterButton.textContent = 'collapse sites';
                    network.fit({ animation: true });

                    return;
                }
                data.sites.forEach(function (site) {
                    if (!site.label || !countIn(site.key)) { return; }
                    clusters.push('site:' + site.key);
                    network.cluster({
                        joinCondition: function (child) { return child.site === site.key; },
                        clusterNodeProperties: {
                            id: 'site:' + site.key, label: site.label + '\n(' + countIn(site.key) + ' members)',
                            shape: 'box', color: { background: '#fdf3d7', border: '#a56c00' }, borderWidth: 2,
                            font: { size: 13 }, allowSingleNodeCluster: true
                        }
                    });
                });
                clusterButton.textContent = 'open sites';
                network.fit({ animation: true });
            });
            function countIn(key) {
                return data.nodes.filter(function (n) { return n.site === key; }).length;
            }

            document.getElementById('nt-static-on').addEventListener('click', function () {
                wrap.hidden = true;
                if (staticWrap) { staticWrap.hidden = false; }
                try { localStorage.setItem(STORE + '-view', 'static'); } catch (e) { /* ignore */ }
            });
            var staticButton = document.getElementById('nt-interactive-on');
            if (staticButton) {
                staticButton.addEventListener('click', function () {
                    if (staticWrap) { staticWrap.hidden = true; }
                    wrap.hidden = false;
                    network.redraw();
                    network.fit();
                    try { localStorage.setItem(STORE + '-view', 'interactive'); } catch (e) { /* ignore */ }
                });
            }

            var prefer = 'interactive';
            try { prefer = localStorage.getItem(STORE + '-view') || 'interactive'; } catch (e) { /* ignore */ }
            if (prefer === 'static') { return; }   // the SVG is already visible
            if (staticWrap) { staticWrap.hidden = true; }
            wrap.hidden = false;
        })();
        </script>
    @endpush
@endonce
