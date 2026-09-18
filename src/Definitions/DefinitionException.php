<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

use RuntimeException;

/**
 * A YAML definition is missing, unreadable or violates the schema.
 */
class DefinitionException extends RuntimeException
{
    public static function at(string $source, string $path, string $problem): self
    {
        return new self(sprintf('%s [%s]: %s', $source, $path, $problem));
    }
}
