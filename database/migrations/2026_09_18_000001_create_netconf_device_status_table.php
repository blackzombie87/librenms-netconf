<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netconf_device_status', function (Blueprint $table) {
            $table->unsignedInteger('device_id')->primary();
            $table->string('transport', 16)->nullable();
            $table->text('definitions')->nullable();           // json list of matched definition names
            $table->unsignedInteger('poll_count')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->dateTime('last_ok')->nullable();
            $table->dateTime('last_attempt')->nullable();
            $table->dateTime('next_attempt')->nullable();      // back-off: skip polls until then
            $table->text('last_error')->nullable();
            $table->float('last_duration')->nullable();
            $table->text('last_summary')->nullable();          // json counts of the last run
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netconf_device_status');
    }
};
