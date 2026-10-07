{{-- A list of blocks of the trace picture, see TracePathDiagram: a run of cards and links, or
     a split into branches that each hold blocks of their own (so a split can be inside a
     branch). A run is drawn as units, each a card with the link after it; a run that starts
     with a link (the first thing in a branch) begins with a unit that is only that link. --}}
@foreach ($blocks as $block)
    @if ($block['type'] === 'split')
        <div class="nt-split">
            @foreach ($block['branches'] as $branch)
                <div>
                    @if ($branch['label'] !== '')<div class="nt-branch-label">{{ $branch['label'] }}</div>@endif
                    <div class="nt-row">
                        @include('netconf::fabric.trace-blocks', ['blocks' => $branch['blocks']])
                    </div>
                </div>
            @endforeach
        </div>
    @else
        @php($items = $block['items'])
        @php($count = count($items))
        @for ($i = 0; $i < $count; $i++)
            @php($card = $items[$i]['type'] === 'link' ? null : $items[$i])
            @php($link = $card === null ? $items[$i] : (($items[$i + 1] ?? null) !== null && $items[$i + 1]['type'] === 'link' ? $items[$i + 1] : null))
            <div class="nt-unit {{ $link === null ? 'nt-last' : '' }} {{ $card === null ? 'nt-bare' : '' }}">
                @if ($card !== null)
                    @include('netconf::fabric.trace-card', ['card' => $card])
                @endif
                @if ($link !== null)
                    @include('netconf::fabric.trace-link', ['link' => $link])
                @endif
            </div>
            @if ($card !== null && $link !== null)
                @php($i++)
            @endif
        @endfor
    @endif
@endforeach
