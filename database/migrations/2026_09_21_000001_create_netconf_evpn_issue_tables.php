<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVPN fabric checks (plan §7.5): the current issues per fabric with the devices each one
 * involves, and the MAC mobility window on the MAC database rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // one row per open issue; issue_key = check|subject is stable across resolves so
        // first_seen survives and the eventlog only sees appear / clear / severity change
        Schema::create('netconf_evpn_issue', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('fabric_id')->index();
            $table->string('issue_key', 191);
            $table->string('check', 32)->index();
            $table->string('severity', 8);                    // critical / warning / info
            $table->string('subject', 191);
            $table->text('message');
            $table->text('details')->nullable();              // json
            $table->dateTime('first_seen');
            $table->dateTime('last_seen');
            $table->unique(['fabric_id', 'issue_key'], 'netconf_evpn_issue_key');
        });

        // the monitored devices an issue involves (the per-leaf "EVPN fabric issues" sensor counts these)
        Schema::create('netconf_evpn_issue_device', function (Blueprint $table) {
            $table->unsignedInteger('issue_id');
            $table->unsignedInteger('device_id')->index();
            $table->primary(['issue_id', 'device_id']);
        });

        // MAC mobility: moves inside the current window (check 7), window start
        Schema::table('netconf_evpn_mac', function (Blueprint $table) {
            $table->unsignedInteger('moves_recent')->default(0)->after('moves');
            $table->dateTime('moves_since')->nullable()->after('moves_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netconf_evpn_issue_device');
        Schema::dropIfExists('netconf_evpn_issue');
        Schema::table('netconf_evpn_mac', function (Blueprint $table) {
            $table->dropColumn(['moves_recent', 'moves_since']);
        });
    }
};
