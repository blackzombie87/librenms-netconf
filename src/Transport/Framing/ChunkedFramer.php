<?php

namespace SafferIt\LibrenmsNetconf\Transport\Framing;

use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;

/**
 * NETCONF 1.1 chunked framing (RFC 6242 §4.2):
 *
 *   \n#<chunk-size>\n<chunk-data> ... \n##\n
 */
class ChunkedFramer implements FramerInterface
{
    public const END = "\n##\n";

    public const MAX_CHUNK = 4294967295;

    public function readDelimiter(): string
    {
        return self::END;
    }

    public function encode(string $message): string
    {
        return sprintf("\n#%d\n%s%s", strlen($message), $message, self::END);
    }

    public function decode(string &$buffer): ?string
    {
        $offset = 0;
        $length = strlen($buffer);
        $message = '';

        // Junos may emit whitespace between frames; never eat the "\n#" that starts a header
        while ($offset < $length && ctype_space($buffer[$offset]) && substr($buffer, $offset, 2) !== "\n#") {
            $offset++;
        }

        while (true) {
            if ($offset >= $length) {
                return null; // need more data
            }

            if (substr($buffer, $offset, 4) === self::END) {
                $buffer = substr($buffer, $offset + 4);

                return $message;
            }

            if ($offset + 2 > $length) {
                return null;
            }

            if (substr($buffer, $offset, 2) !== "\n#") {
                throw new ProtocolException(sprintf(
                    'Malformed chunk header at offset %d: %s',
                    $offset,
                    json_encode(substr($buffer, $offset, 16))
                ));
            }

            $lf = strpos($buffer, "\n", $offset + 2);
            if ($lf === false) {
                return null; // header incomplete
            }

            $sizeText = substr($buffer, $offset + 2, $lf - $offset - 2);
            if (! preg_match('/^[1-9][0-9]{0,9}$/', $sizeText)) {
                throw new ProtocolException("Malformed chunk size '$sizeText'");
            }

            $size = (int) $sizeText;
            if ($size > self::MAX_CHUNK) {
                throw new ProtocolException("Chunk size $size exceeds RFC 6242 maximum");
            }

            $dataStart = $lf + 1;
            if ($dataStart + $size > $length) {
                return null; // chunk data incomplete
            }

            $message .= substr($buffer, $dataStart, $size);
            $offset = $dataStart + $size;
        }
    }
}
