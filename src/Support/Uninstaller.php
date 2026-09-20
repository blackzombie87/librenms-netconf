<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SafferIt\LibrenmsNetconf\Collect\SensorWriter;
use SafferIt\LibrenmsNetconf\Fabric\EsiLinks;
use SafferIt\LibrenmsNetconf\NetconfSettings;

/**
 * Everything the plugin leaves in a LibreNMS installation and how to remove it.
 *
 * `lnms plugin:remove` only edits the composer files. The sensors, the plugin tables, the
 * RRD files, the device attributes and the module config keys stay behind; this class
 * lists them (inventory) and deletes them in an order that a concurrently running poller
 * cannot undo: plugin disabled and module keys erased first, rows and files afterwards,
 * tables and migration rows last.
 */
class Uninstaller
{
    public const TABLES = [
        'netconf_evpn_underlay_link', 'netconf_evpn_fabric_member', 'netconf_evpn_fabric', 'netconf_evpn_vtep', 'netconf_evpn_mac',
        'netconf_evpn_tunnel', 'netconf_evpn_vni_vtep', 'netconf_evpn_vni', 'netconf_evpn_esi', 'netconf_evpn_neighbor',
        'netconf_port_metrics', 'netconf_metrics', 'netconf_device_status',
    ];

    /**
     * Tables that reference a device, with the column holding its id (everything except the
     * fabric-level ones; vtep.device_id is nullable, underlay_link keys the A end).
     */
    public const DEVICE_TABLES = [
        'netconf_evpn_underlay_link' => 'a_device_id', 'netconf_evpn_vtep' => 'device_id',
        'netconf_evpn_mac' => 'device_id', 'netconf_evpn_tunnel' => 'device_id', 'netconf_evpn_vni_vtep' => 'device_id', 'netconf_evpn_vni' => 'device_id',
        'netconf_evpn_esi' => 'device_id', 'netconf_evpn_neighbor' => 'device_id',
        'netconf_port_metrics' => 'device_id', 'netconf_metrics' => 'device_id', 'netconf_device_status' => 'device_id',
    ];

    public const CONFIG_KEYS = ['poller_modules.netconf', 'discovery_modules.netconf'];

    /** Device attributes: netconf_enabled, netconf_queues and the credential overrides. */
    public const ATTRIB_PATTERN = 'netconf\_%';

    /**
     * Migration names as recorded in the migrations table (file names without .php).
     *
     * @return list<string>
     */
    public static function migrationNames(): array
    {
        $files = glob(__DIR__ . '/../../database/migrations/*.php') ?: [];
        $names = array_map(fn (string $file) => basename($file, '.php'), $files);
        sort($names);

        return $names;
    }

    /** Sensor RRD written for a netconf sensor: sensor-<class>-netconf-<definition>-….rrd */
    public static function isSensorRrd(string $file): bool
    {
        return preg_match('/^sensor-[A-Za-z0-9_]+-netconf-/', basename($file)) === 1;
    }

    /** Metric or port metric RRD: netconf-<definition>-… or netconf-port-<port_id>-….rrd */
    public static function isMetricRrd(string $file): bool
    {
        return str_starts_with(basename($file), 'netconf-');
    }

    /**
     * Local path of a file returned by Rrd::getRrdFiles(). Without rrdcached that is an
     * absolute path; with rrdcached, `rrdtool list` prints names relative to the daemon's
     * base directory (bare file name or /<host>/<file>), so the candidates are tried in
     * order and the host-directory path is returned when none exists (for the report).
     */
    public static function localRrdPath(string $file, string $hostDir): string
    {
        $inHostDir = rtrim($hostDir, '/') . '/' . basename($file);
        foreach ([$file, rtrim($hostDir, '/') . '/' . ltrim($file, '/'), $inHostDir] as $candidate) {
            if (str_starts_with($candidate, '/') && is_file($candidate)) {
                return $candidate;
            }
        }

        return $inHostDir;
    }

    /**
     * What is currently stored, without changing anything.
     *
     * @return array{
     *     sensors: int, metrics: int, port_metrics: int, status: int, attribs: int, links: int,
     *     config_keys: list<string>, tables: list<string>, table_rows: array<string, int>, migrations: int,
     *     devices: array<int, string>, rrd_files: list<string>, alert_rules: list<string>
     * }
     */
    public function inventory(): array
    {
        $tables = array_values(array_filter(self::TABLES, fn (string $table) => Schema::hasTable($table)));

        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = in_array($table, $tables, true) ? DB::table($table)->count() : 0;
        }

        $devices = $this->devices($tables);
        $rrd = [];
        foreach ($devices as $hostname) {
            $rrd = array_merge($rrd, $this->rrdFiles($hostname));
        }

