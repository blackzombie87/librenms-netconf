<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * One command a definition needs. Either a CLI string (both transports) or a raw RPC body
 * (netconf transport only).
 */
final class CommandSpec
{
    /** Where an optional `filter:` goes when the cli string names the position explicitly. */
    public const FILTER_PLACEHOLDER = '{filter}';

    public function __construct(
        public readonly string $key,
        public readonly ?string $cli,
        public readonly ?string $rpc = null,
        public readonly bool $optional = false,
        public readonly int $every = 1,
        public readonly string $description = '',
        public readonly ?string $filter = null,
    ) {
    }

    public function isRpc(): bool
    {
        return $this->rpc !== null;
    }

    /**
     * The CLI command as sent to the device: the cli string with the filter spliced in at the
     * `{filter}` placeholder, or appended when there is none (Junos takes an interface pattern
     * such as `xe-0/0/*` before or after the options). Without a filter the placeholder is dropped.
     */
    public function command(): string
    {
        return self::apply((string) $this->cli, $this->filter);
    }

    public static function apply(string $cli, ?string $filter): string
    {
        $filter = $filter === null ? '' : trim($filter);
        $command = str_contains($cli, self::FILTER_PLACEHOLDER)
            ? str_replace(self::FILTER_PLACEHOLDER, $filter, $cli)
            : ($filter === '' ? $cli : $cli . ' ' . $filter);

        return trim(preg_replace('/\s+/', ' ', $command) ?? $command);
    }

    /** Identity used to run identical commands from several definitions only once. */
    public function identity(): string
    {
        return $this->isRpc() ? 'rpc:' . trim((string) $this->rpc) : 'cli:' . strtolower($this->command());
    }

    /** Human readable label (the cli string or the rpc root element). */
    public function label(): string
    {
        if ($this->isRpc()) {
            return preg_match('/<([A-Za-z][\w.-]*)/', (string) $this->rpc, $m) ? 'rpc:' . $m[1] : 'rpc';
        }

        return $this->command();
    }

    /**
     * The spec that stands for two definitions using the same command: required when either
     * use is required, run as often as the most frequent use needs it (F3 review Issue 10).
     */
    public function merge(self $other): self
    {
        if ($this->optional === ($this->optional && $other->optional) && $this->every === min($this->every, $other->every)) {
            return $this;
        }

        return new self($this->key, $this->cli, $this->rpc, $this->optional && $other->optional, min($this->every, $other->every), $this->description, $this->filter);
    }

    public function dueAt(int $pollNumber): bool
    {
        return $this->every <= 1 || $pollNumber % $this->every === 0;
    }
}
