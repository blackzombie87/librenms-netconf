<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One saved arrangement of the fabric overview per fabric, shown to every operator who opens
 * the page. The camera (pan and zoom) stays a browser preference; where the sites and cards
 * were dropped is a fact about the fabric and belongs with it.
 *
 * `fabric_id` is an unsignedInteger with a unique index and not a foreign key, which is what
 * every other table in this plugin does — `netconf_evpn_fabric` declares none either, and
 * `FabricResolver::cleanup()` and the uninstaller delete the rows explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netconf_evpn_fabric_layout', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('fabric_id')->unique();
            // written only after the controller has checked the ids against a fresh place(),
            // so this is a validated document and not a string off a device
            $table->json('positions');
            $table->unsignedInteger('user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netconf_evpn_fabric_layout');
    }
};
