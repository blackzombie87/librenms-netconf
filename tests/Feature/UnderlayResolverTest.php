<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\DB;
use SafferIt\LibrenmsNetconf\Fabric\EsiLinks;
use SafferIt\LibrenmsNetconf\Fabric\UnderlayEdge;
use SafferIt\LibrenmsNetconf\Fabric\UnderlayResolver;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * UnderlayResolver against the core tables (F5 2): only core discovery protocols count as
 * physical evidence. The plugin's own evpn-esi rows in `links` describe a multihoming
 * relation between two PEs, so they yield neither an lldp-only edge nor the lldp flag of an
 * IP candidate; a real LLDP row does both.
 */
final class UnderlayResolverTest extends LibrenmsTestCase
{
    public function testSyntheticEsiLinksAreNotPhysicalEvidence(): void
    {
        [$a, $b, $portA, $portB] = $this->twoLeaves();
        $this->link($a, $portA, $b, $portB, EsiLinks::PROTOCOL);

        $this->assertSame([], (new UnderlayResolver)->resolve([$a->device_id, $b->device_id]));
    }

    public function testARealLldpLinkIsAnLldpOnlyEdge(): void
    {
        [$a, $b, $portA, $portB] = $this->twoLeaves();
        $this->link($a, $portA, $b, $portB, 'lldp');
        $this->link($a, $portA, $b, $portB, EsiLinks::PROTOCOL);   // the ESI row next to it changes nothing

        $edges = (new UnderlayResolver)->resolve([$a->device_id, $b->device_id]);

        $this->assertCount(1, $edges);
        $this->assertSame('lldp-only', $edges[0]->protocol);
        $this->assertTrue($edges[0]->lldp);
        $this->assertSame([$portA->port_id, $portB->port_id], [$edges[0]->aPortId, $edges[0]->bPortId]);
    }

    public function testAnIpCandidateIsLldpConfirmedByCoreRowsOnly(): void
    {
        [$a, $b, $portA, $portB] = $this->twoLeaves();
        // addresses sit on the units, LLDP on the physical ports
        $unitA = Port::factory()->create(['device_id' => $a->device_id, 'ifName' => 'et-0/0/1.0', 'ifIndex' => 1501]);
        $unitB = Port::factory()->create(['device_id' => $b->device_id, 'ifName' => 'et-0/0/1.0', 'ifIndex' => 1501]);
        $network = (int) DB::table('ipv4_networks')->insertGetId(['ipv4_network' => '10.0.0.0/31']);
        DB::table('ipv4_addresses')->insert([
            ['ipv4_address' => '10.0.0.0', 'ipv4_prefixlen' => 31, 'ipv4_network_id' => $network, 'port_id' => $unitA->port_id],
            ['ipv4_address' => '10.0.0.1', 'ipv4_prefixlen' => 31, 'ipv4_network_id' => $network, 'port_id' => $unitB->port_id],
        ]);
        $resolve = fn () => (new UnderlayResolver)->resolve([$a->device_id, $b->device_id]);

        $this->link($a, $portA, $b, $portB, EsiLinks::PROTOCOL);
        $edges = $resolve();
        $this->assertCount(1, $edges);
        $this->assertSame(['ip', false], [$edges[0]->protocol, $edges[0]->lldp]);

        $this->link($a, $portA, $b, $portB, 'lldp');
        $edges = $resolve();
        $this->assertCount(1, $edges);
        $this->assertInstanceOf(UnderlayEdge::class, $edges[0]);
        $this->assertSame(['ip', true], [$edges[0]->protocol, $edges[0]->lldp]);
    }

