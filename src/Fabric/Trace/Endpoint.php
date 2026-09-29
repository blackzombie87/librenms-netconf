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
    /**
     * The device's own IP/MAC table: it states the bridge domain and the access interface for
     * an address instead of leaving them to be inferred from a MAC row, so it outranks even a
     * local EVPN-database row when both are present.
     */
    public const SOURCE_EVPN_MAC_IP = 'evpn-mac-ip';

    public const SOURCE_EVPN_LOCAL = 'evpn-local';

    public const SOURCE_EVPN_ESI = 'evpn-esi';

    /** The fabric knows the MAC is on an Ethernet Segment, but no monitored PE reports a LAG for it. */
    public const SOURCE_EVPN_ESI_UNKNOWN = 'evpn-esi-unknown';

    public const SOURCE_EVPN_REMOTE = 'evpn-remote';

    public const SOURCE_FDB = 'fdb';

    public const SOURCE_ARP = 'arp';

    /** Most trustworthy first; the resolver ranks candidates by this order. */
    public const RANK = [self::SOURCE_EVPN_MAC_IP, self::SOURCE_EVPN_LOCAL, self::SOURCE_EVPN_ESI, self::SOURCE_FDB, self::SOURCE_ARP, self::SOURCE_EVPN_ESI_UNKNOWN, self::SOURCE_EVPN_REMOTE];

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
        /** The designated forwarder of the segment, for a multihomed attachment. */
        public readonly bool $df = false,
    ) {
    }

    /**
     * Whether this candidate says where the endpoint hangs, rather than only that it exists.
     *
     * An `esi` row on a leaf is *not* one: every leaf in the fabric reports a multihomed MAC
     * with the Ethernet Segment as its active source, so the reporting leaf says nothing about
     * where the segment is. Only the PEs that own the segment do, and those are separate
     * candidates the resolver expands to.
     */
    public function isAttachment(): bool
    {
        return $this->address !== null
            && $this->source !== self::SOURCE_EVPN_REMOTE
            && $this->source !== self::SOURCE_EVPN_ESI_UNKNOWN;
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
            'df' => $this->df,
            'attachment' => $this->isAttachment(),
        ];
    }
}
