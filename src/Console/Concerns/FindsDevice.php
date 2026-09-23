<?php

namespace SafferIt\LibrenmsNetconf\Console\Concerns;

use App\Models\Device;

/**
 * Turning the "device" argument of a netconf:* command into a LibreNMS device. Separate from
 * ResolvesTarget because netconf:device manages stored per-device settings and has none of
 * the credential-override options that reading a device live needs.
 *
 * @mixin \Illuminate\Console\Command
 */
trait FindsDevice
{
    protected ?Device $device = null;

    protected function findDevice(string $target): ?Device
    {
        try {
            if (ctype_digit($target)) {
                $device = Device::find((int) $target);
                if ($device) {
                    return $device;
                }
            }

            return Device::findByHostname($target)
                ?? Device::where('sysName', $target)->first()
                ?? Device::findByIp($target);
        } catch (\Throwable $e) {
            $this->warn('Device lookup failed: ' . $e->getMessage());

            return null;
        }
    }

    protected function deviceLabel(): string
    {
        if ($this->device) {
            return sprintf('%s (device_id %d, %s)', $this->device->hostname, $this->device->device_id, $this->device->os ?? 'unknown os');
        }

        return (string) $this->argument('device');
    }
}
