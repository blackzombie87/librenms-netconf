<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Checks;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\SensorMapping;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Extract\SensorValue;

/**
 * The per-leaf "EVPN fabric issues" count sensor (plan §7.5): fabrics are not devices, so
 * every monitored member carries the number of critical and warning issues that involve it
 * as a native sensor (netconf-evpn-fabric-issues, limit 0) for alert rules and graphs. The
 * value joins the device's own poll like a YAML sensor, so it lives and dies with the
 * plugin's sensor sync: no membership, no sensor.
 */
final class IssueSensor
{
    public const DEFINITION = 'evpn';

    public const ID = 'fabric-issues';

    public const TYPE = 'netconf-' . self::DEFINITION . '-' . self::ID;

    public static function mapping(): SensorMapping
    {
        return new SensorMapping(id: self::ID, class: 'count', command: '', index: "'total'", descr: 'EVPN fabric issues', group: 'EVPN', limit: 0);
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

        return new SensorValue(self::DEFINITION, self::mapping(), 'total', 'EVPN fabric issues', (float) ($counts['critical'] + $counts['warning']));
    }
}
