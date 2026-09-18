<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use DOMDocument;
use DOMElement;
use DOMXPath;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException;

/**
 * One XML reply from a device. Holds the raw bytes and a lazily parsed DOM.
 *
 * Understands the two error shapes seen in Phase 0:
 *  - Junos CLI/NETCONF: <rpc-reply><xnm:error><message>…</message></xnm:error></rpc-reply>
 *  - NETCONF: <rpc-reply><rpc-error><error-message>…</error-message></rpc-error></rpc-reply>
 */
class Reply
{
    public const NS_NETCONF = 'urn:ietf:params:xml:ns:netconf:base:1.0';

    public const NS_XNM = 'http://xml.juniper.net/xnm/1.1/xnm';

    private ?DOMDocument $dom = null;

    public function __construct(
        public readonly string $command,
        public readonly string $raw,
        public readonly float $duration = 0.0,
    ) {
    }

    /**
     * Build a reply from CLI exec output: anything before the first "<" (warnings, banners)
     * and after the last ">" (prompt residue) is discarded.
     */
    public static function fromCliOutput(string $command, string $output, float $duration = 0.0): self
    {
        // Junos answers syntax errors and denied commands in plain text, e.g.
        // "error: syntax error, expecting <command>: nonsense" or "error: permission denied"
        if (! str_starts_with(ltrim($output), '<')
            && preg_match_all('/^\s*(?:error|syntax error|permission denied)[^\n]*/mi', $output, $errors) > 0) {
            throw new RpcErrorException($command, array_map('trim', $errors[0]));
        }

        $start = preg_match('/<(\?xml|[A-Za-z_])/', $output, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        $end = strrpos($output, '>');
        if ($start === false || $end === false || $end < $start) {
            throw new ProtocolException(sprintf(
                '%s: device did not return XML (%d bytes: %s)',
                $command,
                strlen($output),
                json_encode(mb_strimwidth(trim($output), 0, 200, '…'))
            ));
        }

        return new self($command, substr($output, $start, $end - $start + 1), $duration);
    }

    public function dom(): DOMDocument
    {
        if ($this->dom === null) {
            $dom = new DOMDocument;
            $dom->preserveWhiteSpace = false;
            $previous = libxml_use_internal_errors(true);
            $ok = $dom->loadXML($this->raw, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT | LIBXML_PARSEHUGE);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (! $ok) {
                $first = $errors[0] ?? null;
                throw new ProtocolException(sprintf(
                    '%s: reply is not well-formed XML%s',
                    $this->command,
                    $first ? sprintf(' (line %d: %s)', $first->line, trim($first->message)) : ''
                ));
            }

            $this->dom = $dom;
        }

        return $this->dom;
    }

    public function xpath(): DOMXPath
    {
        $xpath = new DOMXPath($this->dom());
        $xpath->registerNamespace('nc', self::NS_NETCONF);
        $xpath->registerNamespace('xnm', self::NS_XNM);

        return $xpath;
    }

    /**
     * Error messages contained in the reply (empty when the command succeeded).
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $xpath = $this->xpath();
        $messages = [];

        // Junos: <xnm:error> (namespaced) — also match by local-name for replies without prefix
        foreach ($xpath->query('//*[local-name()="error" and (namespace-uri()="' . self::NS_XNM . '" or namespace-uri()="")]') ?: [] as $error) {
            if ($error instanceof DOMElement) {
                $messages[] = $this->firstText($xpath, $error, ['message', 'error-message']) ?? 'xnm:error without message';
            }
        }

        // NETCONF <rpc-error>
        foreach ($xpath->query('//*[local-name()="rpc-error"]') ?: [] as $error) {
            if (! $error instanceof DOMElement) {
                continue;
            }
            $parts = array_filter([
                $this->firstText($xpath, $error, ['error-message']),
                $this->firstText($xpath, $error, ['error-tag']),
                $this->firstText($xpath, $error, ['error-info/bad-element']),
            ]);
            $messages[] = $parts ? implode(' ', $parts) : 'rpc-error without message';
        }

        return array_values(array_unique(array_map(fn ($m) => trim(preg_replace('/\s+/', ' ', $m) ?? $m), $messages)));
    }

    public function hasError(): bool
    {
        return $this->errors() !== [];
    }

    /**
     * @throws RpcErrorException
     */
    public function assertOk(): self
    {
        $errors = $this->errors();
        if ($errors !== []) {
            throw new RpcErrorException($this->command, $errors);
        }

        return $this;
    }

    /**
     * The first element inside <rpc-reply> that carries data (skips <cli>, <output> banners),
     * or the document element when there is no <rpc-reply> wrapper.
     */
    public function payload(): ?DOMElement
    {
        $root = $this->dom()->documentElement;
        if ($root === null) {
            return null;
        }

        if ($root->localName !== 'rpc-reply') {
            return $root;
        }

        $fallback = null;
        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if (in_array($child->localName, ['cli', 'ok'], true)) {
                continue;
            }
            if ($child->localName === 'output') {
                $fallback ??= $child;
                continue;
            }

            return $child;
        }

        return $fallback;
    }

    /** Text content of the first element matching one of the relative XPaths (namespace agnostic). */
    public function text(string ...$paths): ?string
    {
        $xpath = $this->xpath();
        $context = $this->payload();

        return $context ? $this->firstText($xpath, $context, $paths) : null;
    }

    public function pretty(): string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($this->raw, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok) {
            return $this->raw;
        }

        return (string) $dom->saveXML();
    }

    /**
     * The first step is searched anywhere below the context (so multi-RE wrappers such as
     * multi-routing-engine-results/multi-routing-engine-item are transparent), further steps
     * are direct children.
     *
     * @param  list<string>  $paths  slash separated local names, e.g. "error-info/bad-element"
     */
    private function firstText(DOMXPath $xpath, DOMElement $context, array $paths): ?string
    {
        foreach ($paths as $path) {
            $expr = '.';
            foreach (explode('/', $path) as $i => $step) {
                $expr .= sprintf('%s*[local-name()="%s"]', $i === 0 ? '//' : '/', $step);
            }
            $nodes = $xpath->query($expr, $context);
            if ($nodes !== false && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
        }

        return null;
    }
}
