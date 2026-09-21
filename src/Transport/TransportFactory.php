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

        if ($credentials->isAuto()) {
            return new AutoTransport($credentials, $client);
        }

        return $credentials->isNetconf()
            ? new NetconfTransport($credentials, $client)
            : new SshCliTransport($credentials, $client);
    }
}
