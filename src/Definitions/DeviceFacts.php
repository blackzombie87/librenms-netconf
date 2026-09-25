<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * The device properties definitions can match on. Plain value object so the matcher is
 * testable without Eloquent; fromDevice() is the LibreNMS glue.
 */
final class DeviceFacts
{
    /**
     * Device attributes whose default comes from a global setting instead of being absent
     * (plan §13.3). A definition keeps matching on `attrib: netconf_evpn_mac`, and the
     * attribute is resolved once, before matching: absent means "inherit the setting", an
     * explicit `1` or `0` on the device always wins. That way the tri-state lives in one
     * place, the YAML is not edited, and a user copy of a definition inherits it for free.
     *
     * @var array<string, string> device attribute => the setting that supplies its default
     */
    public const MANAGED_ATTRIBS = [
        'netconf_evpn_mac' => 'evpn_mac',
        'netconf_queues' => 'queues',
    ];

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

    /**
     * The same facts with every managed attribute resolved against the global settings, so
     * the matcher only ever sees a yes or a no (plan §13.3).
     *
     * @param  array<string, mixed>  $settings  NetconfSettings::effective()
     */
    public function withManagedAttribs(array $settings): self
    {
        $attribs = $this->attribs;
        foreach (self::MANAGED_ATTRIBS as $attrib => $setting) {
            $attribs[$attrib] = self::managed($attrib, $attribs[$attrib] ?? null, $settings) ? '1' : '0';
        }

        return new self($this->os, $this->hardware, $this->version, $this->hostname, $attribs);
    }

    /**
     * Effective value of one managed attribute: the device attribute when it is set, the
     * global setting when it is not.
     *
     * @param  array<string, mixed>  $settings  NetconfSettings::effective()
     */
    public static function managed(string $attrib, ?string $value, array $settings): bool
    {
        if ($value !== null && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) ($settings[self::MANAGED_ATTRIBS[$attrib] ?? ''] ?? false);
    }

    public function attribTruthy(string $name): bool
    {
        $value = $this->attribs[$name] ?? null;

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false && $value !== '';
    }
}
