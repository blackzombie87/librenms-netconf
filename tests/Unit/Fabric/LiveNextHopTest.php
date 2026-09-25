<?php

use SafferIt\LibrenmsNetconf\Fabric\Trace\LiveNextHop;

function routeFixture(string $name): string
{
    return file_get_contents(__DIR__ . '/../../fixtures/junos/' . $name) ?: '';
}

it('reads the next hop and the protocol that installed it off the reply', function () {
    $routes = LiveNextHop::parse(routeFixture('show-route-host.xml'));

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['destination'])->toBe('192.0.2.62')
        ->and($routes[0]['entries'][0]['protocol'])->toBe('OSPF')
        ->and($routes[0]['entries'][0]['active'])->toBeTrue()
        ->and($routes[0]['entries'][0]['next_hops'])->toBe([['to' => '10.1.1.1', 'via' => 'et-0/0/52.2121', 'selected' => true]]);
});

it('takes every next hop of the active entry and ignores the inactive one', function () {
    // plan §12.4a: the FIB answers whatever installed the route, so the parser must not
    // prefer a protocol -- here BGP is active and an OSPF entry for the same prefix is not
    $hops = LiveNextHop::activeNextHops(LiveNextHop::parse(routeFixture('show-route-host-ecmp.xml')));

    expect($hops)->toHaveCount(2)
        ->and(array_column($hops, 'to'))->toBe(['10.1.2.1', '10.1.3.1'])
        ->and(array_column($hops, 'protocol'))->toBe(['BGP', 'BGP'])
        ->and($hops[0]['selected'])->toBeTrue()
        ->and($hops[1]['selected'])->toBeFalse();
});

it('reads an OSPF underlay and a BGP one the same way', function () {
    $ospf = LiveNextHop::activeNextHops(LiveNextHop::parse(routeFixture('show-route-host.xml')));
    $bgp = LiveNextHop::activeNextHops(LiveNextHop::parse(str_replace('<protocol-name>OSPF</protocol-name>', '<protocol-name>BGP</protocol-name>', routeFixture('show-route-host.xml'))));

    expect(array_column($bgp, 'to'))->toBe(array_column($ospf, 'to'))
        ->and($bgp[0]['protocol'])->toBe('BGP')
        ->and($ospf[0]['protocol'])->toBe('OSPF');
});

it('answers nothing for a destination with no active entry', function () {
    $noActive = str_replace(['<active-tag>*</active-tag>'], '', routeFixture('show-route-host.xml'));

    expect(LiveNextHop::activeNextHops(LiveNextHop::parse($noActive)))->toBe([])
        ->and(LiveNextHop::activeNextHops([]))->toBe([]);
});

it('maps a next hop only to an address a fabric member actually carries', function () {
    $owners = ['10.1.1.1' => '192.0.2.62', '10.1.1.0' => '192.0.2.61'];

    expect(LiveNextHop::mapNextHop('10.1.1.1', $owners))->toBe('192.0.2.62')
        ->and(LiveNextHop::mapNextHop('10.9.9.9', $owners))->toBeNull();
});

it('defines its command per operating system rather than inline', function () {
    expect(LiveNextHop::COMMANDS)->toHaveKey('junos')
        ->and(sprintf(LiveNextHop::COMMANDS['junos'], '192.0.2.62'))->toBe('show route 192.0.2.62')
        // and it is a plain show command, so the run guard would accept it
        ->and(\SafferIt\LibrenmsNetconf\Support\CommandGuard::reject(sprintf(LiveNextHop::COMMANDS['junos'], '192.0.2.62')))->toBeNull();
});
