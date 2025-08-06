<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->integer('max_production_per_day')->nullable()->after('viewer');
            $table->integer('sell_per_day')->nullable()->after('max_production_per_day');
            $table->tinyInteger('level_umkm')->nullable()->after('sell_per_day');
            $table->boolean('pre_order')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->dropColumn('max_production_per_day');
            $table->dropColumn('sell_per_day');
            $table->dropColumn('level_umkm');
        });
    }
};
