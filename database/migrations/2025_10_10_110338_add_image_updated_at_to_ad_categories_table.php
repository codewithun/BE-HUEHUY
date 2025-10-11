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
            $table->timestamp('image_updated_at')->nullable()->after('picture_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_categories', function (Blueprint $table) {
            $table->dropColumn('image_updated_at');
        });
    }
};
