<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * Where the per-device NETCONF pages live. One place for every link into them, so the
 * device tab (plan §8 U2) can take over from the standalone plugin page without touching
 * the views that link there.
 */
final class DevicePage
{
    public const SECTIONS = ['status', 'metrics', 'esi', 'edit'];

    public static function url(int $deviceId, string $section = 'status'): string
    {
        return match ($section) {
            'metrics' => route('netconf.device.metrics', $deviceId),
            default => route('netconf.device', $deviceId),
        };
    }
}
