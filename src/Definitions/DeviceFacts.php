<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * The device properties definitions can match on. Plain value object so the matcher is
 * testable without Eloquent; fromDevice() is the LibreNMS glue.
 */
final class DeviceFacts
{
    /**
     * @param  array<string, mixed>  $attribs
     */
    public function __construct(
        public readonly ?string $os = null,
        public readonly ?string $hardware = null,
        public readonly ?string $version = null,
        public readonly ?string $hostname = null,
        public readonly array $attribs = [],
    ) {
    }

    public static function fromDevice(\App\Models\Device $device): self
    {
        return new self(
            os: $device->os,
            hardware: $device->hardware,
            version: $device->version,
            hostname: $device->hostname,
            attribs: $device->getAttribs(),
        );
    }

    public function attribTruthy(string $name): bool
    {
        $value = $this->attribs[$name] ?? null;

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false && $value !== '';
    }
}
