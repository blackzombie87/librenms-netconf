<?php

namespace SafferIt\LibrenmsNetconf\Transport\Exceptions;

/**
 * The server's host key is unknown, revoked or differs from the known_hosts entry.
 * A ConnectionException so every caller that handles connection failures handles this.
 */
class HostKeyException extends ConnectionException
{
}
