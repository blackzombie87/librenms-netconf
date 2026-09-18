<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use phpseclib3\Net\SSH2;
use SafferIt\LibrenmsNetconf\Transport\Contracts\ChannelInterface;

/**
 * ChannelInterface over an active phpseclib subsystem channel.
 */
class PhpseclibChannel implements ChannelInterface
{
    private bool $open = true;

    public function __construct(private SSH2 $ssh)
    {
    }

    public function write(string $data): void
    {
        $this->ssh->write($data);
    }

    public function readUntil(string $delimiter): string
    {
        $data = $this->ssh->read($delimiter, SSH2::READ_SIMPLE);

        return is_string($data) ? $data : '';
    }

    public function isTimeout(): bool
    {
        return $this->ssh->isTimeout();
    }

    public function close(): void
    {
        if ($this->open) {
            $this->open = false;
            try {
                $this->ssh->stopSubsystem();
            } catch (\Throwable) {
                // channel already gone
            }
        }
    }
}
