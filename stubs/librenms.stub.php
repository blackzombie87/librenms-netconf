<?php

/*
 * Minimal stubs of LibreNMS core classes for static analysis outside a LibreNMS
 * checkout. Only the members the plugin touches are declared.
 */

namespace App\Models {
    /**
     * @property int $device_id
     * @property string $hostname
     * @property string|null $ip
     * @property string|null $overwrite_ip
     * @property string|null $sysName
     * @property string|null $os
     * @property string|null $hardware
     * @property string|null $version
     * @property int $status
     * @property int $disabled
     * @property int $snmp_disable
     * @property \Illuminate\Database\Eloquent\Collection<int, DeviceAttrib> $attribs
     *
     * @method static Device|null find(int $id)
     * @method static \Illuminate\Database\Eloquent\Builder<Device> where(string $column, mixed $value)
     */
    class Device extends \Illuminate\Database\Eloquent\Model
    {
        public static function findByHostname(string $hostname): ?Device
        {
            return null;
        }

        public static function findByIp(?string $ip): ?Device
        {
            return null;
        }

        public function pollerTarget(): string
        {
            return '';
        }

        public function getAttrib(string $name, mixed $default = null): mixed
        {
            return null;
        }

        public function setAttrib(string $name, mixed $value): bool
        {
            return true;
        }

        public function forgetAttrib(string $name): bool
        {
            return true;
        }

        /** @return array<string, mixed> */
        public function getAttribs(): array
        {
            return [];
        }

        public function displayName(): string
        {
            return '';
        }
    }

    /**
     * @property string $attrib_type
     * @property string $attrib_value
     */
    class DeviceAttrib extends \Illuminate\Database\Eloquent\Model
    {
    }

    /**
     * @property int $plugin_id
     * @property string $plugin_name
     * @property bool $plugin_active
     * @property int $version
     * @property array<string, mixed>|null $settings
     *
     * @method static void saving(callable $callback)
     */
    class Plugin extends \Illuminate\Database\Eloquent\Model
    {
    }
}

namespace App\Facades {
    class LibrenmsConfig
    {
        public static function get(string $key, mixed $default = null): mixed
        {
            return null;
        }

        public static function set(string $key, mixed $value): void
        {
        }

        public static function persist(string $key, mixed $value): bool
        {
            return true;
        }

        public static function has(string $key): bool
        {
            return false;
        }
    }
}
