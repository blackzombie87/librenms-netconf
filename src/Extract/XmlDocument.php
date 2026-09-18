<?php

namespace SafferIt\LibrenmsNetconf\Extract;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Reply;

/**
 * A device reply prepared for extraction: all namespaces removed (so YAML XPaths stay
 * short), the <rpc-reply>/<cli> wrapper skipped, multi-RE wrappers left in place but
 * recognisable via reName().
 */
class XmlDocument
{
    private DOMDocument $dom;

    private DOMXPath $xpath;

    private DOMElement $root;

    public function __construct(string $xml, public readonly string $command = '')
    {
        $source = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $source->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok || $source->documentElement === null) {
            $first = $errors[0] ?? null;
            throw new ProtocolException(sprintf('%s: not well-formed XML%s', $command ?: 'reply', $first ? ' (' . trim($first->message) . ')' : ''));
        }

        $this->dom = new DOMDocument;
        $this->dom->appendChild($this->strip($source->documentElement, $this->dom));
        $this->xpath = new DOMXPath($this->dom);
        $this->root = $this->locateRoot();
    }

    public static function fromReply(Reply $reply): self
    {
        return new self($reply->raw, $reply->command);
    }

    /** The payload element (first data child of rpc-reply, or the document element). */
    public function root(): DOMElement
    {
        return $this->root;
    }

    /**
     * Evaluate a row expression: returns element nodes. Relative expressions are evaluated
     * from the payload root, "//" expressions anywhere in the document.
     *
     * @return list<DOMElement>
     */
    public function rows(string $expression, ?DOMNode $context = null): array
    {
        $result = $this->evaluateRaw($expression, $context ?? $this->root);
        if (! $result instanceof DOMNodeList) {
            throw new ExtractionException(sprintf('rows expression "%s" must select nodes, got %s', $expression, get_debug_type($result)));
        }

        $rows = [];
        foreach ($result as $node) {
            if ($node instanceof DOMElement) {
                $rows[] = $node;
            }
        }

        return $rows;
    }

    /**
     * Evaluate an XPath 1.0 expression to a scalar: node-sets collapse to the text of the
     * first node ('' when empty), NaN becomes null.
     */
    public function scalar(string $expression, ?DOMNode $context = null): string|float|bool|null
    {
        $result = $this->evaluateRaw($expression, $context ?? $this->root);

        if ($result instanceof DOMNodeList) {
            $first = $result->item(0);

            return $first === null ? '' : trim($first->textContent);
        }
        if (is_float($result) && is_nan($result)) {
            return null;
        }
        if (is_string($result)) {
            return trim($result);
        }

        return $result;
    }

    public function bool(string $expression, ?DOMNode $context = null): bool
    {
        $result = $this->evaluateRaw("boolean($expression)", $context ?? $this->root);

        return $result === true;
    }

    /** Routing engine name for rows inside a multi-routing-engine-item wrapper. */
    public function reName(DOMNode $row): ?string
    {
        $result = $this->evaluateRaw('string(ancestor-or-self::multi-routing-engine-item[1]/re-name)', $row);

        return is_string($result) && $result !== '' ? trim($result) : null;
    }

    /**
     * @throws ExtractionException on invalid XPath
     */
    public function evaluateRaw(string $expression, DOMNode $context): mixed
    {
        /** @var list<string> $errors */
        $errors = [];
        set_error_handler(function (int $no, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });
        try {
            $result = $this->xpath->evaluate($expression, $context);
        } finally {
            restore_error_handler();
        }

        if ($result === false && isset($errors[0])) {
            throw new ExtractionException(sprintf('invalid XPath "%s": %s', $expression, preg_replace('/^DOMXPath::evaluate\(\): /', '', $errors[0]) ?? $errors[0]));
        }

        return $result;
    }

    /** Syntax check an expression without a document. */
    public static function validateExpression(string $expression): ?string
    {
        try {
            $probe = new self('<x/>');
            $probe->evaluateRaw($expression, $probe->root());
        } catch (ExtractionException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function locateRoot(): DOMElement
    {
        $doc = $this->dom->documentElement;
        if ($doc === null) {
            throw new ProtocolException('empty document');
        }
        if ($doc->localName !== 'rpc-reply') {
            return $doc;
        }

        $fallback = null;
        foreach ($doc->childNodes as $child) {
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

        return $fallback ?? $doc;
    }

    /**
     * Deep copy without namespaces: elements and attributes keep their local names,
     * xmlns declarations are dropped.
     */
    private function strip(DOMElement $element, DOMDocument $target): DOMElement
    {
        $copy = $target->createElement($element->localName ?? $element->nodeName);

        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attr) {
                if ($attr->prefix === 'xmlns' || $attr->nodeName === 'xmlns') {
                    continue;
                }
                $copy->setAttribute($attr->localName ?? $attr->nodeName, $attr->nodeValue ?? '');
            }
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $copy->appendChild($this->strip($child, $target));
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text = $child->nodeValue ?? '';
                if (trim($text) !== '') {
                    $copy->appendChild($target->createTextNode(trim($text)));
                }
            }
        }

        return $copy;
    }
}
