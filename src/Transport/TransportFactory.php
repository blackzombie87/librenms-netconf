<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;

/**
 * Builds the transport matching the credentials' transport mode.
 */
class TransportFactory
{
    public function make(Credentials $credentials, ?SshClientInterface $client = null): TransportInterface
    {
        $client ??= new PhpseclibSshClient;

        return $credentials->isNetconf()
            ? new NetconfTransport($credentials, $client)
            : new SshCliTransport($credentials, $client);
    }
}
