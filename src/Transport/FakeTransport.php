<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException;

/**
 * In-memory transport for tests and the "--fixture" mode of netconf:run.
 * Replies are keyed by the exact CLI command string.
 */
class FakeTransport implements TransportInterface
{
    /** @var array<string, string> */
    private array $replies = [];

    /** @var list<string> */
    public array $executed = [];

    public bool $connected = false;

    /**
     * @param  array<string, string>  $replies  command => raw xml
     */
    public function __construct(array $replies = [])
    {
        foreach ($replies as $command => $xml) {
            $this->on($command, $xml);
        }
    }

    public function on(string $command, string $xml): self
    {
        $this->replies[self::normalize($command)] = $xml;

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function run(string $command): Reply
    {
        $this->connect();
        $key = self::normalize($command);
        $this->executed[] = $key;

        if (! array_key_exists($key, $this->replies)) {
            // behave like a device that does not know the command: only this command fails
            throw new RpcErrorException($key, ['no reply registered in FakeTransport']);
        }

        return (new Reply($key, $this->replies[$key]))->assertOk();
    }

    public function rpc(string $xml): Reply
    {
        return $this->run($xml);
    }

    public function supportsRpc(): bool
    {
        return true;
    }

    public function sessionInfo(): array
    {
        return ['transport' => 'fake', 'replies' => count($this->replies)];
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public static function normalize(string $command): string
    {
        $command = preg_replace('/\s+/', ' ', trim($command)) ?? $command;

        return preg_replace('/\s*\|\s*display xml\s*$/i', '', $command) ?? $command;
    }
}
