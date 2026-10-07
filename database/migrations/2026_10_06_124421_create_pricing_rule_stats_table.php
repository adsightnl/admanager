<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rule_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('os');
            $table->string('rule_name');
            $table->string('site');
            $table->string('ad_unit');
            $table->decimal('revenue', 14, 4)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('requests')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique(['date', 'os', 'rule_name', 'site', 'ad_unit']);
            $table->index(['date', 'rule_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rule_stats');
    }
};
