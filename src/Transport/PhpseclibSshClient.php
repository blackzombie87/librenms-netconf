<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use SafferIt\LibrenmsNetconf\Transport\Contracts\ChannelInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\AuthenticationException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;
use Throwable;

/**
 * SshClientInterface backed by phpseclib 3.
 *
 * Host keys are not verified (decision 2026-09-18); phpseclib accepts any server key.
 */
class PhpseclibSshClient implements SshClientInterface
{
    private ?SSH2 $ssh = null;

    private string $stderr = '';

    private string $host = '';

    private int $port = 0;

    public function connect(Credentials $credentials, int $connectTimeout): string
    {
        $methods = $credentials->usableAuthMethods();
        if ($methods === []) {
            throw new AuthenticationException(sprintf(
                '%s: no usable credentials (auth order %s, password %s, key file %s)',
                $credentials->host,
                implode(',', $credentials->authOrder),
                $credentials->hasPassword() ? 'set' : 'missing',
                $credentials->hasKey() ? 'set' : 'missing'
            ));
        }
        if ($credentials->username === '') {
            throw new AuthenticationException($credentials->host . ': no username configured');
        }

        $this->host = $credentials->host;
        $this->port = $credentials->port;

        try {
            $ssh = new SSH2($credentials->host, $credentials->port, $connectTimeout);
            $ssh->enableQuietMode();
        } catch (Throwable $e) {
            throw new ConnectionException($this->target() . ': ' . $e->getMessage(), 0, $e);
        }

        $failures = [];
        foreach ($methods as $method) {
            try {
                $secret = $method === Credentials::AUTH_KEY
                    ? $this->loadKey($credentials)
                    : (string) $credentials->password;

                if ($ssh->login($credentials->username, $secret)) {
                    $this->ssh = $ssh;

                    return $method;
                }
                $failures[] = $method . ' rejected';
            } catch (AuthenticationException $e) {
                $failures[] = $e->getMessage();
            } catch (Throwable $e) {
                // phpseclib throws UnableToConnectException / ConnectionClosedException on transport problems
                throw new ConnectionException($this->target() . ': ' . $e->getMessage(), 0, $e);
            }
        }

        $errors = array_filter($ssh->getErrors());
        $ssh->disconnect();

        throw new AuthenticationException(sprintf(
            '%s: authentication failed for user %s (%s%s)',
            $this->target(),
            $credentials->username,
            implode(', ', $failures),
            $errors ? '; ' . implode(', ', array_map('strval', $errors)) : ''
        ));
    }

    public function exec(string $command, int $timeout): string
    {
        $ssh = $this->connection();
        $ssh->setTimeout($timeout);

        try {
            $output = $ssh->exec($command);
        } catch (Throwable $e) {
            throw new ConnectionException($this->target() . ': ' . $e->getMessage(), 0, $e);
        }

        $this->stderr = (string) $ssh->getStdError();

        if ($ssh->isTimeout()) {
            throw new TimeoutException(sprintf('%s: no complete answer to "%s" within %ds', $this->target(), $command, $timeout));
        }

        return is_string($output) ? $output : '';
    }

    public function lastStdError(): string
    {
        return $this->stderr;
    }

    public function startSubsystem(string $name, int $timeout): ChannelInterface
    {
        $ssh = $this->connection();
        $ssh->setTimeout($timeout);

        try {
            $started = $ssh->startSubsystem($name);
        } catch (Throwable $e) {
            throw new ConnectionException($this->target() . ': ' . $e->getMessage(), 0, $e);
        }

        if (! $started) {
            throw new ConnectionException(sprintf(
                '%s: subsystem "%s" refused (is "set system services netconf ssh" configured and allowed for this login class?)',
                $this->target(),
                $name
            ));
        }

        return new PhpseclibChannel($ssh);
    }

    public function serverIdentification(): string
    {
        return $this->ssh ? trim((string) $this->ssh->getServerIdentification()) : '';
    }

    public function disconnect(): void
    {
        if ($this->ssh) {
            try {
                $this->ssh->disconnect();
            } catch (Throwable) {
                // ignore, we are leaving anyway
            }
            $this->ssh = null;
        }
    }

    private function connection(): SSH2
    {
        if ($this->ssh === null) {
            throw new ConnectionException(($this->target() ?: 'ssh') . ': not connected');
        }

        return $this->ssh;
    }

    private function target(): string
    {
        return $this->host !== '' ? $this->host . ':' . $this->port : '';
    }

    /**
     * @return \phpseclib3\Crypt\Common\PrivateKey
     */
    private function loadKey(Credentials $credentials)
    {
        $file = (string) $credentials->keyFile;
        if (! is_readable($file)) {
            throw new AuthenticationException("key file $file is not readable");
        }

        $content = (string) file_get_contents($file);

        try {
            return PublicKeyLoader::loadPrivateKey($content, ($credentials->keyPassphrase ?? '') !== '' ? $credentials->keyPassphrase : false);
        } catch (Throwable $e) {
            throw new AuthenticationException("key file $file could not be loaded: " . $e->getMessage(), 0, $e);
        }
    }
}
