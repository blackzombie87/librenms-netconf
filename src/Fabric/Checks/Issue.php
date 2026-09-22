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

    /**
     * Stable identity of the finding: the check and a digest of the subject. The digest keeps
     * the key at 32 + 1 + 40 characters whatever the subject's length (the column holds 191
     * and the subject is a display value), so lookup, dedupe, insert and clear all use the
     * same string and two subjects that share a long prefix stay two issues (F5 5).
     */
    public function key(): string
    {
        return self::keyFor($this->check, $this->subject);
    }

    public static function keyFor(string $check, string $subject): string
    {
        return $check . '|' . sha1($subject);
    }

    /** The key rows carried before the digest: check|subject cut at the column width. */
    public static function legacyKey(string $check, string $subject): string
    {
        return mb_substr($check . '|' . $subject, 0, 191);
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
