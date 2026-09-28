<?php

use SafferIt\LibrenmsNetconf\Fabric\View\FabricTopologyInput;

/**
 * Plan §10.12: `UnderlayResolver` turns every routing session inside a member's own subnet into
 * a half link. On a border router that is its transit and IX peers — six of them on the first
 * production fabric. A far end that only one member peers with is a session out of the fabric;
 * one that several members peer with is the node that should be a member and is not monitored
 * yet, and that is the one the picture puts on the spine tier.
 */
it('tells a spine nobody monitors from a border router\'s transit peers', function () {
    $half = fn (string $a, string $far) => ['a' => $a, 'b' => null, 'b_label' => $far];

    expect(FabricTopologyInput::sharedFarEnds([
        // an unmonitored spine: every leaf peers with it
        $half('192.0.2.11', '10.0.0.1'), $half('192.0.2.12', '10.0.0.1'), $half('192.0.2.13', '10.0.0.1'),
        // the border leaf's own transit and IX peers, one member each
        $half('192.0.2.13', '193.178.185.5'), $half('192.0.2.13', '193.178.185.6'),
        // a resolved link is not a far end at all
        ['a' => '192.0.2.11', 'b' => '192.0.2.12', 'b_label' => null],
    ]))->toBe(['10.0.0.1']);

    // the same member peering with one address twice is still one member, not two
    expect(FabricTopologyInput::sharedFarEnds([
        $half('192.0.2.13', '193.178.185.5'), $half('192.0.2.13', '193.178.185.5'),
    ]))->toBe([]);
});
