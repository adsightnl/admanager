<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_stats', function (Blueprint $table) {
            // Null for rows synced before clicks were, so their CTR is unknown rather than 0%.
            $table->unsignedBigInteger('clicks')->nullable()->after('impressions');
        });
    }

    public function down(): void
    {
        Schema::table('device_stats', function (Blueprint $table) {
            $table->dropColumn('clicks');
        });
    }
};
