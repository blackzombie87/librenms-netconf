<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Models\Device;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Transport\CredentialResolver;

/**
 * Per-device NETCONF settings stored as device attributes (netconf_enabled and the
 * credential overrides). Shared by the CLI command and the web form; secrets are sealed.
 */
class DeviceSettings
{
    public const FIELDS = ['username', 'password', 'keyfile', 'key_passphrase', 'port', 'transport'];

    public const SECRETS = ['password', 'key_passphrase'];

    /**
     * Current overrides, secrets redacted.
     *
     * @return array<string, string> suffix => value ("set (encrypted)" for secrets)
     */
    public static function current(Device $device): array
    {
        $attribs = $device->getAttribs();
        $out = [];
        foreach (self::FIELDS as $suffix) {
            $key = CredentialResolver::ATTRIB_PREFIX . $suffix;
            if (isset($attribs[$key]) && $attribs[$key] !== '') {
                $out[$suffix] = in_array($suffix, self::SECRETS, true) ? 'set (encrypted)' : (string) $attribs[$key];
            }
        }

        return $out;
    }

    /** Raw value of the netconf_enabled attribute: '1', '0' or null (inherit global default). */
    public static function enabledAttrib(Device $device): ?string
    {
        $value = $device->getAttrib(NetconfService::ATTRIB_ENABLED);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Apply changes. $input keys: enabled ('1' | '0' | 'inherit' | null = unchanged),
     * username, password, keyfile, key_passphrase, port, transport (empty string = unchanged),
     * clear (list of suffixes or 'all').
     *
     * @param  array<string, mixed>  $input
     * @return list<string> human readable change log (secrets never included)
     */
    public static function apply(Device $device, array $input): array
    {
        $changes = [];

        $enabled = $input['enabled'] ?? null;
        if ($enabled === 'inherit') {
            if ($device->forgetAttrib(NetconfService::ATTRIB_ENABLED)) {
                $changes[] = 'netconf_enabled: inherit global default';
            }
        } elseif ($enabled === '1' || $enabled === '0' || $enabled === 1 || $enabled === 0 || $enabled === true || $enabled === false) {
            $value = filter_var($enabled, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            if (self::enabledAttrib($device) !== $value) {
                $device->setAttrib(NetconfService::ATTRIB_ENABLED, $value);
                $changes[] = 'netconf_enabled: ' . ($value === '1' ? 'enabled' : 'disabled');
            }
        }

        $clear = (array) ($input['clear'] ?? []);
        if (in_array('all', $clear, true)) {
            $clear = self::FIELDS;
        }

        foreach (self::FIELDS as $suffix) {
            $key = CredentialResolver::ATTRIB_PREFIX . $suffix;
            $secret = in_array($suffix, self::SECRETS, true);

            if (in_array($suffix, $clear, true)) {
                if ($device->forgetAttrib($key)) {
                    $changes[] = "$suffix: removed";
                }
                continue;
            }

            $value = $input[$suffix] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $value = trim((string) $value);

            if ($suffix === 'transport' && ! in_array($value, ['cli', 'netconf'], true)) {
                throw new \InvalidArgumentException('transport must be cli or netconf');
            }
            if ($suffix === 'port' && ((int) $value < 1 || (int) $value > 65535)) {
                throw new \InvalidArgumentException('port must be between 1 and 65535');
            }

            $device->setAttrib($key, $secret ? SettingsSecrets::seal($value) : $value);
            $changes[] = $secret ? "$suffix: updated" : "$suffix: $value";
        }

        return $changes;
    }
}
