<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Models\Device;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Transport\CredentialResolver;
use SafferIt\LibrenmsNetconf\Transport\Credentials;

/**
 * Per-device NETCONF settings stored as device attributes (the tri-state switches and the
 * credential overrides). Shared by the CLI command and the web form; secrets are sealed.
 */
class DeviceSettings
{
    public const FIELDS = ['username', 'password', 'keyfile', 'key_passphrase', 'port', 'transport'];

    public const SECRETS = ['password', 'key_passphrase'];

    /**
     * Tri-state switches: form field => device attribute. `inherit` (the attribute absent)
     * takes the global setting, `1` and `0` decide for this device alone — `enabled` inherits
     * *enable_by_default*, the rest their entry in `DeviceFacts::MANAGED_ATTRIBS` (plan §13.2).
     *
     * @var array<string, string>
     */
    public const TRISTATE = [
        'enabled' => NetconfService::ATTRIB_ENABLED,
        'evpn_mac' => 'netconf_evpn_mac',
        'queues' => 'netconf_queues',
    ];

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
        return self::tristateAttrib($device, 'enabled');
    }

    /**
     * Raw value of one tri-state attribute: '1', '0' or null for "inherit the global setting".
     */
    public static function tristateAttrib(Device $device, string $field): ?string
    {
        $value = $device->getAttrib(self::TRISTATE[$field] ?? $field);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Every tri-state of a device at once, for the edit form and the CLI table.
     *
     * @return array<string, string|null>
     */
    public static function tristates(Device $device): array
    {
        $out = [];
        foreach (array_keys(self::TRISTATE) as $field) {
            $out[$field] = self::tristateAttrib($device, $field);
        }

        return $out;
    }

    /**
     * The same change on many devices (bulk enable by device group or os, plan G7).
     *
     * @param  iterable<Device>  $devices
     * @param  array<string, mixed>  $input  as for apply()
     * @return array<string, list<string>> hostname => change log, devices without a change included as []
     */
    public static function applyMany(iterable $devices, array $input): array
    {
        $log = [];
        foreach ($devices as $device) {
            $log[$device->hostname] = self::apply($device, $input);
        }

        return $log;
    }

    /**
     * Apply changes. $input keys: the TRISTATE fields ('1' | '0' | 'inherit' | null = unchanged),
     * username, password, keyfile, key_passphrase, port, transport (empty string = unchanged),
     * clear (list of suffixes or 'all').
     *
     * @param  array<string, mixed>  $input
     * @return list<string> human readable change log (secrets never included)
     */
    public static function apply(Device $device, array $input): array
    {
        $changes = [];

        foreach (self::TRISTATE as $field => $attrib) {
            $wanted = $input[$field] ?? null;
            if ($wanted === 'inherit') {
                if ($device->forgetAttrib($attrib)) {
                    $changes[] = "$attrib: inherit global default";
                }
            } elseif ($wanted === '1' || $wanted === '0' || $wanted === 1 || $wanted === 0 || $wanted === true || $wanted === false) {
                $value = filter_var($wanted, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                if (self::tristateAttrib($device, $field) !== $value) {
                    $device->setAttrib($attrib, $value);
                    $changes[] = "$attrib: " . ($value === '1' ? 'enabled' : 'disabled');
                }
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
            // secrets byte for byte: a password with leading or trailing spaces is a value
            // (F5 6); the other fields are identifiers and trimmed, all-blank means unchanged
            $value = $secret ? (string) $value : trim((string) $value);
            if ($value === '') {
                continue;
            }

            if ($suffix === 'transport' && ! in_array($value, Credentials::TRANSPORTS, true)) {
                throw new \InvalidArgumentException('transport must be auto, cli or netconf');
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
