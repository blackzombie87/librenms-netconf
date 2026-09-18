<?php

namespace SafferIt\LibrenmsNetconf\Tests\Support;

use SafferIt\LibrenmsNetconf\Transport\Contracts\ChannelInterface;

/**
 * Scripted channel: server bytes are queued up front (or via a responder closure that is
 * called with each written message), reads hand them out until the delimiter.
 */
class FakeChannel implements ChannelInterface
{
    private string $incoming = '';

    /** @var list<string> */
    public array $written = [];

    public bool $closed = false;

    private bool $timeout = false;

    /** @var (callable(string): ?string)|null */
    private $responder;

    /** Maximum bytes returned per readUntil() call, to simulate fragmented reads. */
    public int $fragment = PHP_INT_MAX;

    /**
     * @param  (callable(string): ?string)|null  $responder  receives raw written bytes, returns bytes to queue
     */
    public function __construct(string $initial = '', ?callable $responder = null)
    {
        $this->incoming = $initial;
        $this->responder = $responder;
    }

    public function queue(string $bytes): void
    {
        $this->incoming .= $bytes;
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
        if ($this->responder) {
            $response = ($this->responder)($data);
            if ($response !== null) {
                $this->incoming .= $response;
            }
        }
    }

    public function readUntil(string $delimiter): string
    {
        $this->timeout = false;

        if ($this->incoming === '') {
            $this->timeout = true;

            return '';
        }

        $pos = strpos($this->incoming, $delimiter);
        $length = $pos === false ? strlen($this->incoming) : $pos + strlen($delimiter);
        $length = min($length, $this->fragment);

        $chunk = substr($this->incoming, 0, $length);
        $this->incoming = substr($this->incoming, $length);

        if ($pos === false || $pos + strlen($delimiter) > $length) {
            // delimiter not (fully) delivered: a real channel would block and time out
            $this->timeout = $this->incoming === '';
        }

        return $chunk;
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
