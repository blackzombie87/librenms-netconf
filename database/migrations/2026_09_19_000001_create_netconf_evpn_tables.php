<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVPN fabric view tables (plan §7.3 and §7.6). Per-leaf rows (neighbor, esi, vni, vni_vtep,
 * tunnel, mac) are filled by `tables:` mappings; vtep, fabric, fabric_member and underlay_link
 * by the FabricResolver.
 */
return new class extends Migration
{
    public const TABLES = [
        'netconf_evpn_neighbor', 'netconf_evpn_esi', 'netconf_evpn_vni', 'netconf_evpn_vni_vtep', 'netconf_evpn_tunnel',
        'netconf_evpn_mac', 'netconf_evpn_vtep', 'netconf_evpn_fabric', 'netconf_evpn_fabric_member', 'netconf_evpn_underlay_link',
    ];

    public function up(): void
    {
        // one directed EVPN neighbour edge per leaf, with route counts by type
        Schema::create('netconf_evpn_neighbor', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->string('instance', 64);
            $table->string('neighbor_ip', 45)->index();
            $table->string('router_id', 45)->nullable();
            $table->unsignedInteger('mac_routes')->nullable();
            $table->unsignedInteger('mac_ip_routes')->nullable();
            $table->unsignedInteger('ead_routes')->nullable();
            $table->unsignedInteger('imet_routes')->nullable();
            $table->unsignedInteger('es_routes')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->unique(['device_id', 'instance', 'neighbor_ip'], 'netconf_evpn_neighbor_key');
        });

        // ESIs as seen by one leaf: local ESI-LAG (if any), DF/BDF, remote PEs (plan §3.8)
        Schema::create('netconf_evpn_esi', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->string('esi', 32)->index();
            $table->string('instance', 64)->nullable();
            $table->string('local_ifname', 64)->nullable();
            $table->unsignedInteger('local_port_id')->nullable();
            $table->string('mode', 16)->nullable();          // all-active / single-active
            $table->string('status', 64)->nullable();        // Resolved by IFL ae2.0 / Unresolved
            $table->string('lag_status', 32)->nullable();    // Up/Forwarding / Down
            $table->boolean('is_df')->nullable();
            $table->string('df_ip', 45)->nullable();
            $table->string('bdf_ip', 45)->nullable();
            $table->boolean('aliasing')->nullable();
            $table->text('remote_vtep_ips')->nullable();     // json list
            $table->unsignedInteger('remote_mac_count')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->unique(['device_id', 'esi'], 'netconf_evpn_esi_key');
        });

        // VNIs configured on a leaf; vlan tag from the remote MAC table, IRB from the gateway
        Schema::create('netconf_evpn_vni', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedInteger('vni')->index();
            $table->string('instance', 64)->nullable();
            $table->unsignedInteger('vlan_id')->nullable();
            $table->string('vlan_name', 64)->nullable();
            $table->string('source_vtep', 45)->nullable();
            $table->string('multicast_group', 45)->nullable();
            $table->string('rd', 64)->nullable();
            $table->string('rt', 64)->nullable();
            $table->unsignedInteger('interfaces')->nullable();
            $table->unsignedInteger('interfaces_up')->nullable();
            $table->boolean('mac_sync')->nullable();
            $table->string('irb_ifname', 64)->nullable();
            $table->string('irb_status', 32)->nullable();
            $table->unsignedInteger('remote_macs')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->unique(['device_id', 'vni'], 'netconf_evpn_vni_key');
        });

        // flood list: which remote VTEP a leaf has for a VNI
        Schema::create('netconf_evpn_vni_vtep', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedInteger('vni');
            $table->string('remote_vtep_ip', 45)->index();
            $table->string('instance', 64)->nullable();
            $table->string('flags', 32)->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->unique(['device_id', 'vni', 'remote_vtep_ip'], 'netconf_evpn_vni_vtep_key');
        });

        // VXLAN tunnels: kernel vtep.N IFL (the ports row via snmp-index) per remote VTEP
        Schema::create('netconf_evpn_tunnel', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->string('remote_vtep_ip', 45)->index();
            $table->string('ifname', 32)->nullable();          // vtep.32771
            $table->unsignedInteger('snmp_index')->nullable();
            $table->unsignedInteger('port_id')->nullable();
            $table->string('ri_ifname', 32)->nullable();       // vtep-4.32771 (per routing instance)
            $table->string('mode', 16)->nullable();            // RNVE
            $table->unsignedInteger('nh_id')->nullable();
            $table->unsignedInteger('mac_count')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->unique(['device_id', 'remote_vtep_ip'], 'netconf_evpn_tunnel_key');
        });

        // EVPN MAC database per leaf (opt-in, plan §7.1 caveat 3); mac_address 12 hex like ports_fdb
        Schema::create('netconf_evpn_mac', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedInteger('vni');
            $table->string('mac_address', 12)->index();
            $table->string('instance', 64)->nullable();
            $table->text('ip_addresses')->nullable();         // json list
            $table->string('source_type', 8)->nullable();     // local / esi / remote
            $table->string('source', 64)->nullable();         // IFL, ESI or VTEP IP
            $table->unsignedInteger('source_device_id')->nullable();
            $table->dateTime('active_since')->nullable();
            $table->unsignedInteger('seq')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->unsignedInteger('moves')->default(0);
            $table->dateTime('first_seen')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->unique(['device_id', 'vni', 'mac_address'], 'netconf_evpn_mac_key');
        });

        // every VTEP / EVPN speaker seen anywhere, resolved to a device where possible
        Schema::create('netconf_evpn_vtep', function (Blueprint $table) {
            $table->increments('id');
            $table->string('vtep_ip', 45)->unique();
            $table->unsignedInteger('device_id')->nullable()->index();   // null = not monitored
            $table->string('router_id', 45)->nullable();
            $table->string('role', 16)->default('unknown');   // leaf / spine / gateway / unknown
            $table->boolean('border')->default(false);        // has L3 contexts / type-5 prefixes
            $table->string('name_hint', 255)->nullable();     // BGP description on a peer
            $table->dateTime('first_seen')->nullable();
            $table->dateTime('last_seen')->nullable();
        });

        // one row per connected component of the overlay/underlay graph
        Schema::create('netconf_evpn_fabric', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 64);
            $table->string('key', 45)->unique();              // lowest VTEP IP of the component
            $table->boolean('auto')->default(true);
            $table->boolean('mac_table')->default(false);
            $table->text('notes')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('netconf_evpn_fabric_member', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('fabric_id')->index();
            $table->string('vtep_ip', 45)->unique();
            $table->string('role', 16)->default('unknown');
            $table->boolean('pinned')->default(false);        // manual assignment survives recompute
            $table->dateTime('since')->nullable();
        });

        // underlay edges between fabric members from core ipv4/BGP/OSPF/LLDP tables (plan §7.6)
        Schema::create('netconf_evpn_underlay_link', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('fabric_id')->nullable()->index();
            $table->string('link_key', 191)->unique();
            $table->unsignedInteger('a_device_id')->index();
            $table->unsignedInteger('a_port_id')->nullable();
            $table->string('a_address', 45)->nullable();
            $table->unsignedInteger('b_device_id')->nullable()->index();   // null = other end not monitored
            $table->unsignedInteger('b_port_id')->nullable();
            $table->string('b_address', 45)->nullable();
            $table->string('b_vtep_ip', 45)->nullable();      // far end's router-id when known (OSPF)
            $table->string('network', 64)->nullable();
            $table->string('protocol', 16)->default('ip');    // bgp / ospf / bgp,ospf / lldp-only / ip
            $table->string('state', 32)->nullable();
            $table->boolean('lldp')->default(false);
            $table->boolean('wan')->default(false);           // multi-site path, other end outside the fabric
            $table->dateTime('first_seen')->nullable();
            $table->dateTime('last_seen')->nullable();
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
