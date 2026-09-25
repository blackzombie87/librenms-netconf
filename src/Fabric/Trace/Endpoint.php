<?php

namespace SafferIt\LibrenmsNetconf\Fabric\Trace;

/**
 * One end of a trace, and the evidence that produced it (plan §12.5). A trace is only as
 * good as this: where a MAC was found, which source said so, and whether that source is an
 * attachment (a port or an ESI on a fabric member) or only corroboration (a leaf that learnt
 * the MAC from the fabric, which proves the MAC exists and not where it lives).
 */
final class Endpoint
{
    public const SOURCE_EVPN_LOCAL = 'evpn-local';

    public const SOURCE_EVPN_ESI = 'evpn-esi';

    public const SOURCE_EVPN_REMOTE = 'evpn-remote';

    public const SOURCE_FDB = 'fdb';

    public const SOURCE_ARP = 'arp';

    /** Most trustworthy first; the resolver ranks candidates by this order. */
    public const RANK = [self::SOURCE_EVPN_LOCAL, self::SOURCE_EVPN_ESI, self::SOURCE_FDB, self::SOURCE_ARP, self::SOURCE_EVPN_REMOTE];

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $evidence
     */
    public function __construct(
        public readonly string $query,
        public readonly ?string $kind,
        public readonly ?string $mac,
        public readonly array $ips,
        public readonly ?int $vni,
        public readonly ?int $deviceId,
        public readonly ?string $address,
        public readonly ?string $name,
        public readonly ?string $ifname,
        public readonly ?int $portId,
        public readonly ?string $esi,
        public readonly string $source,
        public readonly array $evidence,
        public readonly bool $isDuplicate = false,
        public readonly int $moves = 0,
    ) {
    }

    /** Whether this candidate says where the endpoint hangs, rather than only that it exists. */
    public function isAttachment(): bool
    {
        return $this->address !== null && $this->source !== self::SOURCE_EVPN_REMOTE;
    }

    public function rank(): int
    {
        $rank = array_search($this->source, self::RANK, true);

        return $rank === false ? count(self::RANK) : $rank;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'kind' => $this->kind,
            'mac' => $this->mac,
            'ips' => $this->ips,
            'vni' => $this->vni,
            'device_id' => $this->deviceId,
            'address' => $this->address,
            'name' => $this->name,
            'ifname' => $this->ifname,
            'port_id' => $this->portId,
            'esi' => $this->esi,
            'source' => $this->source,
            'evidence' => $this->evidence,
            'is_duplicate' => $this->isDuplicate,
            'moves' => $this->moves,
            'attachment' => $this->isAttachment(),
        ];
    }
}
