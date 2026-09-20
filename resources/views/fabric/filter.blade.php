{{-- filter bar for the VNI / ESI tabs: $q, $issues_only, $placeholder, $total, $shown, $issues --}}
<form method="get" class="form-inline" style="margin-bottom: 10px;">
    <input type="text" name="q" class="form-control input-sm" placeholder="{{ $placeholder }}" value="{{ $q }}" style="width: 260px;">
    <label class="checkbox-inline"><input type="checkbox" name="issues" value="1" {{ $issues_only ? 'checked' : '' }} onchange="this.form.submit()"> with issues only</label>
    <button type="submit" class="btn btn-default btn-sm">Filter</button>
    @if ($q !== '' || $issues_only)<a href="{{ url()->current() }}" class="btn btn-link btn-sm">reset</a>@endif
    <span class="text-muted" style="margin-left: 10px;">{{ $shown }} of {{ $total }} shown @if ($issues > 0)&middot; <span class="text-warning">{{ $issues }} with issues</span>@endif</span>
</form>
