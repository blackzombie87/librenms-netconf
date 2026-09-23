<?php

namespace SafferIt\LibrenmsNetconf\Support;

/**
 * One order for the addresses a device is known by (F6 5). A device can have several VTEP
 * source addresses or router-ids, and the lowest one becomes its fabric member address, so
 * the order has to be the address order and not the string order — `10.10.0.1` sorts before
 * `10.9.0.1` as text. IPv4 before IPv6, each in `inet_pton()` byte order, anything that is
 * not an address last (by string, so the result is still stable).
 *
 * In PHP, after the query: the feature suite runs on MariaDB and the unit suite on sqlite,
 * and neither `INET6_ATON` nor a portable equivalent is available in both.
 */
final class IpSort
{
    public static function compare(string $a, string $b): int
    {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);
        if ($pa === false || $pb === false) {
            return $pa === $pb ? strcmp($a, $b) : ($pa === false ? 1 : -1);
        }

        return strlen($pa) === strlen($pb) ? strcmp($pa, $pb) : strlen($pa) <=> strlen($pb);
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function sort(array $ips): array
    {
        usort($ips, self::compare(...));

        return $ips;
    }

    /**
     * @param  list<string>  $ips
     */
    public static function lowest(array $ips): ?string
    {
        return self::sort($ips)[0] ?? null;
    }
}
