<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * Core's generic /plugin/netconf page. The plugin's own route for that path redirects to the
 * status list before this hook is reached (plan §8 U4); this stays for a core whose route
 * order puts the generic page first, so there is one list page either way instead of two
 * renderings of the same content.
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
        throw new HttpResponseException(redirect()->route('netconf.status'));
    }
}
