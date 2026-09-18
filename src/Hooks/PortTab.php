<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use App\Models\Port;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;

/**
 * "Plugins" tab of a port: the plugin's per-port counters (values table + graphs).
 * Only offered for ports that have netconf_port_metrics rows.
 */
class PortTab implements PortTabHook
{
    public function authorize(Port $port): bool
    {
        return Gate::allows('view', $port->device)
            && NetconfPortMetric::query()->where('port_id', $port->port_id)->exists();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(string $pluginName, Port $port, array $settings): \Illuminate\Contracts\View\View
    {
        $rows = NetconfPortMetric::query()->where('port_id', $port->port_id)->get()
            ->sortBy(fn (NetconfPortMetric $r) => $r->definition . '|' . $r->mapping);

        return view("$pluginName::port-tab", [
            'port' => $port,
            'rows' => $rows,
            'period' => request()->query('period', '-1d'),
        ]);
    }
}
