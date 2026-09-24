<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use App\Models\Port;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;
use SafferIt\LibrenmsNetconf\Models\NetconfPortMetric;
use SafferIt\LibrenmsNetconf\Support\GraphPeriod;
use SafferIt\LibrenmsNetconf\Support\PluginRoutes;

/**
 * "Plugins" tab of a port: the plugin's per-port counters (values table + graphs).
 * Only offered for ports that have netconf_port_metrics rows.
 */
class PortTab implements PortTabHook
{
    public function authorize(Port $port): bool
    {
        return PluginRoutes::available()
            && Gate::allows('view', $port->device)
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
            'period' => GraphPeriod::fromRequest(),
            'periods' => GraphPeriod::OFFERED,
        ]);
    }
}
