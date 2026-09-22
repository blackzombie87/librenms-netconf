{{-- Fold-outs (<details>) on the plugin pages read as what they are: clickable headings, the
     mapping fold-outs as panels. Included once per page. --}}
@once
<style>
    details.netconf-fold > summary, details.netconf-mapping > summary, details.netconf-graphs > summary { cursor: pointer; }
    details.netconf-mapping > summary.panel-heading, details.netconf-graphs > summary.panel-heading { display: list-item; }
</style>
@endonce
