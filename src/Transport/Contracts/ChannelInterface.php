<?php

namespace SafferIt\LibrenmsNetconf\Transport\Contracts;

/**
 * A bidirectional byte stream (an SSH subsystem channel). Kept minimal so tests can
 * script it and the NETCONF session logic stays independent of phpseclib.
 */
interface ChannelInterface
{
    public function write(string $data): void;

    /**
     * Block until $delimiter has been received (inclusive) or the timeout expires.
     * On timeout the partial buffer is returned and isTimeout() is true.
     */
    public function readUntil(string $delimiter): string;

    public function isTimeout(): bool;

    public function close(): void;
}
