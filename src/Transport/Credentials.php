<?php

namespace SafferIt\LibrenmsNetconf\Transport;

/**
 * Everything needed to open a session to one device. Immutable value object.
 * Secrets are held in clear text here (decrypted by CredentialResolver) and must
 * never be logged or echoed; use describe() for output.
 */
final class Credentials
{
    public const TRANSPORT_CLI = 'cli';

    public const TRANSPORT_NETCONF = 'netconf';

    /** NETCONF subsystem when the server offers it on the SSH port, exec channels otherwise. */
    public const TRANSPORT_AUTO = 'auto';

    public const TRANSPORTS = [self::TRANSPORT_AUTO, self::TRANSPORT_CLI, self::TRANSPORT_NETCONF];

    public const AUTH_KEY = 'key';

    public const AUTH_PASSWORD = 'password';

    /**
     * @param  list<string>  $authOrder  subset of [key, password], tried in order
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port = 22,
        public readonly string $transport = self::TRANSPORT_AUTO,
        public readonly string $username = '',
        public readonly ?string $password = null,
        public readonly ?string $keyFile = null,
        public readonly ?string $keyPassphrase = null,
        public readonly array $authOrder = [self::AUTH_KEY, self::AUTH_PASSWORD],
        public readonly int $connectTimeout = 10,
        public readonly int $commandTimeout = 30,
        public readonly ?string $knownHosts = null,
    ) {
    }

    /** Whether the server host key must match an entry in the known_hosts file. */
    public function verifiesHostKey(): bool
    {
        return $this->knownHosts !== null && $this->knownHosts !== '';
    }

    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    public function hasKey(): bool
    {
        return $this->keyFile !== null && $this->keyFile !== '';
    }

    /**
     * Authentication methods that are both requested and usable.
     *
     * @return list<string>
     */
    public function usableAuthMethods(): array
    {
        $methods = [];
        foreach ($this->authOrder as $method) {
            if ($method === self::AUTH_KEY && $this->hasKey()) {
                $methods[] = self::AUTH_KEY;
            } elseif ($method === self::AUTH_PASSWORD && $this->hasPassword()) {
                $methods[] = self::AUTH_PASSWORD;
            }
        }

        return $methods;
    }

    public function isNetconf(): bool
    {
        return $this->transport === self::TRANSPORT_NETCONF;
    }

    public function isAuto(): bool
    {
        return $this->transport === self::TRANSPORT_AUTO;
    }

    /**
     * Human readable summary without secrets.
     *
     * @return array<string, string|int|bool>
     */
    public function describe(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'transport' => $this->transport,
            'username' => $this->username,
            'password' => $this->hasPassword() ? 'set' : 'missing',
            'key_file' => $this->keyFile ?: 'missing',
            'key_passphrase' => ($this->keyPassphrase ?? '') !== '' ? 'set' : 'missing',
            'auth_order' => implode(',', $this->authOrder),
            'connect_timeout' => $this->connectTimeout,
            'command_timeout' => $this->commandTimeout,
            'known_hosts' => $this->verifiesHostKey() ? (string) $this->knownHosts : 'not verified',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        $values = get_object_vars($this);
        foreach ($overrides as $key => $value) {
            if ($value !== null && array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }

        return new self(...$values);
    }
}
