<?php

namespace SafferIt\LibrenmsNetconf\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\Trace\TraceRunner;
use SafferIt\LibrenmsNetconf\Fabric\View\FabricNodes;

/**
 * lnms netconf:trace <from> <to> — the path two endpoints take through an EVPN fabric, with
 * the interfaces on every hop (plan §12.5).
 *
 * The same renderer the page uses, which is how it is tested without HTTP and how an
 * operator scripts it. `--live` asks each device on the way what its own forwarding table
 * says; it opens one SSH session per device and writes nothing.
 */
class NetconfTraceCommand extends Command
{
    protected $signature = 'netconf:trace
        {from : Source MAC address or IP}
        {to : Destination MAC address or IP}
        {--fabric= : Fabric id (default: the only one, or the one both endpoints are in)}
        {--vni= : Restrict both endpoints to this VNI}
        {--live : Read each device\'s own forwarding table instead of the stored graph}
        {--json : Print the whole result as JSON}';

    protected $description = 'Trace a MAC or IP through an EVPN fabric, hop by hop';

    public function handle(): int
    {
        $fabricId = $this->fabricId();
        if ($fabricId === null) {
            return self::FAILURE;
        }

        $nodes = FabricNodes::forFabric($fabricId);
        $vni = $this->option('vni');
        $runner = new TraceRunner($fabricId, $nodes);
        $walker = $this->option('live') ? TraceRunner::walker() : null;
        $result = $runner->run(
            (string) $this->argument('from'),
            (string) $this->argument('to'),
            is_numeric($vni) ? (int) $vni : null,
            live: (bool) $this->option('live'),
            walker: $walker,
        );
        if ($walker !== null) {
            $result['log'] = $walker->log;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($result['ok'] ?? false)) {
            $this->error((string) $result['reason']);
            foreach (['a_sources', 'b_sources'] as $key) {
                foreach ($result[$key]['consulted'] as $source) {
                    $this->line(sprintf('  %-40s %d rows%s', $source['source'], $source['rows'], $source['note'] === null ? '' : '  — ' . $source['note']));
                }
            }

            return self::FAILURE;
        }

        $this->line('');
        $this->line('<info>' . $result['line'] . '</info>');
        $this->line('');

        if ($result['path'] !== []) {
            $this->table(
                ['Hop', 'Out', 'In', 'Protocol', 'State', 'Source'],
                array_map(fn ($hop) => [
                    $nodes->name((string) $hop['a']) . ' → ' . $nodes->name((string) $hop['b']),
                    (string) ($hop['a_ifname'] ?? '?'),
                    (string) ($hop['b_ifname'] ?? '?'),
                    (string) $hop['protocol'],
                    (string) ($hop['state'] ?? 'no session state'),
                    ($hop['live'] ?? false) ? 'device FIB' : 'stored graph',
                ], $result['path']),
            );
            if ($result['equal_paths'] > 1) {
                $this->line(sprintf('<comment>%d paths of the same hop count exist; the FIB decides which one carries the traffic.</comment>', $result['equal_paths']));
            }
        }

        foreach ($result['checks'] as $check) {
            $this->line(sprintf(
                '  [%s] %s  <fg=gray>%s</>',
                $check['ok'] === true ? ' ok ' : ($check['ok'] === false ? 'FAIL' : ' ?  '),
                $check['label'],
                $check['detail'],
            ));
        }
        foreach ($result['warnings'] as $warning) {
            $this->warn('  ' . $warning);
        }
        if (($result['walk']['stopped'] ?? null) !== null) {
            $this->warn('  live walk stopped: ' . $result['walk']['stopped'] . ' — the rest is the stored graph');
        }

        return self::SUCCESS;
    }

    private function fabricId(): ?int
    {
        $option = $this->option('fabric');
        $table = TableSchema::tableName('fabric');
        if ($option !== null) {
            $id = (int) $option;
            if (! DB::table($table)->where('id', $id)->exists()) {
                $this->error("No fabric with id $id.");

                return null;
            }

            return $id;
        }

        $ids = DB::table($table)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            $this->error('No EVPN fabric has been resolved yet. Run <comment>lnms netconf:fabric --resolve</comment>.');

            return null;
        }
        if (count($ids) > 1) {
            $this->error('Several fabrics exist; name one with --fabric=<id> (see <comment>lnms netconf:fabric</comment>).');

            return null;
        }

        return $ids[0];
    }
}
