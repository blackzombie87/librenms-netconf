<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * types now means "data source order of the RRD as verified on the last write". Rows
     * written by earlier versions hold the fields of one reply (or the mapping order)
     * instead, so they are cleared and re-verified against the files on the next poll.
     */
    public function up(): void
    {
        DB::table('netconf_port_metrics')->update(['types' => null]);
        DB::table('netconf_metrics')->update(['types' => null]);
    }

    public function down(): void
    {
    }
};
