<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The L3 context (routing instance / VRF) an anycast IRB sits in, for the routed tracer
 * (plan §12.9 T6a). Two VNIs can only route to each other where one gateway has an IRB in
 * both **and both IRBs are in the same context** — `master` on a centrally-routed fabric, a
 * VRF name where EVPN type-5 is configured. Without it "both VNIs have an IRB on GW1" is a
 * guess: the two IRBs may be in different VRFs and never reach one another.
 *
 * Nullable on purpose: a leaf has no IRB at all, and a release that has not re-polled yet
 * has the column empty, which the tracer reads as *unknown* rather than as *no*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('netconf_evpn_vni', function (Blueprint $table) {
            $table->string('irb_l3_context', 64)->nullable()->after('irb_status');
        });
    }

    public function down(): void
    {
        Schema::table('netconf_evpn_vni', function (Blueprint $table) {
            $table->dropColumn('irb_l3_context');
        });
    }
};
