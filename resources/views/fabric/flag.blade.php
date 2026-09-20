{{-- one issue flag: $flag --}}
@php($map = [
    'vlan-mismatch' => ['warning', 'the VLAN tag differs between leaves (legal, informational)'],
    'flood-gap' => ['danger', 'a monitored carrier is missing from another carrier\'s flood list'],
    'stale-flood' => ['warning', 'the flood list points at a monitored member that does not carry the VNI'],
    'orphan' => ['warning', 'VNI with an empty flood list on a leaf'],
    'irb-down' => ['danger', 'anycast IRB not up'],
    'single-pe' => ['danger', 'only one PE known for this segment: peer down or misconfigured'],
    'df-disagree' => ['danger', 'the PEs name different designated forwarders'],
    'df-both' => ['danger', 'more than one monitored side claims to be the DF'],
    'mode-differs' => ['danger', 'all-active on one side, single-active on the other'],
    'lag-down' => ['danger', 'the ESI-LAG is not up on a side'],
    'unresolved' => ['danger', 'the ESI is not resolved on a side'],
    'lacp-degraded' => ['warning', 'LACP members not distributing (lacp.yaml)'],
    'no-aliasing' => ['warning', 'aliasing disabled on a side'],
    'asymmetric' => ['warning', 'no tunnel back from the far end'],
])
@php([$class, $title] = $map[$flag] ?? ['default', $flag])
<span class="label label-{{ $class }}" title="{{ $title }}">{{ $flag }}</span>
