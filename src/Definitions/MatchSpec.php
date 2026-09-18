<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * Which devices a definition applies to. Every given rule must match.
 */
final class MatchSpec
{
    public function __construct(
        public readonly ?Pattern $os = null,
        public readonly ?Pattern $hardware = null,
        public readonly ?Pattern $version = null,
        public readonly ?Pattern $hostname = null,
        public readonly ?string $attrib = null,
    ) {
    }

    public function matches(DeviceFacts $facts): bool
    {
        if ($this->os && ! $this->os->matches($facts->os)) {
            return false;
        }
        if ($this->hardware && ! $this->hardware->matches($facts->hardware)) {
            return false;
        }
        if ($this->version && ! $this->version->matches($facts->version)) {
            return false;
        }
        if ($this->hostname && ! $this->hostname->matches($facts->hostname)) {
            return false;
        }
        if ($this->attrib !== null && ! $facts->attribTruthy($this->attrib)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function describe(): array
    {
        return array_filter([
            'os' => (string) $this->os,
            'hardware' => (string) $this->hardware,
            'version' => (string) $this->version,
            'hostname' => (string) $this->hostname,
            'attrib' => (string) $this->attrib,
        ], fn ($v) => $v !== '');
    }
}
