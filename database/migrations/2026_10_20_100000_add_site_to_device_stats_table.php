<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rows without a site cannot be split per domain; they are re-pulled by device-stats:sync.
        DB::table('device_stats')->delete();

        Schema::table('device_stats', function (Blueprint $table) {
            $table->dropUnique(['date', 'os', 'device']);
            $table->string('site')->default('')->after('device');
            $table->unique(['date', 'os', 'device', 'site']);
        });
    }

    public function down(): void
    {
        DB::table('device_stats')->delete();

        Schema::table('device_stats', function (Blueprint $table) {
            $table->dropUnique(['date', 'os', 'device', 'site']);
            $table->dropColumn('site');
            $table->unique(['date', 'os', 'device']);
        });
    }
};
