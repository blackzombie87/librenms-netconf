<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Checks;

/**
 * One finding of the fabric checks (plan §7.5): which check, how severe, what it is about
 * (the subject makes the key stable across resolves), a message with device names already
 * resolved, and the monitored devices it involves.
 */
final class Issue
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public const SEVERITIES = [self::CRITICAL, self::WARNING, self::INFO];

    /**
     * @param  list<int>  $deviceIds
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $check,
        public readonly string $severity,
        public readonly string $subject,
        public readonly string $message,
        public readonly array $deviceIds = [],
        public readonly array $details = [],
    ) {
    }

    /** Stable identity of the finding: check and subject. */
    public function key(): string
    {
        return $this->check . '|' . $this->subject;
    }

    public static function rank(string $severity): int
    {
        return match ($severity) {
            self::CRITICAL => 0,
            self::WARNING => 1,
            default => 2,
        };
    }
}
