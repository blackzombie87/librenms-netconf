<?php

namespace SafferIt\LibrenmsNetconf\Console\Concerns;

use App\Models\Device;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Credentials;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

/**
 * Shared option handling for the netconf:* commands: device lookup and credential overrides.
 *
 * @mixin \Illuminate\Console\Command
 */
trait ResolvesTarget
{
    protected const TARGET_OPTIONS = '
        {--u|username= : Override the login user}
        {--p|password= : Override the password (prefer --ask-password)}
        {--P|ask-password : Prompt for the password}
        {--k|key= : Override the private key file}
        {--passphrase= : Passphrase for the private key}
        {--port= : Override the SSH port}
        {--t|transport= : cli or netconf}
        {--auth-order= : key,password or password,key}
        {--connect-timeout= : Seconds for TCP/SSH setup}
        {--timeout= : Seconds to wait for each command}';

    protected ?Device $device = null;

    /**
     * Resolve the "device" argument to a LibreNMS device (id, hostname, sysName or IP) or,
     * when unknown, treat it as a bare host using the global settings.
     */
    protected function resolveCredentials(): Credentials
    {
        $target = (string) $this->argument('device');
        $this->device = $this->findDevice($target);

        $overrides = [
            'username' => $this->option('username'),
            'password' => $this->option('password'),
            'key_file' => $this->option('key'),
            'key_passphrase' => $this->option('passphrase'),
            'port' => $this->option('port'),
            'transport' => $this->option('transport'),
            'auth_order' => $this->option('auth-order'),
            'connect_timeout' => $this->option('connect-timeout'),
            'command_timeout' => $this->option('timeout'),
        ];

        if ($this->option('ask-password')) {
            $overrides['password'] = (string) $this->secret('Password');
        }

        $overrides = array_filter($overrides, fn ($v) => $v !== null && $v !== '');

        /** @var DeviceCredentials $resolver */
        $resolver = app(DeviceCredentials::class);

        if ($this->device) {
            return $resolver->forDevice($this->device, $overrides);
        }

        $this->warn("$target is not a LibreNMS device, using global settings for the bare host");

        return $resolver->forHost($target, $overrides);
    }

    protected function makeTransport(Credentials $credentials): TransportInterface
    {
        /** @var TransportFactory $factory */
        $factory = app(TransportFactory::class);

        return $factory->make($credentials);
    }

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
