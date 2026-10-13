<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rule_stats', function (Blueprint $table) {
            $table->decimal('ecpm', 14, 4)->default(0)->after('requests');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rule_stats', function (Blueprint $table) {
            $table->dropColumn('ecpm');
        });
    }
};