        return [
            'sensors' => DB::table('sensors')->where('poller_type', SensorWriter::POLLER_TYPE)->count(),
            'metrics' => $counts['netconf_metrics'],
            'port_metrics' => $counts['netconf_port_metrics'],
            'status' => $counts['netconf_device_status'],
            'attribs' => DB::table('devices_attribs')->where('attrib_type', 'like', self::ATTRIB_PATTERN)->count(),
            'links' => DB::table('links')->where('protocol', EsiLinks::PROTOCOL)->count(),
            'config_keys' => array_values(array_filter(self::CONFIG_KEYS, fn (string $key) => LibrenmsConfig::has($key))),
            'tables' => $tables,
            'table_rows' => array_intersect_key($counts, array_flip($tables)),
            'migrations' => DB::table('migrations')->whereIn('migration', self::migrationNames())->count(),
            'devices' => $devices,
            'rrd_files' => $rrd,
            'alert_rules' => $this->alertRules(),
        ];
    }

    /**
     * Delete everything. Returns what was done, in order, for the caller to print.
     *
     * @return list<string>
     */
    public function purge(bool $keepRrd = false): array
    {
        $log = [];

        // 1. stop the plugin from re-registering the module keys at the next boot
        $disabled = DB::table('plugins')->where('plugin_name', NetconfSettings::PLUGIN_NAME)->where('version', 2)->update(['plugin_active' => 0]);
        $log[] = $disabled ? 'plugin netconf disabled' : 'plugin netconf already disabled or not registered';

        // 2. unschedule the module
        foreach (self::CONFIG_KEYS as $key) {
            if (LibrenmsConfig::has($key)) {
                LibrenmsConfig::erase($key);
                $log[] = "config key $key removed";
            }
        }

        // 3. per-device data: files first (they are named after rows we are about to delete)
        $tables = array_values(array_filter(self::TABLES, fn (string $table) => Schema::hasTable($table)));
        $devices = $this->devices($tables);
        $files = 0;
        $missing = [];
        foreach ($devices as $hostname) {
            if ($keepRrd) {
                continue;
            }
            foreach ($this->rrdFiles($hostname) as $file) {
                if (is_file($file) && @unlink($file)) {
                    $files++;
                } else {
                    $missing[] = $file;
                }
            }
        }
        if (! $keepRrd) {
            $log[] = sprintf('%d RRD files deleted on %d devices', $files, count($devices));
            if ($missing !== []) {
                $log[] = 'not deleted (not a local file, remove on the rrdcached host): ' . implode(', ', $missing);
            }
        } else {
            $log[] = 'RRD files kept (--keep-rrd)';
        }

        $sensors = DB::table('sensors')->where('poller_type', SensorWriter::POLLER_TYPE)->delete();
        $log[] = "$sensors sensors deleted";
        $links = DB::table('links')->where('protocol', EsiLinks::PROTOCOL)->delete();
        $log[] = "$links evpn-esi rows deleted from links";
        foreach ($tables as $table) {
            $rows = DB::table($table)->delete();
            $log[] = "$rows rows deleted from $table";
        }

        // 4. device attributes (netconf_enabled, credential overrides, opt-ins)
        $attribs = DB::table('devices_attribs')->where('attrib_type', 'like', self::ATTRIB_PATTERN)->delete();
        $log[] = "$attribs device attributes deleted";

        // 5. schema; the migration rows go too so a later install runs the migrations again
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
            $log[] = "table $table dropped";
        }
        $migrations = DB::table('migrations')->whereIn('migration', self::migrationNames())->delete();
        $log[] = "$migrations migration rows deleted";

        return $log;
    }

    /**
     * Devices that have any plugin data: sensors, rows in the plugin tables or attributes.
     *
     * @param  list<string>  $tables  plugin tables that exist
     * @return array<int, string> device_id => hostname
     */
    private function devices(array $tables): array
    {
        $ids = DB::table('sensors')->where('poller_type', SensorWriter::POLLER_TYPE)->distinct()->pluck('device_id')->all();
        foreach ($tables as $table) {
            $column = self::DEVICE_TABLES[$table] ?? null;
            if ($column !== null) {
                $ids = array_merge($ids, DB::table($table)->whereNotNull($column)->distinct()->pluck($column)->all());
            }
        }
        $ids = array_merge($ids, DB::table('devices_attribs')->where('attrib_type', 'like', self::ATTRIB_PATTERN)->distinct()->pluck('device_id')->all());
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $devices = [];
        foreach (Device::query()->whereIn('device_id', $ids)->orderBy('hostname')->get(['device_id', 'hostname']) as $device) {
            $devices[$device->device_id] = $device->hostname;
        }

        return $devices;
    }

    /**
     * Plugin RRD files of one device as local absolute paths (see localRrdPath()).
     *
     * @return list<string>
     */
    private function rrdFiles(string $hostname): array
    {
        $files = array_merge(
            array_filter(Rrd::getRrdFiles($hostname, 'netconf-'), self::isMetricRrd(...)),
            array_filter(Rrd::getRrdFiles($hostname, 'sensor-'), self::isSensorRrd(...)),
        );
        $dir = Rrd::dirFromHost($hostname);

        return array_values(array_unique(array_map(fn (string $file) => self::localRrdPath($file, $dir), $files)));
    }

    /**
     * Alert rules that mention the plugin tables or sensors; they break once the tables
     * are dropped and are left for the admin to delete.
     *
     * @return list<string>
     */
    private function alertRules(): array
    {
        if (! Schema::hasTable('alert_rules')) {
            return [];
        }

        return DB::table('alert_rules')
            ->where('builder', 'like', '%netconf%')
            ->orWhere('query', 'like', '%netconf%')
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