    public function testAnIsIsAdjacencyIsAThirdSessionSourceBesideBgpAndOspf(): void
    {
        // plan §12.4a / T10: an IS-IS underlay is as normal as OSPF or eBGP, and until now it
        // produced a stateless `ip` edge. No IS-IS fabric exists to check this against, so
        // this test is the whole guarantee.
        [$a, $b, $unitA, $unitB] = $this->sharedSubnet();
        $resolve = fn () => (new UnderlayResolver)->resolve([$a->device_id, $b->device_id]);

        $edges = $resolve();
        $this->assertSame(['ip', null], [$edges[0]->protocol, $edges[0]->state]);

        DB::table('isis_adjacencies')->insert([
            ['device_id' => $a->device_id, 'port_id' => $unitA->port_id, 'ifIndex' => 1501, 'isisISAdjState' => 'up', 'isisISAdjIPAddrAddress' => '10.0.0.1', 'isisCircAdminState' => 'on'],
            ['device_id' => $b->device_id, 'port_id' => $unitB->port_id, 'ifIndex' => 1501, 'isisISAdjState' => 'up', 'isisISAdjIPAddrAddress' => '10.0.0.0', 'isisCircAdminState' => 'on'],
        ]);
        $edges = $resolve();
        $this->assertCount(1, $edges);
        $this->assertSame(['isis', 'up'], [$edges[0]->protocol, $edges[0]->state]);
        $this->assertTrue(\SafferIt\LibrenmsNetconf\Fabric\View\Topology::sessionUp('isis', 'up'));

        // and it joins a protocol set rather than replacing one
        DB::table('ospf_nbrs')->insert([
            ['device_id' => $a->device_id, 'ospf_nbr_id' => 'x1', 'ospfNbrIpAddr' => '10.0.0.1', 'ospfNbrRtrId' => '192.0.2.2', 'ospfNbrState' => 'full', 'ospfNbrAddressLessIndex' => 0, 'ospfNbrOptions' => 0, 'ospfNbrPriority' => 1, 'ospfNbrEvents' => 0, 'ospfNbrLsRetransQLen' => 0, 'ospfNbmaNbrStatus' => 'active', 'ospfNbmaNbrPermanence' => 'dynamic', 'ospfNbrHelloSuppressed' => 'false', 'context_name' => ''],
        ]);
        $edges = $resolve();
        $this->assertSame('ospf,isis', $edges[0]->protocol);
        $this->assertSame('full/up', $edges[0]->state);
        $this->assertTrue(\SafferIt\LibrenmsNetconf\Fabric\View\Topology::sessionUp('ospf,isis', 'full/up'));
    }

    /**
     * Two leaves whose unit addresses share one /31, with no session on it yet.
     *
     * @return array{Device, Device, Port, Port}
     */
    private function sharedSubnet(): array
    {
        [$a, $b] = $this->twoLeaves();
        $unitA = Port::factory()->create(['device_id' => $a->device_id, 'ifName' => 'et-0/0/1.0', 'ifIndex' => 1501]);
        $unitB = Port::factory()->create(['device_id' => $b->device_id, 'ifName' => 'et-0/0/1.0', 'ifIndex' => 1501]);
        $network = (int) DB::table('ipv4_networks')->insertGetId(['ipv4_network' => '10.0.0.0/31']);
        DB::table('ipv4_addresses')->insert([
            ['ipv4_address' => '10.0.0.0', 'ipv4_prefixlen' => 31, 'ipv4_network_id' => $network, 'port_id' => $unitA->port_id],
            ['ipv4_address' => '10.0.0.1', 'ipv4_prefixlen' => 31, 'ipv4_network_id' => $network, 'port_id' => $unitB->port_id],
        ]);

        return [$a, $b, $unitA, $unitB];
    }

    /**
     * @return array{Device, Device, Port, Port}
     */
    private function twoLeaves(): array
    {
        $a = Device::factory()->create(['hostname' => 'leaf-a.example.net', 'os' => 'junos']);
        $b = Device::factory()->create(['hostname' => 'leaf-b.example.net', 'os' => 'junos']);
        $portA = Port::factory()->create(['device_id' => $a->device_id, 'ifName' => 'et-0/0/1', 'ifIndex' => 501]);
        $portB = Port::factory()->create(['device_id' => $b->device_id, 'ifName' => 'et-0/0/1', 'ifIndex' => 501]);

        return [$a, $b, $portA, $portB];
    }

    private function link(Device $local, Port $localPort, Device $remote, Port $remotePort, string $protocol): void
    {
        DB::table('links')->insert([
            'local_port_id' => $localPort->port_id, 'local_device_id' => $local->device_id, 'remote_port_id' => $remotePort->port_id,
            'active' => 1, 'protocol' => $protocol, 'remote_hostname' => $remote->hostname, 'remote_device_id' => $remote->device_id,
            'remote_port' => (string) $remotePort->ifName, 'remote_platform' => null, 'remote_version' => '',
        ]);
    }
}
