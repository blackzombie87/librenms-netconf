<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use SafferIt\LibrenmsNetconf\Support\PluginRoutes;

class Menu implements MenuEntryHook
{
    public function authorize(): bool
    {
        // no routes, no menu entry: the entry is rendered from core's menu partial on every
        // page, so an unresolvable route() here is a site outage (plan §9.1 I1)
        return PluginRoutes::available() && Gate::allows('global-read');
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
