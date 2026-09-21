<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use Closure;
use InvalidArgumentException;

/**
 * Merges global plugin settings with per-device attributes into Credentials.
 *
 * Pure PHP: takes arrays, so it can be unit-tested without Eloquent. The LibreNMS
 * glue (Device model → attribs array, PluginManager → settings array) lives in
 * DeviceCredentials.
 *
 * Secret values may be stored encrypted; such values carry the prefix "crypt:" and are
 * passed through the $decrypt callable (Laravel Crypt::decryptString inside LibreNMS).
 */
class CredentialResolver
{
    public const SECRET_PREFIX = 'crypt:';

    public const ATTRIB_PREFIX = 'netconf_';

    /** Settings keys (global) and the matching device attribute suffix. */
    public const KEYS = [
        'username' => 'username',
        'password' => 'password',
        'key_file' => 'keyfile',
        'key_passphrase' => 'key_passphrase',
        'port' => 'port',
        'transport' => 'transport',
    ];

    public const SECRET_KEYS = ['password', 'key_passphrase'];

    /** @var Closure(string): string */
    private Closure $decrypt;

    /**
     * @param  (callable(string): string)|null  $decrypt  receives the ciphertext without prefix
     */
    public function __construct(?callable $decrypt = null)
    {
        $this->decrypt = $decrypt ? Closure::fromCallable($decrypt) : static fn (string $v): string => $v;
    }

    /**
     * @param  array<string, mixed>  $settings  global plugin settings (already merged with defaults)
     * @param  array<string, mixed>  $attribs  device attributes (attrib_type => attrib_value)
     * @param  array<string, mixed>  $overrides  ad-hoc values, e.g. CLI options (highest priority)
     */
    public function resolve(string $host, array $settings, array $attribs = [], array $overrides = []): Credentials
    {
        $values = [];
        foreach (self::KEYS as $settingKey => $attribSuffix) {
            $value = $overrides[$settingKey] ?? null;
            if ($this->isEmpty($value)) {
                $value = $attribs[self::ATTRIB_PREFIX . $attribSuffix] ?? null;
            }
            if ($this->isEmpty($value)) {
                $value = $settings[$settingKey] ?? null;
            }
            $values[$settingKey] = $this->isEmpty($value) ? null : $value;
        }

        foreach (self::SECRET_KEYS as $key) {
            $values[$key] = $this->reveal($values[$key]);
        }

        $transport = strtolower((string) ($values['transport'] ?? Credentials::TRANSPORT_AUTO));
        if (! in_array($transport, Credentials::TRANSPORTS, true)) {
            throw new InvalidArgumentException("Unknown transport '$transport' (expected auto, cli or netconf)");
        }

        $port = (int) ($values['port'] ?? 0);
        if ($port <= 0 || $port > 65535) {
            $port = $transport === Credentials::TRANSPORT_NETCONF ? 830 : 22;
        }

        return new Credentials(
            host: $host,
            port: $port,
            transport: $transport,
            username: (string) ($values['username'] ?? ''),
            password: $values['password'],
            keyFile: $values['key_file'],
            keyPassphrase: $values['key_passphrase'],
            authOrder: self::parseAuthOrder($overrides['auth_order'] ?? $settings['auth_order'] ?? null),
            connectTimeout: max(1, (int) ($overrides['connect_timeout'] ?? $settings['connect_timeout'] ?? 10)),
            commandTimeout: max(1, (int) ($overrides['command_timeout'] ?? $settings['command_timeout'] ?? 30)),
            knownHosts: self::knownHosts($overrides['known_hosts'] ?? null, $settings['known_hosts'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    public static function parseAuthOrder(mixed $value): array
    {
        $default = [Credentials::AUTH_KEY, Credentials::AUTH_PASSWORD];
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $items = explode(',', $value);
        } else {
            return $default;
        }

        $order = [];
        foreach ($items as $item) {
            $item = strtolower(trim((string) $item));
            if (in_array($item, $default, true) && ! in_array($item, $order, true)) {
                $order[] = $item;
            }
        }

        return $order ?: $default;
    }

    /** known_hosts path: override wins, "-" as override disables a configured file. */
    private static function knownHosts(mixed $override, mixed $setting): ?string
    {
        $value = trim((string) ($override ?? ''));
        if ($value === '-') {
            return null;
        }
        if ($value === '') {
            $value = trim((string) ($setting ?? ''));
        }

        return $value === '' ? null : $value;
    }

    public static function isEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::SECRET_PREFIX);
    }

    private function reveal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if (self::isEncrypted($value)) {
            return ($this->decrypt)(substr($value, strlen(self::SECRET_PREFIX)));
        }

        return $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
