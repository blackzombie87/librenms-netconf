<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('netconf_metrics', function (Blueprint $table) {
            $table->text('types')->nullable()->after('values');   // json: field => GAUGE|COUNTER|DERIVE, in RRD order
        });
    }

    public function down(): void
    {
        Schema::table('netconf_metrics', function (Blueprint $table) {
            $table->dropColumn('types');
        });
    }
};
