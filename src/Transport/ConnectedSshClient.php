<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use SafferIt\LibrenmsNetconf\Transport\Contracts\ChannelInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;

/**
 * Wraps an SSH client that is already connected and authenticated: connect() returns the
 * recorded auth method without touching the wire, and a channel opened before the wrap
 * (the AutoTransport probe) is handed out by the first startSubsystem() call.
 */
final class ConnectedSshClient implements SshClientInterface
{
    public function __construct(
        private readonly SshClientInterface $inner,
        private readonly string $authMethod,
        private ?ChannelInterface $openChannel = null,
    ) {
    }

    public function connect(Credentials $credentials, int $connectTimeout): string
    {
        return $this->authMethod;
    }

    public function exec(string $command, int $timeout): string
    {
        return $this->inner->exec($command, $timeout);
    }

    public function lastStdError(): string
    {
        return $this->inner->lastStdError();
    }

    public function startSubsystem(string $name, int $timeout): ChannelInterface
    {
        if ($this->openChannel !== null) {
            $channel = $this->openChannel;
            $this->openChannel = null;

            return $channel;
        }

        return $this->inner->startSubsystem($name, $timeout);
    }

    public function serverIdentification(): string
    {
        return $this->inner->serverIdentification();
    }

    public function serverHostKey(): string
    {
        return $this->inner->serverHostKey();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }
}
