<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use SafferIt\LibrenmsNetconf\Support\StatusOverview;

/**
 * /plugin/netconf — the same status content as the plugin's own status route.
 */
class Page implements SinglePageHook
{
    public function authorize(): bool
    {
        return Gate::allows('global-read');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        return ['content_view' => "$pluginName::status-content", 'run' => session('netconf_run')] + StatusOverview::data();
    }
}
