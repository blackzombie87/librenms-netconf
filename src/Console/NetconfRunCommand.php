<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use SafferIt\LibrenmsNetconf\Console\Concerns\ResolvesTarget;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\ConnectionException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\Reply;

/**
 * lnms netconf:run <device> show evpn instance extensive — ad-hoc command, pretty XML.
 * Useful while writing YAML definitions.
 *
 * Deliberately unrestricted: whoever can run lnms on the poller can open an SSH session
 * with the same credentials anyway. Whether anything but "show" works is up to the login
 * class on the device (README); the web form is guarded by CommandGuard instead.
 *
 * Rapid ad-hoc sessions can trip the device's SSH connection rate limit, which shows up as
 * "Error reading SSH identification string" before the login: the command then waits and
 * retries once with a fresh connection.
 */
class NetconfRunCommand extends Command
{
    use ResolvesTarget;

    /** Seconds to wait before the one retry on the rate-limit error (tests set 0). */
    public static int $retryDelay = 2;

    protected $signature = 'netconf:run
        {device : Hostname, IP, sysName or device_id}
        {cmd* : Operational command, e.g. show version (quotes optional; "command" is reserved by artisan)}
        {--rpc : Treat the command as a raw RPC body (netconf transport only)}
        {--raw : Print the reply exactly as received}
        {--o|output= : Write the reply to this file instead of stdout}'
        . self::TARGET_OPTIONS;

    protected $description = 'Run one operational command on a device and print the XML reply';

    public function handle(): int
    {
        $command = implode(' ', (array) $this->argument('cmd'));

        try {
            $credentials = $this->resolveCredentials();
            $transport = $this->makeTransport($credentials);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $reply = $this->option('rpc') ? $transport->rpc($command) : $transport->run($command);

                return $this->output($reply);
            } catch (TransportException $e) {
                if ($attempt > 1 || ! self::isRateLimited($e)) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
                $this->warn(sprintf('%s; retrying once in %ds', $e->getMessage(), self::$retryDelay));
            } finally {
                $transport->close();
            }

            sleep(self::$retryDelay);
            $transport = $this->makeTransport($credentials);
        }
    }

    /** The SSH connection rate limit of Junos closes the socket before its identification string. */
    public static function isRateLimited(TransportException $e): bool
    {
        return $e instanceof ConnectionException && stripos($e->getMessage(), 'SSH identification string') !== false;
    }

    private function output(Reply $reply): int
    {
        $xml = $this->option('raw') ? $reply->raw : $reply->pretty();
        $file = $this->option('output');

        if (is_string($file) && $file !== '') {
            if (file_put_contents($file, $xml) === false) {
                $this->error("Could not write $file");

                return self::FAILURE;
            }
            $this->info(sprintf('%s: %d bytes in %.2fs written to %s', $reply->command, strlen($reply->raw), $reply->duration, $file));

            return self::SUCCESS;
        }

        $this->line($xml);
        if ($this->getOutput()->isVerbose()) {
            $this->comment(sprintf('%s: %d bytes in %.2fs', $reply->command, strlen($reply->raw), $reply->duration));
        }

        return self::SUCCESS;
    }
}
