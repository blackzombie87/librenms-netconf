<?php

namespace SafferIt\LibrenmsNetconf\Support;

use App\Models\Device;
use App\Models\DeviceGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Many devices at once for the bulk enable (plan G7): every device of a LibreNMS device
 * group, every device with an os, or the intersection of both. Shared by `lnms netconf:device
 * --group/--os` and the form on the status page.
 */
final class DeviceSelection
{
    /**
     * @return Collection<int, Device> ordered by hostname, attribs loaded
     *
     * @throws \InvalidArgumentException when nothing selects or the group is unknown
     */
    public static function resolve(?string $group, ?string $os): Collection
    {
        $group = trim((string) $group);
        $os = trim((string) $os);
        if ($group === '' && $os === '') {
            throw new \InvalidArgumentException('select devices with a device group, an os or both');
        }

        $query = Device::query()->with('attribs')->orderBy('hostname');
        if ($group !== '') {
            $model = ctype_digit($group) ? DeviceGroup::query()->find((int) $group) : null;
            $model ??= DeviceGroup::query()->where('name', $group)->first();
            if (! $model instanceof DeviceGroup) {
                throw new \InvalidArgumentException("unknown device group \"$group\"");
            }
            $query->whereIn('device_id', DB::table('device_group_device')->where('device_group_id', $model->id)->select('device_id'));
        }
        if ($os !== '') {
            $query->where('os', $os);
        }

        return $query->get();
    }

    /** "device group X", "os junos" or both, for messages. */
    public static function describe(?string $group, ?string $os): string
    {
        $parts = [];
        if (trim((string) $group) !== '') {
            $parts[] = 'device group "' . trim((string) $group) . '"';
        }
        if (trim((string) $os) !== '') {
            $parts[] = 'os ' . trim((string) $os);
        }

        return implode(' and ', $parts) ?: 'nothing';
    }

    /**
     * @return array<int, string> id => name, for a selector
     */
    public static function groups(): array
    {
        /** @var array<int, string> $groups */
        $groups = DeviceGroup::query()->orderBy('name')->pluck('name', 'id')->all();

        return $groups;
    }

    /**
     * @return list<string> distinct os values of the monitored devices
     */
    public static function osNames(): array
    {
        /** @var list<string> $names */
        $names = Device::query()->whereNotNull('os')->where('os', '!=', '')->distinct()->orderBy('os')->pluck('os')->values()->all();

        return $names;
    }
}
