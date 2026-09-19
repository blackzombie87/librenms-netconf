<?php

namespace SafferIt\LibrenmsNetconf\Fabric;

/**
 * One underlay link between a fabric member and its neighbour (plan §7.6). Side a is always
 * a monitored device; side b is a device when the shared subnet is known on both ends,
 * otherwise only the neighbour address of a routing session (b_address) and, for OSPF, the
 * neighbour's router-id (b_vtep_ip, the VTEP loopback in loopback-as-router-id designs).
 */
final class UnderlayEdge
{
    public function __construct(
        public readonly int $aDeviceId,
        public readonly ?int $aPortId,
        public readonly ?string $aAddress,
        public readonly ?int $bDeviceId,
        public readonly ?int $bPortId,
        public readonly ?string $bAddress,
        public readonly ?string $bVtepIp,
        public readonly ?string $network,
        public string $protocol,
        public ?string $state,
        public bool $lldp = false,
        public bool $wan = false,
    ) {
    }

    public function key(): string
    {
        return sprintf('%d:%s|%s:%s|%s', $this->aDeviceId, $this->aPortId ?? '-', $this->bDeviceId ?? '-', $this->bPortId ?? '-', $this->bAddress ?? '-');
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'link_key' => mb_substr($this->key(), 0, 191),
            'a_device_id' => $this->aDeviceId,
            'a_port_id' => $this->aPortId,
            'a_address' => $this->aAddress,
            'b_device_id' => $this->bDeviceId,
            'b_port_id' => $this->bPortId,
            'b_address' => $this->bAddress,
            'b_vtep_ip' => $this->bVtepIp,
            'network' => $this->network,
            'protocol' => $this->protocol,
            'state' => $this->state === null ? null : mb_substr($this->state, 0, 32),
            'lldp' => $this->lldp ? 1 : 0,
            'wan' => $this->wan ? 1 : 0,
        ];
    }
}
