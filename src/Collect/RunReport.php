<?php

namespace SafferIt\LibrenmsNetconf\Collect;

/**
 * What one discovery or poll run did for a device (for logs, dump() and the status row).
 */
final class RunReport
{
    /**
     * @param  list<string>  $definitions
     * @param  array<string, int>  $summary
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly bool $discovery,
        public readonly string $transport,
        public readonly array $definitions,
        public readonly array $summary,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly float $duration,
        public readonly ?CollectionResult $collection = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->discovery ? 'discovery' : 'poll',
            'transport' => $this->transport,
            'definitions' => $this->definitions,
            'duration' => round($this->duration, 3),
            'errors' => $this->errors,
            'warnings' => count($this->warnings),
        ] + $this->summary;
    }
}
