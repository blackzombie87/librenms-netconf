<?php

namespace SafferIt\LibrenmsNetconf\Transport\Exceptions;

/**
 * The device answered, but with an error: NETCONF <rpc-error> or Junos <xnm:error>.
 */
class RpcErrorException extends TransportException
{
    /**
     * @param  list<string>  $messages  individual error messages from the reply
     */
    public function __construct(
        public readonly string $command,
        public readonly array $messages,
    ) {
        parent::__construct(sprintf('%s: %s', $command, implode('; ', $messages) ?: 'unknown rpc error'));
    }
}
