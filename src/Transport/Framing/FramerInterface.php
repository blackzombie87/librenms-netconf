<?php

namespace SafferIt\LibrenmsNetconf\Transport\Framing;

/**
 * RFC 6242 message framing for NETCONF over SSH.
 */
interface FramerInterface
{
    /** Byte sequence that reliably terminates a frame on the wire; used to read from the channel. */
    public function readDelimiter(): string;

    /** Wrap one XML message for sending. */
    public function encode(string $message): string;

    /**
     * Try to extract one complete message from the front of $buffer.
     * On success the consumed bytes are removed from $buffer and the message returned.
     * Returns null when the buffer does not yet hold a full message.
     *
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException on malformed framing
     */
    public function decode(string &$buffer): ?string;
}
