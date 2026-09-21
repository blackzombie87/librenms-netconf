{{-- filter bar for the VNI / ESI tabs: $q, $issues_only, $placeholder, $total, $shown, $issues.
     $issues_default (optional) marks a tab that opens filtered, so the checkbox must submit
     an explicit 0 to show everything. --}}
@php($issuesDefault = $issues_default ?? false)
<form method="get" class="form-inline" style="margin-bottom: 10px;">
    <input type="text" name="q" class="form-control input-sm" placeholder="{{ $placeholder }}" value="{{ $q }}" style="width: 260px;">
    @if ($issuesDefault)<input type="hidden" name="issues" value="0">@endif
    <label class="checkbox-inline"><input type="checkbox" name="issues" value="1" {{ $issues_only ? 'checked' : '' }} onchange="this.form.submit()"> with issues only</label>
    <button type="submit" class="btn btn-default btn-sm">Filter</button>
    @if ($q !== '' || $issues_only !== $issuesDefault)<a href="{{ url()->current() }}" class="btn btn-link btn-sm">reset</a>@endif
    <span class="text-muted" style="margin-left: 10px;">{{ $shown }} of {{ $total }} shown @if ($issues > 0)&middot; <span class="text-warning">{{ $issues }} with issues</span>@endif</span>
    @if ($issuesDefault && $issues_only)<span class="text-muted">&middot; this tab opens on the rows with issues</span>@endif
</form>
