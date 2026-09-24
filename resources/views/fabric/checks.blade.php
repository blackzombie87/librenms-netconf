{{-- Checks tab: $issues (one page), $issue_pager, $issue_total, $issue_shown, $issue_counts, $q, $severity, $check --}}
<form method="get" class="form-inline" style="margin-bottom: 10px;">
    <input type="text" name="q" class="form-control input-sm" placeholder="message, subject, device" value="{{ $q }}" style="width: 260px;">
    <select name="severity" class="form-control input-sm" onchange="this.form.submit()">
        <option value="">any severity</option>
        @foreach (['critical', 'warning', 'info'] as $s)
            <option value="{{ $s }}" {{ $severity === $s ? 'selected' : '' }}>{{ $s }} ({{ $issue_counts['severity'][$s] }})</option>
        @endforeach
    </select>
    <select name="check" class="form-control input-sm" onchange="this.form.submit()">
        <option value="">any check</option>
        @foreach ($issue_counts['checks'] as $id => $n)
            <option value="{{ $id }}" {{ $check === $id ? 'selected' : '' }}>{{ \SafferIt\LibrenmsNetconf\Fabric\Checks\FabricChecks::CHECKS[$id][0] ?? $id }} ({{ $n }})</option>
        @endforeach
    </select>
    <button type="submit" class="btn btn-default btn-sm">Filter</button>
    @if ($q !== '' || $severity !== '' || $check !== '')<a href="{{ url()->current() }}" class="btn btn-link btn-sm">reset</a>@endif
    <span class="text-muted" style="margin-left: 10px;">{{ $issue_shown }} of {{ $issue_total }} shown &middot;
        <span class="text-danger">{{ $issue_counts['severity']['critical'] }} critical</span>,
        <span class="text-warning">{{ $issue_counts['severity']['warning'] }} warning</span>,
        {{ $issue_counts['severity']['info'] }} info</span>
</form>
<p class="text-muted">
    The consistency checks run at the end of every fabric resolve (each poll of a member leaf) over the monitored members' EVPN tables, the overlay sessions and a few core tables.
    An issue keeps its <em>first seen</em> while it persists; the eventlog (type <code>netconf-evpn</code>) records when one appears, clears or changes severity, and every monitored member carries a count sensor <em>EVPN fabric issues</em> with the critical and warning issues that involve it.
    <a data-toggle="collapse" href="#netconf-checks-legend">What is checked</a>
</p>
<div id="netconf-checks-legend" class="collapse">
    <table class="table table-condensed" style="max-width: 1000px;">
        <thead><tr><th>Check</th><th>Default severity</th><th>Meaning</th></tr></thead>
        <tbody>
            @foreach (\SafferIt\LibrenmsNetconf\Fabric\Checks\FabricChecks::CHECKS as $id => [$label, $defaultSeverity, $description])
                <tr><td>{{ $label }} <small class="text-muted">{{ $id }}</small></td><td><span class="label label-{{ ['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'][$defaultSeverity] }}">{{ $defaultSeverity }}</span></td><td><small>{{ $description }}</small></td></tr>
            @endforeach
        </tbody>
    </table>
</div>
@if ($issue_total === 0)
    <p><span class="label label-success">ok</span> No open issues on this fabric.</p>
@elseif ($issue_shown === 0)
    <p>No issue matches the filter.</p>
@else
    <table class="table table-condensed table-hover">
        <thead>
            <tr>
                <th>Severity</th>
                <th>Check</th>
                <th>Issue</th>
                <th>Devices</th>
                <th>First seen</th>
                <th>Last seen</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($issues as $i)
                <tr class="{{ ['critical' => 'danger', 'warning' => 'warning', 'info' => ''][$i['severity']] }}">
                    <td><span class="label label-{{ ['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'][$i['severity']] }}">{{ $i['severity'] }}</span></td>
                    <td><span title="{{ $i['description'] }}">{{ $i['label'] }}</span><br><small class="text-muted">{{ $i['check'] }}</small></td>
                    <td>{{ $i['message'] }}</td>
                    <td>
                        @foreach ($i['devices'] as $device)
                            {!! \LibreNMS\Util\Url::deviceLink($device) !!}@if (! $loop->last), @endif
                        @endforeach
                        @if ($i['devices'] === [])<small class="text-muted">fabric</small>@endif
                    </td>
                    <td><small title="{{ $i['first_seen'] }}">{{ \Carbon\Carbon::parse($i['first_seen'])->diffForHumans() }}</small></td>
                    <td><small title="{{ $i['last_seen'] }}">{{ \Carbon\Carbon::parse($i['last_seen'])->diffForHumans() }}</small></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @include('netconf::fabric.pager', ['pager' => $issue_pager])
@endif
