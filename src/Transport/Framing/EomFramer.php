<?php

namespace SafferIt\LibrenmsNetconf\Transport\Framing;

/**
 * NETCONF 1.0 end-of-message framing (RFC 6242 §4.3): every message is followed by "]]>]]>".
 */
class EomFramer implements FramerInterface
{
    public const DELIMITER = ']]>]]>';

    public function readDelimiter(): string
    {
        return self::DELIMITER;
    }

    public function encode(string $message): string
    {
        return $message . "\n" . self::DELIMITER . "\n";
    }

    public function decode(string &$buffer): ?string
    {
        $pos = strpos($buffer, self::DELIMITER);
        if ($pos === false) {
            return null;
        }

        $message = substr($buffer, 0, $pos);
        $buffer = ltrim(substr($buffer, $pos + strlen(self::DELIMITER)), "\r\n");

        return trim($message);
    }
}
