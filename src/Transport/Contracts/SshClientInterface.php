<?php

namespace SafferIt\LibrenmsNetconf\Transport\Contracts;

use SafferIt\LibrenmsNetconf\Transport\Credentials;

/**
 * Thin abstraction over the SSH library so transports can be unit-tested with a fake.
 */
interface SshClientInterface
{
    /**
     * Open the TCP connection and authenticate. Tries the methods in $credentials->authOrder.
     *
     * @return string the method that succeeded ("key" or "password")
     *
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\AuthenticationException
     */
    public function connect(Credentials $credentials, int $connectTimeout): string;

    /**
     * Run one non-interactive command on a fresh exec channel and return stdout.
     *
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException
     */
    public function exec(string $command, int $timeout): string;

    /** Last stderr output of exec(), for diagnostics. */
    public function lastStdError(): string;

    /**
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException
     */
    public function startSubsystem(string $name, int $timeout): ChannelInterface;

    public function serverIdentification(): string;

    public function isConnected(): bool;

    public function disconnect(): void;
}
