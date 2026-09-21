<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use LibreNMS\Interfaces\Data\DataStorageInterface;

/*
 * Datastore for the feature tests: keeps every put() so a test can assert what a writer sent
 * (measurement, tags, fields) without rrdtool or a cache. Declared only when LibreNMS is
 * loaded, so the bare-checkout run (Pest) that skips the suite does not compile it.
 */
if (interface_exists(DataStorageInterface::class)) {
final class MemoryDatastore implements DataStorageInterface
{
    /** @var list<array{measurement: string, tags: array<string, mixed>, fields: array<string, mixed>}> */
    public array $puts = [];

    public function put($device, $measurement, $tags, $fields): void
    {
        $this->puts[] = ['measurement' => $measurement, 'tags' => $tags, 'fields' => is_array($fields) ? $fields : [$measurement => $fields]];
    }

    /**
     * @return list<array<string, mixed>> the fields of every put for a measurement
     */
    public function fields(string $measurement): array
    {
        return array_values(array_map(fn ($p) => $p['fields'], array_filter($this->puts, fn ($p) => $p['measurement'] === $measurement)));
    }
}
}
