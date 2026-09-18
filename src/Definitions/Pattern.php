<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * Match rule used in `match:` blocks: a literal (case-insensitive), a "/regex/" or a list of either.
 */
final class Pattern
{
    /** @var list<string> */
    private array $alternatives;

    /**
     * @param  string|list<string>  $spec
     */
    public function __construct(string|array $spec)
    {
        $this->alternatives = array_values(array_map('strval', is_array($spec) ? $spec : [$spec]));
    }

    public static function isRegex(string $value): bool
    {
        return strlen($value) > 2 && $value[0] === '/' && preg_match('~^/.*/[imsux]*$~s', $value) === 1;
    }

    public function matches(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        foreach ($this->alternatives as $alt) {
            if (self::isRegex($alt)) {
                if (@preg_match($alt, $value) === 1) {
                    return true;
                }
            } elseif (strcasecmp($alt, $value) === 0) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return implode('|', $this->alternatives);
    }
}
