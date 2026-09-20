<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use SafferIt\LibrenmsNetconf\Support\Uninstaller;

/**
 * lnms netconf:uninstall — list what the plugin stores in LibreNMS and, with --purge,
 * remove it. Registered even while the plugin is disabled so it can run right before
 * `lnms plugin:remove saffer-it/librenms-netconf`.
 */
class NetconfUninstallCommand extends Command
{
    protected $signature = 'netconf:uninstall
        {--purge : Delete everything listed (without it the command only reports)}
        {--keep-rrd : Keep the RRD files (history) when purging}
        {--force : Purge without asking for confirmation}';

    protected $description = 'Show or remove everything the netconf plugin stores in LibreNMS (sensors, tables, RRDs, device attributes, module config)';

    public function handle(Uninstaller $uninstaller): int
    {
        $inventory = $uninstaller->inventory();

        $this->table(['Item', 'Count / value'], [
            ['sensors (poller_type = netconf)', (string) $inventory['sensors']],
            ['netconf_metrics rows', (string) $inventory['metrics']],
            ['netconf_port_metrics rows', (string) $inventory['port_metrics']],
            ['netconf_device_status rows', (string) $inventory['status']],
            ['netconf_evpn_* rows', implode(', ', array_map(fn ($table, $rows) => substr($table, strlen('netconf_evpn_')) . ' ' . $rows, array_keys($evpn = array_filter($inventory['table_rows'], fn ($t) => str_starts_with($t, 'netconf_evpn_'), ARRAY_FILTER_USE_KEY)), $evpn)) ?: '(no tables)'],
            ['device attributes netconf_*', (string) $inventory['attribs']],
            ['links rows (protocol evpn-esi)', (string) $inventory['links']],
            ['RRD files (sensor-*-netconf-*, netconf-*)', (string) count($inventory['rrd_files'])],
            ['devices with plugin data', implode(', ', $inventory['devices']) ?: '(none)'],
            ['module config keys', implode(', ', $inventory['config_keys']) ?: '(none)'],
            ['tables', implode(', ', $inventory['tables']) ?: '(none)'],
            ['migration rows', (string) $inventory['migrations']],
        ]);

        if ($inventory['alert_rules'] !== []) {
            $this->warn('Alert rules that reference the plugin and will break once the tables are gone (delete them yourself): ' . implode(', ', $inventory['alert_rules']));
        }

        if (! $this->option('purge')) {
            $this->line('Nothing was changed. Run <comment>lnms netconf:uninstall --purge</comment> to delete all of the above (<comment>--keep-rrd</comment> keeps the files), then <comment>lnms plugin:remove saffer-it/librenms-netconf</comment>.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Refusing to purge without confirmation; add --force when running non-interactively.');

                return self::FAILURE;
            }
            if (! $this->confirm('Delete all plugin data listed above' . ($this->option('keep-rrd') ? ' (RRD files are kept)' : ', including the RRD files') . '?')) {
                $this->line('Aborted, nothing was changed.');

                return self::FAILURE;
            }
        }

        foreach ($uninstaller->purge((bool) $this->option('keep-rrd')) as $line) {
            $this->line('  ' . $line);
        }

        $this->info('Done.');
        $this->line('Now remove the package: <comment>lnms plugin:remove saffer-it/librenms-netconf</comment>. LibreNMS drops the row in the plugins table (settings, encrypted credentials) once the package is gone.');
        $this->line('Left untouched: the SSH key and known_hosts files, the user definitions directory, alert rules. To start over instead, run <comment>lnms plugin:enable netconf</comment> and <comment>lnms migrate</comment>.');

        return self::SUCCESS;
    }
}
