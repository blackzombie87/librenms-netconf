<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

class Menu implements MenuEntryHook
{
    public function authorize(): bool
    {
        return Gate::allows('global-read');
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
