<?php

namespace SafferIt\LibrenmsNetconf\Transport\Contracts;

use SafferIt\LibrenmsNetconf\Transport\Reply;

/**
 * Runs operational commands against one device and returns parsed XML replies.
 * Implementations: SshCliTransport ("show ... | display xml" over exec),
 * NetconfTransport (NETCONF subsystem, RFC 6242 framing), FakeTransport (tests).
 */
interface TransportInterface
{
    /** Short identifier used in status output and logs: "cli" or "netconf" ("auto" until AutoTransport has decided). */
    public function name(): string;

    /**
     * Establish the session. Idempotent; run() connects lazily if needed.
     *
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException
     */
    public function connect(): void;

    /**
     * Execute a Junos operational CLI command (without "| display xml") and return the reply.
     *
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException when the device reports an error
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException on connection/timeout problems
     */
    public function run(string $command): Reply;

    /**
     * Send a raw RPC body (the XML inside <rpc>...</rpc>). Only NETCONF supports this.
     *
     * @throws \SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException when unsupported
     */
    public function rpc(string $xml): Reply;

    public function supportsRpc(): bool;

    /**
     * Free-form facts about the session for `netconf:test` (server id, capabilities, ...).
     *
     * @return array<string, scalar|array<int, string>>
     */
    public function sessionInfo(): array;

    public function close(): void;
}
