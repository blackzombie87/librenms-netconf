<?php

use SafferIt\LibrenmsNetconf\Fabric\Trace\Endpoint;
use SafferIt\LibrenmsNetconf\Fabric\Trace\TraceRunner;

/**
 * @param  array<string, mixed>  $extra
 */
function attachment(string $source, string $address, array $extra = []): Endpoint
{
    return new Endpoint(
        query: '198.51.100.4', kind: 'ip', mac: $extra['mac'] ?? '020000001140', ips: ['198.51.100.4'], vni: $extra['vni'] ?? 10010,
        deviceId: 1, address: $address, name: 'node-' . $address, ifname: 'ae2.0', portId: null, esi: $extra['esi'] ?? null,
        source: $source, evidence: [], df: $extra['df'] ?? false,
    );
}

it('adds the other PEs of the segment to an attachment the IP/MAC table named, and teaches it the segment', function () {
    $pick = attachment(Endpoint::SOURCE_EVPN_MAC_IP, '192.0.2.61');
    $candidates = [
        $pick,
        attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.61', ['esi' => '00:11', 'df' => true]),
        attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.62', ['esi' => '00:11']),
    ];

    $legs = TraceRunner::attachments($candidates, $pick);

    expect(array_column($legs, 'address'))->toBe(['192.0.2.61', '192.0.2.62'])
        ->and($legs[0]->source)->toBe(Endpoint::SOURCE_EVPN_MAC_IP)   // the pick keeps what it was found by
        ->and($legs[0]->esi)->toBe('00:11')
        ->and($legs[0]->df)->toBeTrue();
});

it('keeps an ESI pick first and the others after it, once each', function () {
    $df = attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.61', ['esi' => '00:11', 'df' => true]);
    $other = attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.62', ['esi' => '00:11']);

    expect(array_column(TraceRunner::attachments([$df, $other, $other], $df), 'address'))->toBe(['192.0.2.61', '192.0.2.62'])
        ->and(array_column(TraceRunner::attachments([$df, $other], $other), 'address'))->toBe(['192.0.2.62', '192.0.2.61']);
});

it('does not call two PEs that both claim a MAC locally multihomed, or a PE that is not on the segment', function () {
    $pick = attachment(Endpoint::SOURCE_EVPN_LOCAL, '192.0.2.61');
    $duplicate = attachment(Endpoint::SOURCE_EVPN_LOCAL, '192.0.2.62');
    $elsewhere = attachment(Endpoint::SOURCE_EVPN_MAC_IP, '192.0.2.63');
    $segment = [attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.61', ['esi' => '00:11']), attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.62', ['esi' => '00:11'])];

    expect(TraceRunner::attachments([$pick, $duplicate], $pick))->toBe([$pick])
        // the pick's own PE is not one of the segment's: unrelated, whatever the MAC
        ->and(TraceRunner::attachments([$elsewhere, ...$segment], $elsewhere))->toBe([$elsewhere])
        // another MAC or another VNI is another host
        ->and(array_column(TraceRunner::attachments([attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.61', ['esi' => '00:11', 'mac' => '020000009999']), attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.62', ['esi' => '00:11', 'mac' => '020000009999'])], attachment(Endpoint::SOURCE_EVPN_ESI, '192.0.2.61', ['esi' => '00:11'])), 'address'))->toBe(['192.0.2.61']);
});

it('stops at four legs of a segment', function () {
    $legs = [];
    foreach (range(1, 6) as $n) {
        $legs[] = attachment(Endpoint::SOURCE_EVPN_ESI, "192.0.2.$n", ['esi' => '00:11']);
    }

    expect(TraceRunner::attachments($legs, $legs[0]))->toHaveCount(TraceRunner::MAX_LEGS);
});
