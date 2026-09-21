<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException;

/**
 * Transport "auto": one SSH login (port 22 by default), then the NETCONF subsystem is
 * requested on that connection. Junos answers a channel failure when "set system services
 * netconf ssh" is missing or the login class may not use it; the transport then falls back
 * to exec channels ("show ... | display xml") on the same connection. No second login, no
 * second timeout. name() reports the mode that was actually negotiated.
 *
 * Why prefer NETCONF: a login class with deny-commands costs about one second of CLI
 * start-up per exec channel, the subsystem pays it once per session (EX4650: 22 commands
 * in 13 s over NETCONF vs. 45 s over exec).
 */
class AutoTransport implements TransportInterface
{
    private ?TransportInterface $inner = null;

    private ?string $fallbackReason = null;

    /** SSH login done; set before the inner transport exists so close() can still hang up. */
    private bool $sshUp = false;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly SshClientInterface $client,
    ) {
    }

    public function name(): string
    {
        return $this->inner?->name() ?? Credentials::TRANSPORT_AUTO;
    }

    /** Why the NETCONF subsystem was not used; null while undecided or when it is in use. */
    public function fallbackReason(): ?string
    {
        return $this->fallbackReason;
    }

    public function connect(): void
    {
        if ($this->inner !== null) {
            return;
        }

        $authMethod = $this->client->connect($this->credentials, $this->credentials->connectTimeout);
        $this->sshUp = true;

        try {
            $channel = $this->client->startSubsystem('netconf', $this->credentials->commandTimeout);
        } catch (ConnectionException $e) {
            $this->fallbackReason = $e->getMessage();
            $this->inner = new SshCliTransport($this->credentials, new ConnectedSshClient($this->client, $authMethod));
            $this->inner->connect();

            return;
        }

        $this->inner = new NetconfTransport($this->credentials, new ConnectedSshClient($this->client, $authMethod, $channel));
        $this->inner->connect();
    }

    public function run(string $command): Reply
    {
        $this->connect();

        return $this->inner->run($command);
    }

    public function rpc(string $xml): Reply
    {
        $this->connect();

        return $this->inner->rpc($xml);
    }

    public function supportsRpc(): bool
    {
        $this->connect();

        return $this->inner->supportsRpc();
    }

    public function sessionInfo(): array
    {
        $this->connect();

        $info = $this->inner->sessionInfo();
        $info['transport'] = $this->name() . ' (auto)';
        if ($this->fallbackReason !== null) {
            $info['netconf_subsystem'] = 'refused: ' . $this->fallbackReason;
        }

        return $info;
    }

    /**
     * Hangs up on every path: the inner transport when one exists (also one whose own
     * connect() failed after the login), else the bare SSH connection when the login went
     * through but no inner transport was assigned (the subsystem request threw something
     * other than a refusal).
     */
    public function close(): void
    {
        if ($this->inner !== null) {
            $this->inner->close();
        } elseif ($this->sshUp) {
            $this->client->disconnect();
        }
        $this->sshUp = false;
    }
}
