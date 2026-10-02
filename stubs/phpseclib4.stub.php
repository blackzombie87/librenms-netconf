<?php

/*
 * PHPStan scan file: the phpseclib 4 classes SafferIt\LibrenmsNetconf\Transport\Phpseclib reaches
 * for, declared for an analysis that has only phpseclib 3 installed (the plugin's own vendor,
 * LibreNMS before librenms#20677). For the calls the plugin makes 4 has the surface of 3, so the
 * classes are described as 3 subtypes. Where 4 is installed its real classes are used. Nothing
 * here is loaded at runtime.
 */

namespace phpseclib4\Crypt\Common;

interface PrivateKey extends \phpseclib3\Crypt\Common\PrivateKey
{
}

namespace phpseclib4\Net;

class SSH2 extends \phpseclib3\Net\SSH2
{
}

namespace phpseclib4\Crypt;

class PublicKeyLoader
{
    /**
     * @param  string|array<string, mixed>  $key
     */
    public static function loadPrivateKey(string|array $key, ?string $password = null): \phpseclib4\Crypt\Common\PrivateKey
    {
        throw new \LogicException('stub');
    }
}
