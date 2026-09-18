<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netconf_port_metrics', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedInteger('port_id');
            $table->string('definition', 64);
            $table->string('mapping', 64);
            $table->text('values')->nullable();               // json: field => last value
            $table->text('types')->nullable();                // json: field => GAUGE|COUNTER|DERIVE
            $table->dateTime('last_seen')->nullable();
            $table->timestamps();
            $table->unique(['port_id', 'definition', 'mapping'], 'netconf_port_metrics_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netconf_port_metrics');
    }
};
