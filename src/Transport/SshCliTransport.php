<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;

/**
 * Default transport: one SSH connection, every command executed on its own exec channel as
 * "show ... | display xml" (the junos_exporter approach). Needs only SSH on port 22 and a
 * login class that allows "show" commands.
 */
class SshCliTransport implements TransportInterface
{
    private bool $connected = false;

    private ?string $authMethod = null;

    private int $commandCount = 0;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly SshClientInterface $client,
    ) {
    }

    public function name(): string
    {
        return Credentials::TRANSPORT_CLI;
    }

    public function connect(): void
    {
        if (! $this->connected) {
            $this->authMethod = $this->client->connect($this->credentials, $this->credentials->connectTimeout);
            $this->connected = true;
        }
    }

    public function run(string $command): Reply
    {
        $this->connect();

        $wire = self::prepare($command);
        $start = microtime(true);
        $output = $this->client->exec($wire, $this->credentials->commandTimeout);
        $this->commandCount++;

        try {
            $reply = Reply::fromCliOutput(self::normalize($command), $output, microtime(true) - $start);
        } catch (ProtocolException $e) {
            // a login class that denies the command, or a shell that swallowed it, talks on stderr
            $stderr = trim($this->client->lastStdError());
            if ($stderr !== '') {
                throw new ProtocolException($e->getMessage() . '; stderr: ' . mb_strimwidth($stderr, 0, 200, '…'), 0, $e);
            }
            throw $e;
        }

        return $reply->assertOk();
    }

    public function rpc(string $xml): Reply
    {
        throw new TransportException('Raw RPCs require the netconf transport; the cli transport only runs "show" commands');
    }

    public function supportsRpc(): bool
    {
        return false;
    }

    public function sessionInfo(): array
    {
        return [
            'transport' => $this->name(),
            'host' => $this->credentials->host,
            'port' => $this->credentials->port,
            'server' => $this->client->serverIdentification(),
            'auth_method' => $this->authMethod ?? '',
            'commands' => $this->commandCount,
        ];
    }

    public function close(): void
    {
        if ($this->connected) {
            $this->client->disconnect();
            $this->connected = false;
        }
    }

    /** Command as sent on the wire: normalized, with a single "| display xml" appended. */
    public static function prepare(string $command): string
    {
        return self::normalize($command) . ' | display xml';
    }

    /** Command without any trailing "| display xml" / "| no-more" pipes and collapsed whitespace. */
    public static function normalize(string $command): string
    {
        $command = preg_replace('/\s+/', ' ', trim($command)) ?? $command;

        while (preg_match('/^(.*?)\s*\|\s*(display xml|no-more)\s*$/i', $command, $m)) {
            $command = $m[1];
        }

        return $command;
    }
}
