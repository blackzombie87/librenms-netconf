<?php

namespace SafferIt\LibrenmsNetconf\Console;

use SafferIt\LibrenmsNetconf\Support\FixtureReplay;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Reply;

/**
 * Decorator that records every reply as a fixture file (netconf:preview --save).
 */
class SavingTransport implements TransportInterface
{
    public function __construct(private TransportInterface $inner, private string $directory)
    {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function run(string $command): Reply
    {
        return $this->save($this->inner->run($command));
    }

    public function rpc(string $xml): Reply
    {
        return $this->save($this->inner->rpc($xml));
    }

    public function supportsRpc(): bool
    {
        return $this->inner->supportsRpc();
    }

    public function sessionInfo(): array
    {
        return $this->inner->sessionInfo();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    private function save(Reply $reply): Reply
    {
        file_put_contents(rtrim($this->directory, '/') . '/' . FixtureReplay::slug($reply->command) . '.xml', $reply->pretty());

        return $reply;
    }
}
