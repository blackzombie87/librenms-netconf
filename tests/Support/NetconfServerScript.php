<?php

namespace SafferIt\LibrenmsNetconf\Tests\Support;

use SafferIt\LibrenmsNetconf\Transport\Framing\ChunkedFramer;
use SafferIt\LibrenmsNetconf\Transport\Framing\EomFramer;

/**
 * Minimal scripted NETCONF server for the FakeChannel: answers hello with the given
 * capabilities and every <rpc> with the reply registered for its <command> text.
 */
class NetconfServerScript
{
    /** @var array<string, string> command => reply body (inside rpc-reply) */
    public array $replies = [];

    /** @var list<string> */
    public array $receivedRpcs = [];

    public bool $chunkedAfterHello = false;

    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public array $capabilities = ['urn:ietf:params:netconf:base:1.0', 'urn:ietf:params:netconf:base:1.1'],
        public string $sessionId = '4711',
    ) {
    }

    public function hello(): string
    {
        $caps = implode('', array_map(fn ($c) => "<capability>$c</capability>", $this->capabilities));

        return (new EomFramer)->encode(
            '<!-- No zombies were killed during the creation of this user interface -->'
            . '<!-- user librenms, class j-netconf -->'
            . '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>' . $caps . '</capabilities>'
            . '<session-id>' . $this->sessionId . '</session-id></hello>'
        );
    }

    public function channel(): FakeChannel
    {
        return new FakeChannel($this->hello(), fn (string $written) => $this->respond($written));
    }

    private function respond(string $written): ?string
    {
        // strip framing of the incoming message
        $eom = new EomFramer;
        $chunked = new ChunkedFramer;
        $buffer = $written;
        $message = str_starts_with(ltrim($written), '<') ? $eom->decode($buffer) : $chunked->decode($buffer);
        if ($message === null) {
            return null;
        }

        if (str_contains($message, '<hello')) {
            $this->chunkedAfterHello = in_array('urn:ietf:params:netconf:base:1.1', $this->capabilities, true)
                && str_contains($message, 'base:1.1');

            return null;
        }

        $this->receivedRpcs[] = $message;

        preg_match('/message-id="(\d+)"/', $message, $id);
        preg_match('/<command[^>]*>(.*?)<\/command>/s', $message, $cmd);
        $command = html_entity_decode($cmd[1] ?? '', ENT_XML1 | ENT_QUOTES);

        if (str_contains($message, '<close-session/>')) {
            $body = '<ok/>';
        } else {
            $body = $this->replies[$command] ?? '<xnm:error xmlns:xnm="http://xml.juniper.net/xnm/1.1/xnm"><message>error: unknown command: ' . htmlspecialchars($command) . '</message></xnm:error>';
        }

        $reply = sprintf(
            '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" xmlns:junos="http://xml.juniper.net/junos/23.4R0/junos" message-id="%s">%s</rpc-reply>',
            $id[1] ?? '',
            $body
        );

        return $this->chunkedAfterHello ? $chunked->encode($reply) : $eom->encode($reply);
    }
}
