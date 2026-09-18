<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netconf_metrics', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('device_id')->index();
            $table->string('definition', 64);
            $table->string('mapping', 64);
            $table->string('metric_index', 191);
            $table->string('descr', 255)->default('');
            $table->string('group', 64)->nullable();
            $table->text('values')->nullable();               // json: field => last numeric value
            $table->text('labels')->nullable();               // json: field => text value
            $table->dateTime('last_seen')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'definition', 'mapping', 'metric_index'], 'netconf_metrics_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netconf_metrics');
    }
};
