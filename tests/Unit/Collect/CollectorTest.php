<?php

use SafferIt\LibrenmsNetconf\Collect\Collector;
use SafferIt\LibrenmsNetconf\Collect\CommandRun;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionParser;
use SafferIt\LibrenmsNetconf\Support\FixtureReplay;
use SafferIt\LibrenmsNetconf\Transport\Contracts\TransportInterface;
use SafferIt\LibrenmsNetconf\Transport\Exceptions\TimeoutException;
use SafferIt\LibrenmsNetconf\Transport\FakeTransport;
use SafferIt\LibrenmsNetconf\Transport\Reply;

function shipped(string ...$names): array
{
    $all = (new DefinitionLoader([DefinitionLoader::shippedDirectory()]))->all();

    return array_values(array_intersect_key($all, array_flip($names)));
}

function replayTransport(array $definitions): FakeTransport
{
    $commands = [];
    foreach ($definitions as $d) {
        foreach ($d->commands as $c) {
            $commands[] = $c->cli;
        }
    }
    [$transport] = FixtureReplay::transport(__DIR__ . '/../../fixtures/junos', $commands);

    return $transport;
}

it('runs each distinct command once and extracts from the fixtures', function () {
    $defs = shipped('junos-routing', 'junos-l2');
    $transport = replayTransport($defs);

    $result = (new Collector($transport))->collect($defs);

    expect($result->ok())->toBeTrue()
        ->and(collect($transport->executed)->sort()->values()->all())->toBe(['show bgp summary', 'show ethernet-switching table summary', 'show route summary'])
        ->and($result->commands)->toHaveCount(3)
        ->and(count($result->sensors()))->toBeGreaterThan(8)
        ->and($result->definitions['junos-l2']->sensors[0]->descr)->toBe('MAC table entries')
        ->and($result->definitions['junos-l2']->sensors[0]->value)->toBeGreaterThan(0)
        ->and($result->summary()['commands_ok'])->toBe(3);
});

it('marks optional commands that fail as skipped and drops only their mappings', function () {
    $defs = shipped('junos-srx-cluster');
    $transport = new FakeTransport(['show chassis cluster status' => fixture('junos/show-chassis-cluster-status-not-enabled.xml')]);

    $result = (new Collector($transport))->collect($defs);

    expect($result->ok())->toBeTrue()
        ->and($result->commands['cli:show chassis cluster status']->status)->toBe(CommandRun::SKIPPED)
        ->and($result->commands['cli:show chassis cluster status']->message)->toContain('Chassis cluster is not enabled')
        ->and($result->definitions['junos-srx-cluster']->skippedMappings)->toBe(['rg-status', 'rg-monitor', 'rg-failovers', 'rg-node'])
        ->and($result->definitions['junos-srx-cluster']->isEmpty())->toBeTrue();
});

it('records required command failures as run errors without aborting the session', function () {
    $def = (new DefinitionParser)->parse([
        'name' => 'two', 'commands' => ['a' => 'show a', 'b' => 'show b'],
        'sensors' => [
            ['class' => 'count', 'command' => 'a', 'index' => "'a'", 'descr' => 'A', 'value' => 'count(//x)'],
            ['class' => 'count', 'command' => 'b', 'index' => "'b'", 'descr' => 'B', 'value' => 'count(//x)'],
        ],
    ], 'two.yaml');
    $transport = new FakeTransport(['show b' => '<rpc-reply><r><x/><x/></r></rpc-reply>']);

    $result = (new Collector($transport))->collect([$def]);

    // the run is a failure (status row, back-off, eventlog), the other command's data is kept
    expect($result->ok())->toBeFalse()
        ->and($result->aborted)->toBeFalse()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toStartWith('show a: ')
        ->and($result->commands['cli:show a']->status)->toBe(CommandRun::ERROR)
        ->and($result->commands['cli:show b']->status)->toBe(CommandRun::OK)
        ->and($result->sensors())->toHaveCount(1)
        ->and($result->sensors()[0]->value)->toBe(2.0);
});

it('aborts the session on connection level failures', function () {
    $def = (new DefinitionParser)->parse([
        'name' => 'two', 'commands' => ['a' => 'show a', 'b' => 'show b'],
        'sensors' => [['class' => 'count', 'command' => 'b', 'index' => "'b'", 'descr' => 'B', 'value' => 'count(//x)']],
    ], 'two.yaml');
    $transport = new class implements TransportInterface {
        public function name(): string { return 'boom'; }

        public function connect(): void {}

        public function run(string $command): Reply { throw new TimeoutException("$command: timeout"); }

        public function rpc(string $xml): Reply { return $this->run($xml); }

        public function supportsRpc(): bool { return false; }

        public function sessionInfo(): array { return []; }

        public function close(): void {}
    };

    $result = (new Collector($transport))->collect([$def]);

    expect($result->aborted)->toBeTrue()
        ->and($result->errors)->toBe(['show a: timeout'])
        ->and($result->commands['cli:show b']->status)->toBe(CommandRun::SKIPPED)
        ->and($result->commands['cli:show b']->message)->toBe('session aborted');
});

