<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use DOMDocument;
use SafferIt\LibrenmsNetconf\Transport\Contracts\ChannelInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\SshClientInterface;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;
use SafferIt\LibrenmsNetconf\Transport\Framing\ChunkedFramer;
use SafferIt\LibrenmsNetconf\Transport\Framing\EomFramer;
use SafferIt\LibrenmsNetconf\Transport\Framing\FramerInterface;
use Throwable;

/**
 * NETCONF over SSH (RFC 6241/6242): hello exchange, framing negotiation (1.0 end-of-message
 * or 1.1 chunked), <rpc> request/response with message-id correlation.
 *
 * CLI commands are sent as Junos <command format="xml">show ...</command>, which yields the
 * same XML body as "| display xml" over the cli transport.
 */
class NetconfTransport implements TransportInterface
{
    public const CAP_BASE_1_0 = 'urn:ietf:params:netconf:base:1.0';

    public const CAP_BASE_1_1 = 'urn:ietf:params:netconf:base:1.1';

    public const NS_BASE = 'urn:ietf:params:xml:ns:netconf:base:1.0';

    private ?ChannelInterface $channel = null;

    private FramerInterface $framer;

    private string $buffer = '';

    private int $messageId = 0;

    private ?string $authMethod = null;

    private ?string $sessionId = null;

    /** @var list<string> */
    private array $serverCapabilities = [];

    public function __construct(
        private readonly Credentials $credentials,
        private readonly SshClientInterface $client,
        private readonly bool $preferChunked = true,
    ) {
        $this->framer = new EomFramer;
    }

    public function name(): string
    {
        return Credentials::TRANSPORT_NETCONF;
    }

    public function connect(): void
    {
        if ($this->channel !== null) {
            return;
        }

        $this->authMethod = $this->client->connect($this->credentials, $this->credentials->connectTimeout);
        $channel = $this->client->startSubsystem('netconf', $this->credentials->commandTimeout);

        // Both hellos use end-of-message framing (RFC 6242 §4.1)
        $this->framer = new EomFramer;
        $this->buffer = '';
        $this->channel = $channel;

        $hello = $this->receive('hello');
        $this->parseHello($hello);

        $channel->write($this->framer->encode($this->clientHello()));

        if ($this->preferChunked && in_array(self::CAP_BASE_1_1, $this->serverCapabilities, true)) {
            $this->framer = new ChunkedFramer;
        } elseif (! in_array(self::CAP_BASE_1_0, $this->serverCapabilities, true)) {
            throw new ProtocolException($this->credentials->host . ': server advertises neither NETCONF base:1.0 nor base:1.1');
        }
    }

