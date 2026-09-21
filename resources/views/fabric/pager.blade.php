{{-- page links of a paged fabric table: $pager (Pager::slice()), other query parameters are kept --}}
@if ($pager['pages'] > 1)
    @php($link = fn (int $page) => url()->current() . '?' . http_build_query(array_merge(request()->query(), ['page' => $page])))
    <nav>
        <ul class="pagination pagination-sm" style="margin: 5px 0;">
            <li class="{{ $pager['page'] <= 1 ? 'disabled' : '' }}">
                <a href="{{ $pager['page'] <= 1 ? '#' : $link($pager['page'] - 1) }}" rel="prev">&laquo;</a>
            </li>
            @foreach (range(1, $pager['pages']) as $page)
                @if ($page === 1 || $page === $pager['pages'] || abs($page - $pager['page']) <= 2)
                    <li class="{{ $page === $pager['page'] ? 'active' : '' }}"><a href="{{ $link($page) }}">{{ $page }}</a></li>
                @elseif (abs($page - $pager['page']) === 3)
                    <li class="disabled"><a href="#">&hellip;</a></li>
                @endif
            @endforeach
            <li class="{{ $pager['page'] >= $pager['pages'] ? 'disabled' : '' }}">
                <a href="{{ $pager['page'] >= $pager['pages'] ? '#' : $link($pager['page'] + 1) }}" rel="next">&raquo;</a>
            </li>
        </ul>
        <span class="text-muted">rows {{ $pager['from'] }}&ndash;{{ $pager['to'] }} of {{ $pager['total'] }}</span>
    </nav>
@endif