it('honours every: and dedupes commands shared by definitions', function () {
    $mk = fn (string $name, int $every) => (new DefinitionParser)->parse([
        'name' => $name, 'commands' => ['a' => ['cli' => 'show a', 'every' => $every]],
        'sensors' => [['class' => 'count', 'command' => 'a', 'index' => "'a'", 'descr' => 'A', 'value' => 'count(//x)']],
    ], "$name.yaml");
    $transport = new FakeTransport(['show a' => '<r><x/></r>']);
    $collector = new Collector($transport);

    $result = $collector->collect([$mk('one', 3), $mk('two', 3)], pollNumber: 2);
    expect($result->commands['cli:show a']->status)->toBe(CommandRun::SKIPPED)
        ->and($result->sensors())->toBe([])
        ->and($result->definitions['one']->skippedMappings)->toBe(['sensor1']);

    $result = $collector->collect([$mk('one', 3), $mk('two', 3)], pollNumber: 3);
    expect($transport->executed)->toBe(['show a'])
        ->and($result->sensors())->toHaveCount(2);

    $result = $collector->collect([$mk('one', 3)], pollNumber: 0);
    expect($result->sensors())->toHaveCount(1);
});

it('treats text-only and warning replies as unavailable commands', function () {
    $def = (new DefinitionParser)->parse([
        'name' => 'na', 'commands' => ['ldp' => ['cli' => 'show ldp session', 'optional' => true], 'vrrp' => 'show vrrp summary'],
        'sensors' => [
            ['class' => 'count', 'command' => 'ldp', 'index' => "'s'", 'descr' => 'LDP sessions', 'value' => 'count(//ldp-session)'],
            ['class' => 'count', 'command' => 'vrrp', 'index' => "'v'", 'descr' => 'VRRP groups', 'value' => 'count(//vrrp-interface)'],
        ],
    ], 'na.yaml');
    $transport = new FakeTransport([
        'show ldp session' => fixture('junos/show-ldp-session-not-running.xml'),
        'show vrrp summary' => fixture('junos/show-vrrp-summary-not-running.xml'),
    ]);

    $result = (new Collector($transport))->collect([$def]);

    expect($result->sensors())->toBe([])
        ->and($result->commands['cli:show ldp session']->status)->toBe(CommandRun::SKIPPED)
        ->and($result->commands['cli:show ldp session']->message)->toBe('LDP instance is not running')
        ->and($result->commands['cli:show vrrp summary']->status)->toBe(CommandRun::ERROR)
        ->and($result->commands['cli:show vrrp summary']->message)->toBe('vrrp subsystem not running - not needed by configuration.')
        ->and($result->errors)->toBe(['show vrrp summary: vrrp subsystem not running - not needed by configuration.'])   // required: the run failed
        ->and($result->definitions['na']->skippedMappings)->toBe(['sensor1', 'sensor2']);
});

it('skips the EVPN definitions on a box that answers "not configured" without failing the run', function () {
    // G13: show evpn instance extensive is optional in both shipped EVPN definitions, so a non-EVPN
    // Junos box loses only the EVPN mappings; the other definitions and the status row stay healthy
    $defs = shipped('junos-evpn', 'junos-evpn-fabric', 'junos-system');
    $transport = replayTransport($defs)->on('show evpn instance extensive', '<rpc-reply><output>EVPN is not configured</output></rpc-reply>');

    $result = (new Collector($transport))->collect($defs);

    expect($result->ok())->toBeTrue()
        ->and($result->commands['cli:show evpn instance extensive']->status)->toBe(CommandRun::SKIPPED)
        ->and($result->commands['cli:show evpn instance extensive']->message)->toBe('EVPN is not configured')
        ->and(array_filter($result->definitions['junos-evpn']->sensors, fn ($s) => $s->mapping->command === 'evpn_inst'))->toBe([])
        ->and($result->definitions['junos-evpn']->skippedMappings)->toContain('esi-resolution', 'gateway-irbs')
        ->and(array_filter($result->definitions['junos-evpn-fabric']->tables, fn ($t) => $t->mapping->command === 'evpn_inst'))->toBe([])
        ->and($result->definitions['junos-evpn-fabric']->skippedMappings)->toContain('neighbor', 'esi')
        ->and($result->definitions['junos-system']->sensors)->not->toBe([]);
});

it('merges optional and every when definitions share a command', function () {
    $mk = fn (string $name, array $spec) => (new DefinitionParser)->parse([
        'name' => $name, 'commands' => ['a' => ['cli' => 'show a'] + $spec],
        'sensors' => [['class' => 'count', 'command' => 'a', 'index' => "'a'", 'descr' => 'A', 'value' => 'count(//x)']],
    ], "$name.yaml");
    $transport = new FakeTransport(['show a' => '<r><x/></r>']);

    // every: the most frequent use wins, whichever definition comes first
    $result = (new Collector($transport))->collect([$mk('slow', ['every' => 3]), $mk('fast', [])], pollNumber: 2);
    expect($result->commands['cli:show a']->status)->toBe(CommandRun::OK)
        ->and($result->sensors())->toHaveCount(2);
    $result = (new Collector($transport))->collect([$mk('fast', []), $mk('slow', ['every' => 3])], pollNumber: 2);
    expect($result->commands['cli:show a']->status)->toBe(CommandRun::OK);

    // optional: a required use anywhere makes the failure count
    $failing = new FakeTransport;
    $result = (new Collector($failing))->collect([$mk('opt', ['optional' => true]), $mk('req', [])]);
    expect($result->commands['cli:show a']->status)->toBe(CommandRun::ERROR)
        ->and($result->ok())->toBeFalse();
    $result = (new Collector($failing))->collect([$mk('opt', ['optional' => true]), $mk('opt2', ['optional' => true])]);
    expect($result->commands['cli:show a']->status)->toBe(CommandRun::SKIPPED)
        ->and($result->ok())->toBeTrue();
});
