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
     * @property int|null $location_id
     * @property int $disabled
     * @property int $snmp_disable
     * @property \Illuminate\Database\Eloquent\Collection<int, DeviceAttrib> $attribs
     *
     * @method static Device|null find(int $id)
     * @method static \Illuminate\Database\Eloquent\Builder<Device> where(string $column, mixed $value)
     * @method static \Illuminate\Database\Eloquent\Builder<Device> hasAccess(User $user)
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

        /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Sensor, $this> */
        public function sensors(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
            return $this->hasMany(Sensor::class);
        }

        /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Port, $this> */
        public function ports(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
            return $this->hasMany(Port::class);
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

        public static function erase(string $key): bool
        {
            return true;
        }

        public static function getOsSetting(?string $os, string $key, mixed $default = null): mixed
        {
            return null;
        }
    }
}

namespace App\Models {
    /**
     * @property int $sensor_id
     * @property string $poller_type
     * @property string $sensor_class
     * @property int $device_id
     * @property string $sensor_oid
     * @property string $sensor_index
     * @property string $sensor_type
     * @property string|null $sensor_descr
     * @property float|null $sensor_current
     * @property float|null $sensor_prev
     * @property float|null $sensor_limit
     * @property float|null $sensor_limit_warn
     * @property float|null $sensor_limit_low
     * @property float|null $sensor_limit_low_warn
     * @property int $sensor_alert
     * @property string $sensor_custom
     * @property string|null $group
     * @property string $rrd_type
     * @property \Carbon\CarbonInterface|null $lastupdate
     */
    class Sensor extends \Illuminate\Database\Eloquent\Model
    {
        public function currentTranslation(): ?StateTranslation
        {
            return null;
        }

        public function formatValue(string $field = 'sensor_current'): string
        {
            return '';
        }
    }

    /**
     * @property int $user_id
     * @property string $username
     */
    class User extends \Illuminate\Database\Eloquent\Model
    {
        public function can(string $ability, mixed $arguments = []): bool
        {
            return false;
        }
    }

    /**
     * @property int $port_id
     * @property int $device_id
     * @property int $ifIndex
     * @property string|null $ifName
     * @property string|null $ifDescr
     * @property string|null $ifAlias
     * @property int $deleted
     * @property Device $device
     *
     * @method static \Illuminate\Database\Eloquent\Builder<Port> where(string $column, mixed $operator = null, mixed $value = null)
     */
    class Port extends \Illuminate\Database\Eloquent\Model
    {
    }

    /**
     * @property int $state_index_id
     * @property string $state_descr
     * @property int $state_value
     * @property int $state_generic_value
     */
    class StateTranslation extends \Illuminate\Database\Eloquent\Model
    {
        public static function define(string $descr, int $value, \LibreNMS\Enum\Severity $severity): self
        {
            return new self;
        }
    }

    class Eventlog extends \Illuminate\Database\Eloquent\Model
    {
        public static function log(string $text, Device|int|null $device = null, ?string $type = null, \LibreNMS\Enum\Severity $severity = \LibreNMS\Enum\Severity::Info, int|string|null $reference = null): void
        {
        }
    }
}

namespace App\Discovery {
    class Sensor
    {
        public function __construct(private \App\Models\Device $device)
        {
        }

        public function discover(\App\Models\Sensor $sensor): static
        {
            return $this;
        }

        /**
         * @param  array<int, \App\Models\StateTranslation>|\Illuminate\Support\Collection<int, \App\Models\StateTranslation>  $states
         */
        public function withStateTranslations(string $stateName, array|\Illuminate\Support\Collection $states): static
        {
            return $this;
        }

        /**
         * @return \Illuminate\Support\Collection<int, \App\Models\Sensor>
         */
        public function sync(mixed ...$params): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection;
        }
    }
}

namespace LibreNMS\Enum {
    enum Severity: int
    {
        case Unknown = 0;
        case Ok = 1;
        case Info = 2;
        case Notice = 3;
        case Warning = 4;
        case Error = 5;
    }

    enum Sensor: string
    {
        case Count = 'count';
        case State = 'state';

        public function unit(): string
        {
            return '';
        }
    }
}

namespace LibreNMS\Interfaces\Data {
    interface DataStorageInterface
    {
        /**
         * @param  array<string, mixed>|\App\Models\Device  $device
         * @param  array<string, mixed>  $tags
         * @param  array<string, mixed>|int|float  $fields
         */
        public function put($device, string $measurement, array $tags, $fields): void;
    }
}

namespace LibreNMS\Interfaces {
    interface Module
    {
        /** @return list<string> */
        public function dependencies(): array;

        public function shouldDiscover(\LibreNMS\OS $os, \LibreNMS\Polling\ModuleStatus $status, \LibreNMS\Polling\ConnectivityHelper $connectivity): bool;

        public function shouldPoll(\LibreNMS\OS $os, \LibreNMS\Polling\ModuleStatus $status, \LibreNMS\Polling\ConnectivityHelper $connectivity): bool;

        public function discover(\LibreNMS\OS $os): void;

        public function poll(\LibreNMS\OS $os, \LibreNMS\Interfaces\Data\DataStorageInterface $datastore): void;

        public function dataExists(\App\Models\Device $device): bool;

        public function cleanup(\App\Models\Device $device): int;

        /** @return array<string, mixed>|null */
        public function dump(\App\Models\Device $device, string $type): ?array;
    }
}

namespace LibreNMS {
    class OS
    {
        public function getDevice(): \App\Models\Device
        {
            return new \App\Models\Device;
        }

        public function getDeviceId(): int
        {
            return 0;
        }
    }
}

namespace LibreNMS\Polling {
    class ModuleStatus
    {
        public function isEnabled(): bool
        {
            return true;
        }
    }

    class ConnectivityHelper
    {
        public function isAvailable(): bool
        {
            return true;
        }
    }
}

namespace LibreNMS\RRD {
    class RrdDefinition
    {
        public static function make(): self
        {
            return new self;
        }

        public function addDataset(string $name, string $type, int|float|null $min = null, int|float|null $max = null, ?int $heartbeat = null): self
        {
            return $this;
        }
    }
}

namespace App\Facades {
    class Rrd
    {
        /** @param  string|array<int, string|int>  $extra */
        public static function name(string $host, string|array $extra): string
        {
            return '';
        }

        /** @param  list<string|int|float>  $options */
        public static function graph(array $options): string
        {
            return '';
        }

        public static function checkRrdExists(string $filename): bool
        {
            return false;
        }

        /**
         * @param  string|array<int, string|int>  $prefix
         * @return list<string>
         */
        public static function getRrdFiles(string $hostname, string|array $prefix = ''): array
        {
            return [];
        }

        public static function dirFromHost(string $host): string
        {
            return '';
        }
    }
}

namespace LibreNMS\Enum {
    enum ImageFormat: string
    {
        case Png = 'png';
        case Svg = 'svg';

        public function contentType(): string
        {
            return '';
        }
    }
}

namespace LibreNMS\Data\Graphing {
    class GraphParameters
    {
        public readonly \LibreNMS\Enum\ImageFormat $imageFormat;

        public readonly int $width;

        public readonly int $height;

        /** @param  array<string, mixed>  $vars */
        public function __construct(array $vars)
        {
            $this->imageFormat = \LibreNMS\Enum\ImageFormat::Svg;
            $this->width = 0;
            $this->height = 0;
        }

        public function visible(string $element): bool
        {
            return true;
        }

        /** @return list<string|int|float> */
        public function toRrdOptions(): array
        {
            return [];
        }
    }
}
