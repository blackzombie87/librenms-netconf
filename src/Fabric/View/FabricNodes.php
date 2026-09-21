<?php

namespace SafferIt\LibrenmsNetconf\Fabric\View;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Definitions\TableSchema;
use SafferIt\LibrenmsNetconf\Fabric\FabricGraph;

/**
 * Address book of a fabric: every VTEP / router-id address of its members resolved to the
 * device (when monitored), a display name (device name, else the BGP description, else the
 * address) and the role. Shared by the tabs so that a peer address is always shown the same way.
 */
final class FabricNodes
{
    /** @var array<string, array{vtep_ip: string, device_id: int|null, device: Device|null, name: string, role: string, border: bool, member: bool}> */
    private array $nodes = [];

    /** @var array<int, list<string>> device_id => addresses (member address first) */
    private array $deviceNodes = [];

    /**
     * Build from arrays (tests, replays): member rows first, aliases after their device's member.
     *
     * @param  list<array{vtep_ip: string, device_id?: int|null, name?: string, role?: string, border?: bool, member?: bool}>  $nodes
     */
    public static function fromArray(array $nodes): self
    {
        $self = new self;
        foreach ($nodes as $n) {
            $ip = $n['vtep_ip'];
            $deviceId = $n['device_id'] ?? null;
            $self->nodes[$ip] = [
                'vtep_ip' => $ip,
                'device_id' => $deviceId,
                'device' => null,
                'name' => $n['name'] ?? $ip,
                'role' => $n['role'] ?? FabricGraph::ROLE_UNKNOWN,
                'border' => $n['border'] ?? false,
                'member' => $n['member'] ?? true,
            ];
            if ($deviceId !== null) {
                $self->deviceNodes[$deviceId][] = $ip;
            }
        }

        return $self;
    }

    public static function forFabric(int $fabricId): self
    {
        $self = new self;
        $members = DB::table(TableSchema::tableName('fabric_member') . ' as m')
            ->join(TableSchema::tableName('vtep') . ' as v', 'v.vtep_ip', '=', 'm.vtep_ip')
            ->where('m.fabric_id', $fabricId)
            ->get(['m.vtep_ip', 'm.role', 'v.device_id', 'v.border', 'v.name_hint']);
        $deviceIds = $members->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        /** @var \Illuminate\Support\Collection<int, Device> $devices */
        $devices = Device::query()->whereIn('device_id', $deviceIds ?: [0])->get()->keyBy('device_id');

        foreach ($members as $m) {
            $deviceId = $m->device_id === null ? null : (int) $m->device_id;
            $device = $deviceId === null ? null : $devices->get($deviceId);
            $self->nodes[(string) $m->vtep_ip] = [
                'vtep_ip' => (string) $m->vtep_ip,
                'device_id' => $deviceId,
                'device' => $device,
                'name' => $device?->displayName() ?? ($m->name_hint ?: (string) $m->vtep_ip),
                'role' => (string) $m->role,
                'border' => (bool) $m->border,
                'member' => true,
            ];
            if ($deviceId !== null) {
                $self->deviceNodes[$deviceId] = [(string) $m->vtep_ip];
            }
        }
        // alias addresses of the member devices (router-ids that differ from the VTEP)
        foreach (DB::table(TableSchema::tableName('vtep'))->whereIn('device_id', $deviceIds ?: [0])->get(['vtep_ip', 'device_id', 'role', 'border', 'name_hint']) as $v) {
            $ip = (string) $v->vtep_ip;
            if (isset($self->nodes[$ip])) {
                continue;
            }
            $deviceId = (int) $v->device_id;
            $self->nodes[$ip] = [
                'vtep_ip' => $ip,
                'device_id' => $deviceId,
                'device' => $devices->get($deviceId),
                'name' => $devices->get($deviceId)?->displayName() ?? $ip,
                'role' => (string) $v->role,
                'border' => (bool) $v->border,
                'member' => false,
            ];
            $self->deviceNodes[$deviceId][] = $ip;
        }

        return $self;
    }

    /**
     * @return array{vtep_ip: string, device_id: int|null, device: Device|null, name: string, role: string, border: bool, member: bool}
     */
    public function get(string $ip): array
    {
        return $this->nodes[$ip] ?? ['vtep_ip' => $ip, 'device_id' => null, 'device' => null, 'name' => $ip, 'role' => FabricGraph::ROLE_UNKNOWN, 'border' => false, 'member' => false];
    }

    public function has(string $ip): bool
    {
        return isset($this->nodes[$ip]);
    }

    public function name(string $ip): string
    {
        return $this->get($ip)['name'];
    }

    public function device(string $ip): ?Device
    {
        return $this->get($ip)['device'];
    }

    public function deviceId(string $ip): ?int
    {
        return $this->get($ip)['device_id'];
    }

    /** The member address of a device (its first node). */
    public function addressOf(int $deviceId): ?string
    {
        return $this->deviceNodes[$deviceId][0] ?? null;
    }

    /**
     * @return array<int, list<string>>
     */
    public function deviceNodes(): array
    {
        return $this->deviceNodes;
    }

    /**
     * @return list<int>
     */
    public function deviceIds(): array
    {
        return array_keys($this->deviceNodes);
    }

    /**
     * The address a peer is drawn and counted under: the member address of its device when the
     * address is a monitored device's alias (router-id), otherwise the address itself.
     */
    public function canonical(string $ip): string
    {
        $deviceId = $this->deviceId($ip);

        return $deviceId === null ? $ip : ($this->addressOf($deviceId) ?? $ip);
    }

    /**
     * @return array<string, array{vtep_ip: string, device_id: int|null, device: Device|null, name: string, role: string, border: bool, member: bool}>
     */
    public function all(): array
    {
        return $this->nodes;
    }
}
