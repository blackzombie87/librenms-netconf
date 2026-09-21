<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use SafferIt\LibrenmsNetconf\Console\Concerns\ResolvesTarget;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\RpcErrorException;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TransportException;

/**
 * lnms netconf:test <device> — verify login, transport and permissions.
 */
class NetconfTestCommand extends Command
{
    use ResolvesTarget;

    protected $signature = 'netconf:test
        {device : Hostname, IP, sysName or device_id; unknown hosts are tested with the global settings}
        {--capabilities : List every NETCONF capability the server advertises}'
        . self::TARGET_OPTIONS;

    protected $description = 'Connect to a device over SSH (cli or netconf transport) and report version and permissions';

    public function handle(): int
    {
        try {
            $credentials = $this->resolveCredentials();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('<info>Target:</info> ' . $this->deviceLabel());
        $this->table(['Setting', 'Value'], $this->rows($credentials->describe()));

        $transport = $this->makeTransport($credentials);

        try {
            $start = microtime(true);
            $transport->connect();
            $this->info(sprintf('Connected in %.2fs', microtime(true) - $start));
        } catch (TransportException $e) {
            // the login may have succeeded before the hello / subsystem failed: hang up
            $transport->close();
            $this->error('Connection failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $ok = true;

        try {
            $info = $transport->sessionInfo();
            $capabilities = $info['capabilities'] ?? [];
            unset($info['capabilities']);
            $this->table(['Session', 'Value'], $this->rows($info));

            if ($this->option('capabilities') && is_array($capabilities)) {
                $this->line('<info>Capabilities:</info>');
                foreach ($capabilities as $cap) {
                    $this->line('  ' . $cap);
                }
            }

            $ok = $this->showVersion($transport);
            $ok = $this->showAuthorization($transport) && $ok;
        } catch (TransportException $e) {
            $this->error($e->getMessage());
            $ok = false;
        } finally {
            $transport->close();
        }

        $this->line($ok ? '<info>OK</info>' : '<error>Test finished with errors</error>');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function showVersion(TransportInterface $transport): bool
    {
        try {
            $reply = $transport->run('show version');
        } catch (RpcErrorException $e) {
            $this->error('show version: ' . implode('; ', $e->messages));

            return false;
        }

        $this->table(['show version', 'Value'], $this->rows([
            'host-name' => $reply->text('host-name') ?? '?',
            'product-model' => $reply->text('product-model') ?? '?',
            'junos-version' => $reply->text('junos-version') ?? $reply->text('package-information/comment') ?? '?',
            'duration' => sprintf('%.2fs', $reply->duration),
            'bytes' => strlen($reply->raw),
        ]));

        return true;
    }

    /**
     * Junos prints the effective permissions of the login class; missing rights show up here
     * before a definition fails silently.
     */
    private function showAuthorization(TransportInterface $transport): bool
    {
        try {
            $reply = $transport->run('show cli authorization');
        } catch (RpcErrorException $e) {
            $this->warn('show cli authorization: ' . implode('; ', $e->messages));

            return true; // informational only
        }

        $permissions = [];
        foreach ($reply->xpath()->query('//*[local-name()="user-permission-entry"]/*[local-name()="permission-type"]') ?: [] as $node) {
            $permissions[] = trim($node->textContent);
        }
        $permissions = array_values(array_unique(array_filter($permissions, fn ($p) => $p !== '' && $p !== 'none')));
        $class = $reply->text('user-information/user-class') ?? '?';

        $this->table(['show cli authorization', 'Value'], $this->rows([
            'user' => $reply->text('user-information/user') ?? '?',
            'class' => $class,
            'permissions' => count($permissions),
            'list' => wordwrap(implode(', ', $permissions), 70, "\n", true),
        ]));

        if (! in_array('view', $permissions, true)) {
            $this->warn('The login class does not report the "view" permission; most show commands will be denied.');
        }
        if ($class === 'super-user' || in_array('all-control', $permissions, true)) {
            $this->warn('The account has configuration rights. Use a read-only class for monitoring (see README).');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{string, string}>
     */
    private function rows(array $values): array
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = [(string) $key, is_scalar($value) ? (string) $value : json_encode($value)];
        }

        return $rows;
    }
}
