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
        Schema::table('ad_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('ad_categories', 'community_id')) {
                $table->unsignedBigInteger('community_id')->nullable()->after('id');
                $table->foreign('community_id')
                      ->references('id')->on('communities')
                      ->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_categories', function (Blueprint $table) {
            if (Schema::hasColumn('ad_categories', 'community_id')) {
                $table->dropForeign(['community_id']);
                $table->dropColumn('community_id');
            }
        });
    }
};
