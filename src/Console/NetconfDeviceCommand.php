<?php

namespace SafferIt\LibrenmsNetconf\Console;

use App\Models\Device;
use Illuminate\Console\Command;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Console\Concerns\ResolvesTarget;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Transport\CredentialResolver;

/**
 * lnms netconf:device <device> — enable/disable NETCONF polling for a device and manage its
 * credential overrides (stored as device attributes, secrets encrypted).
 */
class NetconfDeviceCommand extends Command
{
    use ResolvesTarget;

    protected $signature = 'netconf:device
        {device : Hostname, IP, sysName or device_id}
        {--enable : Enable NETCONF polling for this device}
        {--disable : Disable NETCONF polling for this device}
        {--set-username= : Store a per-device login user}
        {--set-password= : Store a per-device password (encrypted)}
        {--set-ask-password : Prompt for the per-device password}
        {--set-key= : Store a per-device private key file path}
        {--set-passphrase= : Store the key passphrase (encrypted)}
        {--set-port= : Store a per-device SSH port}
        {--set-transport= : Store a per-device transport (cli|netconf)}
        {--clear=* : Remove overrides: username, password, keyfile, key_passphrase, port, transport, all}
        {--status : Show status and matched definitions only}';

    protected $description = 'Enable NETCONF polling for a device and manage its credential overrides';

    public function handle(NetconfService $service): int
    {
        $device = $this->findDevice((string) $this->argument('device'));
        if (! $device) {
            $this->error($this->argument('device') . ' is not a LibreNMS device');

            return self::FAILURE;
        }
        $this->device = $device;

        $changed = $this->applyChanges($device);

        if ($changed) {
            $device->save();
            $this->info('Saved.');
        }

        $this->show($device, $service);

        if ($this->option('enable')) {
            $this->line(sprintf('Run <comment>lnms device:discover %s -m netconf</comment> to create the sensors, then <comment>lnms device:poll %s -m netconf</comment>.', $device->hostname, $device->hostname));
        }

        return self::SUCCESS;
    }

    private function applyChanges(Device $device): bool
    {
        $input = [
            'enabled' => $this->option('enable') ? '1' : ($this->option('disable') ? '0' : null),
            'username' => $this->option('set-username'),
            'password' => $this->option('set-password'),
            'keyfile' => $this->option('set-key'),
            'key_passphrase' => $this->option('set-passphrase'),
            'port' => $this->option('set-port'),
            'transport' => $this->option('set-transport'),
            'clear' => (array) $this->option('clear'),
        ];
        if ($this->option('set-ask-password')) {
            $input['password'] = (string) $this->secret('Password for this device');
        }
        foreach ($input['clear'] as $suffix) {
            if ($suffix !== 'all' && ! in_array($suffix, DeviceSettings::FIELDS, true)) {
                $this->error("unknown override \"$suffix\" (known: " . implode(', ', DeviceSettings::FIELDS) . ')');

                return false;
            }
        }

        try {
            $changes = DeviceSettings::apply($device, $input);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return false;
        }
        foreach ($changes as $change) {
            $this->line('  ' . $change);
        }

        return $changes !== [];
    }

    private function show(Device $device, NetconfService $service): void
    {
        $attribs = $device->getAttribs();
        $rows = [['netconf_enabled', $attribs[NetconfService::ATTRIB_ENABLED] ?? '(unset, global default ' . ($service->isEnabled($device) ? 'on' : 'off') . ')']];
        foreach (DeviceSettings::current($device) as $suffix => $value) {
            $rows[] = [CredentialResolver::ATTRIB_PREFIX . $suffix, $value];
        }
        $this->table(['Device attribute', 'Value'], $rows);

        $credentials = app(\SafferIt\LibrenmsNetconf\Transport\DeviceCredentials::class)->forDevice($device);
        $this->table(['Effective', 'Value'], array_map(fn ($k, $v) => [$k, (string) $v], array_keys($credentials->describe()), $credentials->describe()));

        $definitions = array_map(fn ($d) => $d->name, $service->matchingDefinitions($device));
        $this->line('<info>Matching definitions:</info> ' . (implode(', ', $definitions) ?: '(none)'));

        $reason = $service->skipReason($device, true);
        $this->line('<info>Poll status:</info> ' . ($reason ?? 'would poll'));

        $status = $service->status($device);
        if ($status->exists) {
            $this->table(['Status', 'Value'], [
                ['transport', $status->transport ?? ''],
                ['poll_count', (string) $status->poll_count],
                ['last_ok', (string) $status->last_ok],
                ['last_attempt', (string) $status->last_attempt],
                ['consecutive_failures', (string) $status->consecutive_failures],
                ['next_attempt', (string) $status->next_attempt],
                ['last_error', (string) $status->last_error],
                ['last_duration', $status->last_duration === null ? '' : sprintf('%.2fs', $status->last_duration)],
                ['last_summary', json_encode($status->last_summary)],
            ]);
        }
    }
}
