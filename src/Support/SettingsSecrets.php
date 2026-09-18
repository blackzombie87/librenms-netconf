<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Models\Plugin;
use Illuminate\Support\Facades\Crypt;
use SafferIt\LibrenmsNetconf\NetconfSettings;
use SafferIt\LibrenmsNetconf\Transport\CredentialResolver;

/**
 * The core plugin settings form stores the posted array verbatim. This model listener
 * rewrites the netconf secrets before the row is saved:
 *  - an empty field keeps the previously stored value (the form never echoes secrets),
 *  - "<key>_clear" = 1 removes the secret,
 *  - a new plaintext value is encrypted with the LibreNMS APP_KEY and prefixed "crypt:".
 */
class SettingsSecrets
{
    public static function register(): void
    {
        Plugin::saving(function (Plugin $plugin): void {
            if ($plugin->plugin_name !== NetconfSettings::PLUGIN_NAME) {
                return;
            }

            $plugin->settings = self::protect(
                (array) $plugin->settings,
                (array) ($plugin->getOriginal('settings') ?? []),
                self::encrypt(...)
            );
        });
    }

    /**
     * @param  array<string, mixed>  $settings  freshly posted settings
     * @param  array<string, mixed>  $original  settings as stored before this save
     * @param  callable(string): string  $encrypt  plaintext → ciphertext (without prefix)
     * @return array<string, mixed>
     */
    public static function protect(array $settings, array $original, callable $encrypt): array
    {
        foreach (NetconfSettings::SECRET_KEYS as $key) {
            $clearFlag = $key . '_clear';
            $posted = $settings[$key] ?? '';

            if (! empty($settings[$clearFlag])) {
                $settings[$key] = '';
            } elseif ($posted === '') {
                $settings[$key] = $original[$key] ?? '';
            } elseif (! CredentialResolver::isEncrypted($posted)) {
                $settings[$key] = CredentialResolver::SECRET_PREFIX . $encrypt((string) $posted);
            }

            unset($settings[$clearFlag]);
        }

        return $settings;
    }

    public static function encrypt(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    public static function decrypt(string $cipher): string
    {
        return Crypt::decryptString($cipher);
    }

    /** Stored representation of a secret for device attribs and settings. */
    public static function seal(string $plain): string
    {
        return CredentialResolver::SECRET_PREFIX . self::encrypt($plain);
    }
}
