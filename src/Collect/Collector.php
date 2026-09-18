<?php

namespace SafferIt\LibrenmsNetconf\Collect;

use SafferIt\LibrenmsNetconf\Definitions\CommandSpec;
use SafferIt\LibrenmsNetconf\Definitions\Definition;
use SafferIt\LibrenmsNetconf\Extract\ExtractionException;
use SafferIt\LibrenmsNetconf\Extract\Extractor;
use SafferIt\LibrenmsNetconf\Extract\XmlDocument;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ProtocolException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;

/**
 * Runs every command the matched definitions need exactly once over one transport
 * session, then hands the parsed replies to the Extractor. Pure PHP: the transport can be
 * a FakeTransport fed with fixtures.
 */
class Collector
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Extractor $extractor = new Extractor,
    ) {
    }

    /**
     * @param  list<Definition>  $definitions  already matched to the device
     * @param  int  $pollNumber  running poll counter for `every:`; 0 forces every command (discovery)
     * @param  float|null  $budget  wall-clock seconds; remaining commands are skipped when exceeded
     */
    public function collect(array $definitions, int $pollNumber = 0, ?float $budget = null): CollectionResult
    {
        $start = microtime(true);
        $result = new CollectionResult;
        $this->extractor->resetWarnings();

        // 1. unique commands across definitions
        /** @var array<string, CommandSpec> $specs */
        $specs = [];
        foreach ($definitions as $definition) {
            foreach ($definition->commands as $spec) {
                $specs[$spec->identity()] ??= $spec;
                if (! $spec->optional && $specs[$spec->identity()]->optional) {
                    $specs[$spec->identity()] = $spec; // a required use wins over an optional one
                }
            }
        }

        // 2. run them
        foreach ($specs as $identity => $spec) {
            if ($pollNumber > 0 && ! $spec->dueAt($pollNumber)) {
                $result->commands[$identity] = new CommandRun($identity, $spec->label(), CommandRun::SKIPPED, message: "not due (every {$spec->every})");
                continue;
            }
            if ($result->aborted) {
                $result->commands[$identity] = new CommandRun($identity, $spec->label(), CommandRun::SKIPPED, message: 'session aborted');
                continue;
            }
            if ($budget !== null && microtime(true) - $start > $budget) {
                $result->errors[] = sprintf('poll budget of %.0fs exceeded, remaining commands skipped', $budget);
                $result->aborted = true;
                $result->commands[$identity] = new CommandRun($identity, $spec->label(), CommandRun::SKIPPED, message: 'budget exceeded');
                continue;
            }

            $result->commands[$identity] = $this->run($spec, $identity, $result);
        }

        // 3. extract
        foreach ($definitions as $definition) {
            $result->definitions[$definition->name] = $this->extract($definition, $result);
        }

        $result->duration = microtime(true) - $start;

        return $result;
    }

    private function run(CommandSpec $spec, string $identity, CollectionResult $result): CommandRun
    {
        $t = microtime(true);
        try {
            $reply = $spec->isRpc() ? $this->transport->rpc((string) $spec->rpc) : $this->transport->run((string) $spec->cli);
            $document = XmlDocument::fromReply($reply);

            // Junos answers "daemon not running" style conditions with plain text (<output>) or an
            // <xnm:warning> instead of data; treat that like a failed command
            $unavailable = self::unavailableMessage($document);
            if ($unavailable !== null) {
                $status = $spec->optional ? CommandRun::SKIPPED : CommandRun::ERROR;

                return new CommandRun($identity, $spec->label(), $status, microtime(true) - $t, strlen($reply->raw), message: $unavailable);
            }

            return new CommandRun($identity, $spec->label(), CommandRun::OK, microtime(true) - $t, strlen($reply->raw), $document);
        } catch (RpcErrorException|ProtocolException $e) {
            // the device answered, but not usefully: only this command is affected
            $status = $spec->optional ? CommandRun::SKIPPED : CommandRun::ERROR;

            return new CommandRun($identity, $spec->label(), $status, microtime(true) - $t, message: $e->getMessage());
        } catch (TransportException $e) {
            // connection level problem: nothing else will work in this session
            $result->errors[] = $e->getMessage();
            $result->aborted = true;

            return new CommandRun($identity, $spec->label(), CommandRun::ERROR, microtime(true) - $t, message: $e->getMessage());
        }
    }

    /**
     * Text of a reply that carries no XML data: <output>text</output> (e.g. "LDP instance is
     * not running") or <warning><message>…</message></warning> ("vrrp subsystem not running").
     */
    public static function unavailableMessage(XmlDocument $document): ?string
    {
        $root = $document->root();
        if ($root->localName === 'output') {
            $text = trim(preg_replace('/\s+/', ' ', $root->textContent) ?? $root->textContent);

            return $text !== '' ? $text : 'empty text output';
        }
        if ($root->localName === 'warning') {
            $message = $document->scalar('normalize-space(message)', $root);

            return is_string($message) && $message !== '' ? $message : 'warning without message';
        }

        return null;
    }

    private function extract(Definition $definition, CollectionResult $result): DefinitionResult
    {
        $out = new DefinitionResult($definition);
        $this->extractor->resetWarnings();

        $document = function (string $commandKey, string $mappingId) use ($definition, $result, $out): ?XmlDocument {
            $run = $result->commands[$definition->command($commandKey)->identity()] ?? null;
            if ($run === null || $run->document === null) {
                $out->skippedMappings[] = $mappingId;

                return null;
            }

            return $run->document;
        };

        foreach ($definition->sensors as $mapping) {
            if ($doc = $document($mapping->command, $mapping->id)) {
                try {
                    array_push($out->sensors, ...$this->extractor->sensors($definition, $mapping, $doc));
                } catch (ExtractionException $e) {
                    $out->warnings[] = "$definition->name/{$mapping->id}: " . $e->getMessage();
                }
            }
        }
        foreach ($definition->ports as $mapping) {
            if ($doc = $document($mapping->command, $mapping->id)) {
                try {
                    array_push($out->ports, ...$this->extractor->ports($definition, $mapping, $doc));
                } catch (ExtractionException $e) {
                    $out->warnings[] = "$definition->name/{$mapping->id}: " . $e->getMessage();
                }
            }
        }
        foreach ($definition->metrics as $mapping) {
            if ($doc = $document($mapping->command, $mapping->id)) {
                try {
                    array_push($out->metrics, ...$this->extractor->metrics($definition, $mapping, $doc));
                } catch (ExtractionException $e) {
                    $out->warnings[] = "$definition->name/{$mapping->id}: " . $e->getMessage();
                }
            }
        }

        array_push($out->warnings, ...$this->extractor->warnings());

        return $out;
    }
}
