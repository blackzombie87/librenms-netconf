<?php

namespace SafferIt\LibrenmsNetconf\Hooks;

use Illuminate\Support\Facades\Gate;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use SafferIt\LibrenmsNetconf\NetconfSettings;

/**
 * Plugin settings page (/plugin/settings/netconf). Rendered inside the core settings form,
 * which posts settings[...] back and stores them; SettingsSecrets encrypts the secrets.
 */
class Settings implements SettingsHook
{
    public function authorize(): bool
    {
        $user = auth()->guard()->user();

        return $user !== null && Gate::forUser($user)->allows('admin');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        $effective = NetconfSettings::effective($settings);

        $secretState = [];
        foreach (NetconfSettings::SECRET_KEYS as $key) {
            $secretState[$key] = ($settings[$key] ?? '') !== '';
        }

        return [
            'content_view' => "$pluginName::settings",
            'settings' => $settings,
            'effective' => $effective,
            'defaults' => NetconfSettings::defaults(),
            'secret_state' => $secretState,
            'module_ready' => class_exists(\LibreNMS\Modules\Netconf::class),
        ];
    }
}
