<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Checks;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Collect\SensorWriter;
use SafferIt\LibrenmsNetconf\Definitions\SensorMapping;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Extract\SensorValue;

/**
 * The per-leaf "EVPN fabric critical issues" count sensor (plan §7.5): fabrics are not
 * devices, so every monitored member carries the number of *critical* issues that involve it
 * as a native sensor (netconf-evpn-fabric-issues, limit 0) for alert rules and graphs.
 *
 * Criticals only since 1.3.0 (plan §10.7): with `limit 0` the stock "Sensor over limit" rule
 * fires on anything above zero, and counting warnings meant every standing informational
 * finding alerted forever — on the first production fabric it alerted all twelve leaves at
 * once. The warnings are on the Checks tab and in the eventlog, where they belong. The
 * value joins the device's own run like a YAML sensor, after this run's fabric resolve, so
 * it lives and dies with the plugin's sensor sync: on discovery a device without membership
 * gets no sensor. On poll an existing sensor is never left frozen: while the fabric view is
 * paused or after the device left the fabric it reads 0, so `limit 0` rules clear and the
 * RRD stays continuous.
 */
final class IssueSensor
{
    public const DEFINITION = 'evpn';

    public const ID = 'fabric-issues';

    public const TYPE = 'netconf-' . self::DEFINITION . '-' . self::ID;

    public const DESCRIPTION = 'EVPN fabric critical issues';

    public static function mapping(): SensorMapping
    {
        return new SensorMapping(id: self::ID, class: 'count', command: '', index: "'total'", descr: self::DESCRIPTION, group: 'EVPN', limit: 0);
    }

    /**
     * The reading a run appends to the device's sensors: the count for a fabric member; on
     * poll a 0 for a device that has the sensor but nothing to count right now (fabric view
     * off, membership gone); null when the sensor should not exist or nothing is recorded.
     */
    public static function reading(Device $device, bool $fabricEnabled, bool $discovery): ?SensorValue
    {
        $value = $fabricEnabled ? self::value($device) : null;
        if ($value === null && ! $discovery && self::exists($device)) {
            return self::make(0.0);
        }

        return $value;
    }

    /** The reading for a fabric member, null for a device that is not one. */
    public static function value(Device $device): ?SensorValue
    {
        $member = DB::table(TableSchema::tableName('vtep') . ' as v')
            ->join(TableSchema::tableName('fabric_member') . ' as m', 'm.vtep_ip', '=', 'v.vtep_ip')
            ->where('v.device_id', $device->device_id)->exists();
        if (! $member) {
            return null;
        }
        $counts = IssueStore::countForDevice($device->device_id);

        return self::make((float) $counts['critical']);
    }

    /** Whether the device has the sensor row from an earlier discovery. */
    public static function exists(Device $device): bool
    {
        return $device->sensors()->where('poller_type', SensorWriter::POLLER_TYPE)->where('sensor_type', self::TYPE)->exists();
    }

    private static function make(float $count): SensorValue
    {
        return new SensorValue(self::DEFINITION, self::mapping(), 'total', self::DESCRIPTION, $count);
    }
}
