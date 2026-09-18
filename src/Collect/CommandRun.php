<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use SafferIt\LibrenmsNetconf\Extract\XmlDocument;

/**
 * Outcome of one command during a collection.
 */
final class CommandRun
{
    public const OK = 'ok';

    public const SKIPPED = 'skipped';      // optional command failed, or not due (every:)

    public const ERROR = 'error';

    public function __construct(
        public readonly string $identity,
        public readonly string $label,
        public readonly string $status,
        public readonly float $duration = 0.0,
        public readonly int $bytes = 0,
        public readonly ?XmlDocument $document = null,
        public readonly ?string $message = null,
    ) {
    }
}
