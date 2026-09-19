<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * The plugin's EVPN fabric tables (prefix netconf_evpn_, plan §7.3) as seen by the YAML
 * engine: which tables a `tables:` mapping may fill, their key columns and column types.
 *
 * Tables are defined by column, not by command, so a second vendor ships its own YAML that
 * fills the same columns. The resolver-owned tables (vtep, fabric, fabric_member,
 * underlay_link) are not writable from YAML.
 */
final class TableSchema
{
    public const PREFIX = 'netconf_evpn_';

    public const TYPE_INT = 'int';

    public const TYPE_STRING = 'string';

    public const TYPE_IP = 'ip';

    public const TYPE_MAC = 'mac';

    public const TYPE_BOOL = 'bool';

    public const TYPE_JSON = 'json';

    public const TYPE_DATETIME = 'datetime';

    /** Column transforms a mapping may apply before the type coercion. */
    public const TRANSFORMS = ['duration', 'evpn_source', 'timestamp'];

    /**
     * Tables a `tables:` mapping may fill: key columns (unique per device) and column types.
     * `first_seen` marks tables whose rows keep an insert-only first_seen column.
     *
     * @var array<string, array{key: list<string>, columns: array<string, string>, first_seen?: bool}>
     */
    public const TABLES = [
        'neighbor' => [
            'key' => ['instance', 'neighbor_ip'],
            'columns' => [
                'instance' => self::TYPE_STRING,
                'neighbor_ip' => self::TYPE_IP,
                'router_id' => self::TYPE_IP,
                'mac_routes' => self::TYPE_INT,
                'mac_ip_routes' => self::TYPE_INT,
                'ead_routes' => self::TYPE_INT,
                'imet_routes' => self::TYPE_INT,
                'es_routes' => self::TYPE_INT,
            ],
        ],
        'esi' => [
            'key' => ['esi'],
            'columns' => [
                'esi' => self::TYPE_STRING,
                'instance' => self::TYPE_STRING,
                'local_ifname' => self::TYPE_STRING,
                'mode' => self::TYPE_STRING,
                'status' => self::TYPE_STRING,
                'lag_status' => self::TYPE_STRING,
                'is_df' => self::TYPE_BOOL,
                'df_ip' => self::TYPE_IP,
                'bdf_ip' => self::TYPE_IP,
                'aliasing' => self::TYPE_BOOL,
                'remote_vtep_ips' => self::TYPE_JSON,
                'remote_mac_count' => self::TYPE_INT,
            ],
        ],
        'vni' => [
            'key' => ['vni'],
            'columns' => [
                'vni' => self::TYPE_INT,
                'instance' => self::TYPE_STRING,
                'vlan_id' => self::TYPE_INT,
                'vlan_name' => self::TYPE_STRING,
                'source_vtep' => self::TYPE_IP,
                'multicast_group' => self::TYPE_IP,
                'rd' => self::TYPE_STRING,
                'rt' => self::TYPE_STRING,
                'interfaces' => self::TYPE_INT,
                'interfaces_up' => self::TYPE_INT,
                'mac_sync' => self::TYPE_BOOL,
                'irb_ifname' => self::TYPE_STRING,
                'irb_status' => self::TYPE_STRING,
                'remote_macs' => self::TYPE_INT,
            ],
        ],
        'vni_vtep' => [
            'key' => ['vni', 'remote_vtep_ip'],
            'columns' => [
                'vni' => self::TYPE_INT,
                'remote_vtep_ip' => self::TYPE_IP,
                'instance' => self::TYPE_STRING,
                'flags' => self::TYPE_STRING,
            ],
        ],
        'tunnel' => [
            'key' => ['remote_vtep_ip'],
            'columns' => [
                'remote_vtep_ip' => self::TYPE_IP,
                'ifname' => self::TYPE_STRING,
                'snmp_index' => self::TYPE_INT,
                'ri_ifname' => self::TYPE_STRING,
                'mode' => self::TYPE_STRING,
                'nh_id' => self::TYPE_INT,
                'mac_count' => self::TYPE_INT,
            ],
        ],
        'mac' => [
            'key' => ['vni', 'mac_address'],
            'first_seen' => true,
            'columns' => [
                'vni' => self::TYPE_INT,
                'mac_address' => self::TYPE_MAC,
                'instance' => self::TYPE_STRING,
                'ip_addresses' => self::TYPE_JSON,
                'source_type' => self::TYPE_STRING,
                'source' => self::TYPE_STRING,
                'active_since' => self::TYPE_DATETIME,
                'seq' => self::TYPE_INT,
                'is_duplicate' => self::TYPE_BOOL,
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function writable(): array
    {
        return array_keys(self::TABLES);
    }

    public static function isWritable(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    /**
     * @return list<string>
     */
    public static function key(string $table): array
    {
        return self::TABLES[$table]['key'] ?? [];
    }

    /**
     * @return array<string, string> column => type
     */
    public static function columns(string $table): array
    {
        return self::TABLES[$table]['columns'] ?? [];
    }

    public static function type(string $table, string $column): ?string
    {
        return self::TABLES[$table]['columns'][$column] ?? null;
    }

    public static function hasFirstSeen(string $table): bool
    {
        return (bool) (self::TABLES[$table]['first_seen'] ?? false);
    }

    /** Database table name for a logical table. */
    public static function tableName(string $table): string
    {
        return self::PREFIX . $table;
    }
}
