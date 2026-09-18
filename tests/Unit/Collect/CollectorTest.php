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

it('records required command failures as errors without aborting', function () {
    $def = (new DefinitionParser)->parse([
        'name' => 'two', 'commands' => ['a' => 'show a', 'b' => 'show b'],
        'sensors' => [
            ['class' => 'count', 'command' => 'a', 'index' => "'a'", 'descr' => 'A', 'value' => 'count(//x)'],
            ['class' => 'count', 'command' => 'b', 'index' => "'b'", 'descr' => 'B', 'value' => 'count(//x)'],
        ],
    ], 'two.yaml');
    $transport = new FakeTransport(['show b' => '<rpc-reply><r><x/><x/></r></rpc-reply>']);

    $result = (new Collector($transport))->collect([$def]);

    expect($result->ok())->toBeTrue()
        ->and($result->commands['cli:show a']->status)->toBe(CommandRun::ERROR)
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
