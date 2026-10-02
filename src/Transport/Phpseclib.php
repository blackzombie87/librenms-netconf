<?php

namespace SafferIt\LibrenmsNetconf\Transport;

/**
 * The parts of phpseclib that differ between the two majors LibreNMS may ship.
 *
 * Core moved from phpseclib 3 to 4 (librenms#20677). 4 renamed the namespace (phpseclib3\ to
 * phpseclib4\), dropped SSH2::getErrors() and takes null instead of false for "no passphrase".
 * The SSH2 calls the plugin makes are otherwise the same, down to read() returning the partial
 * buffer with isTimeout() set. Whichever major is installed is used; the type hints name both,
 * and stubs/phpseclib4.stub.php declares the 4 classes where only 3 is installed.
 *
 * @internal not part of the plugin's API, it goes once LibreNMS no longer ships phpseclib 3
 */
final class Phpseclib
{
    /** SSH2::READ_SIMPLE in both majors; naming the phpseclib3 constant fails on 4. */
    public const READ_SIMPLE = 1;

    public static function major(): int
    {
        return class_exists(\phpseclib4\Net\SSH2::class) ? 4 : 3;
    }

    /**
     * An unconnected SSH2 client (phpseclib connects lazily).
     *
     * @return \phpseclib3\Net\SSH2|\phpseclib4\Net\SSH2
     */
    public static function ssh(string $host, int $port, int $timeout): object
    {
        return self::major() === 4
            ? new \phpseclib4\Net\SSH2($host, $port, $timeout)
            : new \phpseclib3\Net\SSH2($host, $port, $timeout);
    }

    /**
     * @return \phpseclib3\Crypt\Common\PrivateKey|\phpseclib4\Crypt\Common\PrivateKey
     */
    public static function loadPrivateKey(#[\SensitiveParameter] string $key, #[\SensitiveParameter] ?string $passphrase): object
    {
        if (self::major() === 4) {
            return \phpseclib4\Crypt\PublicKeyLoader::loadPrivateKey($key, $passphrase);
        }

        return \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($key, $passphrase ?? false);
    }

    /**
     * The protocol messages phpseclib collected (SSH_MSG_USERAUTH_FAILURE, …); 4 keeps them private.
     *
     * @return list<string>
     */
    public static function errors(object $ssh): array
    {
        if (! method_exists($ssh, 'getErrors')) {
            return [];
        }

        return array_values(array_map('strval', array_filter((array) $ssh->getErrors())));
    }
}
