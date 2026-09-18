<?php

namespace SafferIt\LibrenmsNetconf;

use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Access to the plugin's global settings: shipped defaults (config/netconf.php) overlaid
 * with what the admin saved on the plugin settings page (plugins.settings JSON).
 */
class NetconfSettings
{
    public const PLUGIN_NAME = 'netconf';

    /** Settings whose values are secrets (stored encrypted, never echoed). */
    public const SECRET_KEYS = ['password', 'key_passphrase'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        if (function_exists('config')) {
            try {
                $config = config('netconf');
                if (is_array($config) && $config !== []) {
                    return $config;
                }
            } catch (\Throwable) {
                // no Laravel container (unit tests outside LibreNMS)
            }
        }

        return require __DIR__ . '/../config/netconf.php';
    }

    /**
     * Raw values saved in the plugins table (secrets still encrypted).
     *
     * @return array<string, mixed>
     */
    public static function stored(): array
    {
        if (! function_exists('app')) {
            return [];
        }

        try {
            return app(PluginManagerInterface::class)->getSettings(self::PLUGIN_NAME);
        } catch (\Throwable) {
            // container not booted / DB unavailable
        }

        return [];
    }

    /**
     * Defaults overlaid with stored values; empty stored values fall back to the default.
     *
     * @param  array<string, mixed>|null  $stored  inject for tests
     * @return array<string, mixed>
     */
    public static function effective(?array $stored = null): array
    {
        $effective = self::defaults();
        foreach ($stored ?? self::stored() as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($effective[$key] ?? null)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($effective[$key] ?? null) && is_numeric($value)) {
                $value = (int) $value;
            }
            $effective[$key] = $value;
        }

        return $effective;
    }
}
