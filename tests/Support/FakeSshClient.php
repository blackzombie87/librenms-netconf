<?php

namespace SafferIt\LibrenmsNetconf\Tests\Support;

use SafferIt\LibrenmsNetconf\Transport\Contracts\ChannelInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\AuthenticationException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;

class FakeSshClient implements SshClientInterface
{
    /** @var array<string, string> exec command => stdout */
    public array $execReplies = [];

    /** @var list<string> */
    public array $executed = [];

    public ?ChannelInterface $channel = null;

    public ?Credentials $connectedWith = null;

    public bool $connected = false;

    public int $connects = 0;

    /** Thrown by startSubsystem() to simulate a server without the subsystem. */
    public ?\Throwable $subsystemError = null;

    /** @var list<string> */
    public array $subsystems = [];

    public bool $disconnected = false;

    public string $acceptMethod = Credentials::AUTH_PASSWORD;

    public string $stderr = '';

    /** @var list<string> commands that should time out */
    public array $timeouts = [];

    public function connect(Credentials $credentials, int $connectTimeout): string
    {
        $methods = $credentials->usableAuthMethods();
        if (! in_array($this->acceptMethod, $methods, true)) {
            throw new AuthenticationException('fake: authentication failed');
        }
        $this->connected = true;
        $this->connects++;
        $this->connectedWith = $credentials;

        return $this->acceptMethod;
    }

    public function exec(string $command, int $timeout): string
    {
        $this->executed[] = $command;
        if (in_array($command, $this->timeouts, true)) {
            throw new TimeoutException("fake: timeout on $command");
        }

        return $this->execReplies[$command] ?? '';
    }

    public function lastStdError(): string
    {
        return $this->stderr;
    }

    public function startSubsystem(string $name, int $timeout): ChannelInterface
    {
        $this->subsystems[] = $name;
        if ($this->subsystemError !== null) {
            throw $this->subsystemError;
        }

        return $this->channel ?? new FakeChannel;
    }

    public function serverIdentification(): string
    {
        return 'SSH-2.0-OpenSSH_7.5 (fake)';
    }

    public function serverHostKey(): string
    {
        return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPn7v1Zt7nJc9m6z0m3mKk3rQ1Uu3o8dS3xTo3v3Bq2f';
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }
}
