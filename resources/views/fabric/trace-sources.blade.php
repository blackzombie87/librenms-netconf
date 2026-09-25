{{-- Where one endpoint was looked for, and what each source said. The empty ones matter: on a
     fabric that never collected the MAC database, "not found" is a setting and not a fault. --}}
<details style="margin-top: 6px;">
    <summary class="text-muted"><small>Sources consulted for <code>{{ $label }}</code></small></summary>
    <table class="table table-condensed" style="margin-top: 6px;">
        @foreach ($sources['consulted'] as $source)
            <tr>
                <td style="width: 40%;">{{ $source['source'] }}</td>
                <td style="width: 80px;">{{ $source['rows'] }} row{{ $source['rows'] === 1 ? '' : 's' }}</td>
                <td><small class="text-muted">{{ $source['note'] }}</small></td>
            </tr>
        @endforeach
        @foreach ($sources['candidates'] as $candidate)
            <tr>
                <td><span class="label label-{{ $candidate->isAttachment() ? 'success' : 'default' }}">{{ $candidate->source }}</span></td>
                <td>{{ $candidate->name ?? '—' }}</td>
                <td><small>{{ implode(' · ', $candidate->evidence) }}</small></td>
            </tr>
        @endforeach
    </table>
    @foreach ($sources['notes'] as $note)<p class="text-muted"><small>{{ $note }}</small></p>@endforeach
</details>
