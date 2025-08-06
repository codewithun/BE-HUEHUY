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
        Schema::table('cube_tags', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
            $table->string('map_lat')->nullable()->change();
            $table->string('map_lng')->nullable()->change();
            $table->string('link')->nullable()->after('map_lng');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cube_tags', function (Blueprint $table) {
            $table->string('address')->nullable(false)->change();
            $table->string('map_lat')->nullable(false)->change();
            $table->string('map_lng')->nullable(false)->change();
            $table->dropColumn('link');
        });
    }
};
