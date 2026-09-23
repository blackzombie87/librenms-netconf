<?php

/*
 * PHPStan stub: corrections to LibreNMS PHPDoc that the analysis would otherwise trust.
 * Used only when analysing against a real LibreNMS checkout (phpstan-librenms.neon); nothing
 * here is loaded at runtime. Types are written against Illuminate rather than App\, because a
 * stub is resolved before the bootstrap file registers LibreNMS's autoloader.
 */

namespace LibreNMS\Interfaces\Data;

interface DataStorageInterface
{
    /**
     * `$device` is documented as an array upstream, but LibreNMS\Data\Store\Datastore::put()
     * branches on `$device instanceof \App\Models\Device` and stores the model as it is; the
     * plugin's writers pass the model they already hold. Core's own modules pass
     * `$os->getDeviceArray()`, which is why the docblock says array. Upstream fix pending.
     *
     * @param  array<string, mixed>|\Illuminate\Database\Eloquent\Model  $device
     * @param  string  $measurement
     * @param  array<string, mixed>  $tags
     * @param  array<string, mixed>|int|float|string  $fields
     * @return mixed
     */
    public function put($device, $measurement, $tags, $fields);
}
