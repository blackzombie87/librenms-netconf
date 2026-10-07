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
            display: flex; flex-wrap: wrap; align-items: center; gap: 14px 0; margin: 6px 0 14px;
        }
        html.dark .nt-path {
            --nt-up: #7dca7a; --nt-down: #e87a76; --nt-unknown: #aaaaaa; --nt-wan: #b794e0;
            --nt-gateway: #6aaee0; --nt-spine: #7fd0e8; --nt-leaf: #7dca7a; --nt-host: #aaaaaa; --nt-esi: #f0c36a;
            --nt-card: rgba(255, 255, 255, 0.05); --nt-edge: rgba(255, 255, 255, 0.28); --nt-pill: rgba(255, 255, 255, 0.1);
        }
        .nt-unit { display: flex; align-items: stretch; flex: 1 1 262px; min-width: 262px; }
        .nt-unit.nt-last { flex: 0 0 150px; min-width: 150px; }
        .nt-unit.nt-bare { flex: 1 1 112px; min-width: 112px; }
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

        .nt-split {
            flex: 1 1 280px; min-width: 0; display: flex; flex-direction: column; gap: 10px; padding: 4px 8px;
            border: 2px solid var(--nt-edge); border-top: 0; border-bottom: 0; border-radius: 10px;
        }
        .nt-branch-label { font-size: 10px; color: var(--nt-muted); margin: 0 0 2px 2px; text-transform: none; }
        .nt-row { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 0; }
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

<div class="nt-path">
    @include('netconf::fabric.trace-blocks', ['blocks' => $diagram['blocks']])
</div>
@if ($diagram['routes'] > 1 || $diagram['paths'] > 1)
    <p class="nt-note">
        @if ($diagram['routes'] > 1){{ $diagram['routes'] }} routes: an endpoint on an ESI-LAG is attached to every PE of its segment and unicast may arrive on either. @endif
        @if ($diagram['paths'] > $diagram['routes']){{ $diagram['paths'] }} paths in all: where the underlay has several of the same length the picture splits. @endif
        The graph does not know which one a packet takes; <em>Trace live</em> asks each device's own forwarding table.
    </p>
@endif
