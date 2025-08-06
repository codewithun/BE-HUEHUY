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
        Schema::table('cubes', function (Blueprint $table) {
            $table->boolean('is_information')->default(false)->after('is_recommendation');
            $table->string('link_information')->nullable()->after('is_information');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cubes', function (Blueprint $table) {
            $table->dropColumn('is_information');
        });
    }
};
