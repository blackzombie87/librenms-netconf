{{-- Outcome of the last action (test, discover, poll, save) and validation errors. --}}
@if ($result)
    <div class="alert alert-{{ $result['type'] }}">
        <strong>{{ $result['title'] }}</strong>
        <ul class="tw:mb-0">
            @foreach ($result['lines'] as $line)<li>{{ $line }}</li>@endforeach
        </ul>
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>
@endif
