<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * One command a definition needs. Either a CLI string (both transports) or a raw RPC body
 * (netconf transport only).
 */
final class CommandSpec
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $cli,
        public readonly ?string $rpc = null,
        public readonly bool $optional = false,
        public readonly int $every = 1,
        public readonly string $description = '',
    ) {
    }

    public function isRpc(): bool
    {
        return $this->rpc !== null;
    }

    /** Identity used to run identical commands from several definitions only once. */
    public function identity(): string
    {
        return $this->isRpc() ? 'rpc:' . trim((string) $this->rpc) : 'cli:' . strtolower(preg_replace('/\s+/', ' ', trim((string) $this->cli)) ?? '');
    }

    /** Human readable label (the cli string or the rpc root element). */
    public function label(): string
    {
        if ($this->isRpc()) {
            return preg_match('/<([A-Za-z][\w.-]*)/', (string) $this->rpc, $m) ? 'rpc:' . $m[1] : 'rpc';
        }

        return (string) $this->cli;
    }

    public function dueAt(int $pollNumber): bool
    {
        return $this->every <= 1 || $pollNumber % $this->every === 0;
    }
}
