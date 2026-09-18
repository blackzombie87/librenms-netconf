<?php

namespace SafferIt\LibrenmsNetconf\Support;

use SafferIt\LibrenmsNetconf\Transport\FakeTransport;

/**
 * Builds a FakeTransport from a directory of recorded replies. Files are named after the
 * command: "show route summary" -> show-route-summary.xml (the netconf:run --output and
 * dev/capture naming). Variants such as show-x-empty.xml are ignored unless requested.
 */
class FixtureReplay
{
    public static function slug(string $command): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($command)) ?? '', '-');
    }

    /**
     * @param  list<string>  $commands  commands the definitions need
     * @return array{FakeTransport, list<string>} transport and the commands without a fixture
     */
    public static function transport(string $directory, array $commands): array
    {
        $transport = new FakeTransport;
        $missing = [];
        foreach (array_unique($commands) as $command) {
            $file = rtrim($directory, '/') . '/' . self::slug($command) . '.xml';
            if (is_readable($file)) {
                $transport->on($command, (string) file_get_contents($file));
            } else {
                $missing[] = $command;
            }
        }

        return [$transport, $missing];
    }
}
