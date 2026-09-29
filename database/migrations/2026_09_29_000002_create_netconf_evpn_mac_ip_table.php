<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP -> MAC -> bridge domain -> local interface per leaf (plan §12.10 T7), from
 * `show mac-vrf forwarding mac-ip-table`. Only the entries a leaf owns locally are stored:
 * a remote entry is another leaf's local row seen through a tunnel, and keeping it would
 * multiply the table by the number of leaves without adding an answer.
 *
 * This is what lets a trace start and end at an access port instead of at a VTEP — the
 * device states the binding rather than the tracer inferring it from the MAC database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('netconf_evpn_mac_ip')) {
            return;
        }

        Schema::create('netconf_evpn_mac_ip', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->string('bridge_domain', 64);          // VX91 - joins vni.vlan_name
            $table->string('ip_address', 45)->index();    // v4 and v6, incl. link-local
            $table->string('mac_address', 12)->index();   // 12 hex like ports_fdb
            $table->string('instance', 64)->nullable();
            $table->string('ifname', 64)->nullable();     // ae36.0 / xe-0/0/19.0
            $table->unsignedInteger('port_id')->nullable();
            $table->string('flags', 32)->nullable();      // DRLp,K,AD
            $table->dateTime('last_seen')->nullable();
            $table->unique(['device_id', 'bridge_domain', 'ip_address'], 'netconf_evpn_mac_ip_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netconf_evpn_mac_ip');
    }
};
