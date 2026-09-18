<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use SafferIt\LibrenmsNetconf\Console\Concerns\ResolvesTarget;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;
use SafferIt\LibrenmsNetconf\Transport\Reply;

/**
 * lnms netconf:run <device> show evpn instance extensive — ad-hoc command, pretty XML.
 * Useful while writing YAML definitions.
 */
class NetconfRunCommand extends Command
{
    use ResolvesTarget;

    protected $signature = 'netconf:run
        {device : Hostname, IP, sysName or device_id}
        {command* : Operational command, e.g. show version (quotes optional)}
        {--rpc : Treat the command as a raw RPC body (netconf transport only)}
        {--raw : Print the reply exactly as received}
        {--o|output= : Write the reply to this file instead of stdout}'
        . self::TARGET_OPTIONS;

    protected $description = 'Run one operational command on a device and print the XML reply';

    public function handle(): int
    {
        $command = implode(' ', (array) $this->argument('command'));

        try {
            $credentials = $this->resolveCredentials();
            $transport = $this->makeTransport($credentials);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $reply = $this->option('rpc') ? $transport->rpc($command) : $transport->run($command);
        } catch (TransportException $e) {
            $this->error($e->getMessage());
            $transport->close();

            return self::FAILURE;
        }

        $transport->close();

        return $this->output($reply);
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
