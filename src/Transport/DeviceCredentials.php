<?php

namespace SafferIt\LibrenmsNetconf\Transport;

use App\Models\Device;
use SafferIt\LibrenmsNetconf\NetconfSettings;
use SafferIt\LibrenmsNetconf\Support\SettingsSecrets;

/**
 * LibreNMS glue for CredentialResolver: global plugin settings + device attribs.
 */
class DeviceCredentials
{
    private CredentialResolver $resolver;

    public function __construct(?CredentialResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new CredentialResolver(SettingsSecrets::decrypt(...));
    }

    /**
     * @param  array<string, mixed>  $overrides  ad-hoc values (CLI options), highest priority
     */
    public function forDevice(Device $device, array $overrides = []): Credentials
    {
        return $this->resolver->resolve(
            $device->pollerTarget(),
            NetconfSettings::effective(),
            $device->getAttribs(),
            $overrides
        );
    }

    /**
     * A host that is not (yet) a LibreNMS device: global settings only.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function forHost(string $host, array $overrides = []): Credentials
    {
        return $this->resolver->resolve($host, NetconfSettings::effective(), [], $overrides);
    }
}