    public function run(string $command): Reply
    {
        $command = SshCliTransport::normalize($command);
        $body = '<command format="xml">' . htmlspecialchars($command, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</command>';

        return $this->request($body, $command);
    }

    public function rpc(string $xml): Reply
    {
        return $this->request($xml, self::rpcLabel($xml));
    }

    public function supportsRpc(): bool
    {
        return true;
    }

    public function sessionInfo(): array
    {
        $base = array_values(array_filter($this->serverCapabilities, fn ($c) => str_starts_with($c, 'urn:ietf:params:netconf:base:')));

        return [
            'transport' => $this->name(),
            'host' => $this->credentials->host,
            'port' => $this->credentials->port,
            'server' => $this->client->serverIdentification(),
            'auth_method' => $this->authMethod ?? '',
            'session_id' => $this->sessionId ?? '',
            'framing' => $this->framer instanceof ChunkedFramer ? 'chunked (1.1)' : 'end-of-message (1.0)',
            'base_versions' => implode(', ', array_map(fn ($c) => substr($c, strrpos($c, ':') + 1), $base)),
            'capabilities' => $this->serverCapabilities,
            'messages' => $this->messageId,
        ];
    }

    public function close(): void
    {
        if ($this->channel !== null) {
            try {
                $this->channel->write($this->framer->encode($this->rpcEnvelope('<close-session/>')));
                $this->receive('close-session');
            } catch (Throwable) {
                // best effort
            }
            $this->channel->close();
            $this->channel = null;
        }
        $this->client->disconnect();
    }

    /**
     * @return list<string>
     */
    public function serverCapabilities(): array
    {
        return $this->serverCapabilities;
    }

    private function request(string $body, string $label): Reply
    {
        $this->connect();

        $envelope = $this->rpcEnvelope($body);
        $expectedId = (string) $this->messageId;

        $start = microtime(true);
        $this->channel()->write($this->framer->encode($envelope));
        $raw = $this->receive($label);
        $reply = new Reply($label, $raw, microtime(true) - $start);

        $gotId = $reply->dom()->documentElement?->getAttribute('message-id') ?? '';
        if ($gotId !== '' && $gotId !== $expectedId) {
            throw new ProtocolException(sprintf('%s: expected message-id %s, got %s', $label, $expectedId, $gotId));
        }

        return $reply->assertOk();
    }

    private function rpcEnvelope(string $body): string
    {
        $this->messageId++;

        return sprintf('<rpc message-id="%d" xmlns="%s">%s</rpc>', $this->messageId, self::NS_BASE, $body);
    }

    private function clientHello(): string
    {
        $caps = [self::CAP_BASE_1_0];
        if ($this->preferChunked) {
            $caps[] = self::CAP_BASE_1_1;
        }

        return sprintf(
            '<hello xmlns="%s"><capabilities>%s</capabilities></hello>',
            self::NS_BASE,
            implode('', array_map(fn ($c) => "<capability>$c</capability>", $caps))
        );
    }

    private function parseHello(string $xml): void
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok || $dom->documentElement === null || $dom->documentElement->localName !== 'hello') {
            throw new ProtocolException($this->credentials->host . ': expected NETCONF <hello>, got ' . json_encode(mb_strimwidth(trim($xml), 0, 120, '…')));
        }

        $this->serverCapabilities = [];
        foreach ($dom->getElementsByTagName('capability') as $cap) {
            $value = trim($cap->textContent);
            if ($value !== '') {
                $this->serverCapabilities[] = $value;
            }
        }

        $sessions = $dom->getElementsByTagName('session-id');
        $this->sessionId = $sessions->length > 0 ? trim($sessions->item(0)->textContent) : null;
    }

    /**
     * Read one framed message from the channel.
     */
    private function receive(string $label): string
    {
        $channel = $this->channel();
        $delimiter = $this->framer->readDelimiter();

        while (true) {
            $message = $this->framer->decode($this->buffer);
            if ($message !== null) {
                return $message;
            }

            $chunk = $channel->readUntil($delimiter);
            $this->buffer .= $chunk;

            if ($channel->isTimeout()) {
                // the channel returns whatever arrived before the timeout; it may complete the frame
                $message = $this->framer->decode($this->buffer);
                if ($message !== null) {
                    return $message;
                }

                throw new TimeoutException(sprintf(
                    '%s: no complete NETCONF reply to "%s" within %ds (%d bytes buffered)',
                    $this->credentials->host,
                    $label,
                    $this->credentials->commandTimeout,
                    strlen($this->buffer)
                ));
            }

            if ($chunk === '') {
                throw new ProtocolException(sprintf('%s: channel closed while waiting for "%s"', $this->credentials->host, $label));
            }
        }
    }

    private function channel(): ChannelInterface
    {
        if ($this->channel === null) {
            throw new ProtocolException($this->credentials->host . ': NETCONF session not open');
        }

        return $this->channel;
    }

    private static function rpcLabel(string $xml): string
    {
        return preg_match('/<([A-Za-z][\w.-]*)/', $xml, $m) ? 'rpc:' . $m[1] : 'rpc';
    }
}
