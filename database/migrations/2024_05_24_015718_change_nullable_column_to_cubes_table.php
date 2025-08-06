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
            $table->string('address')->nullable()->change();
            $table->double('map_lat')->nullable()->change();
            $table->double('map_lng')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cubes', function (Blueprint $table) {
            $table->string('address')->nullable(false)->change();
            $table->double('map_lat')->nullable(false)->change();
            $table->double('map_lng')->nullable(false)->change();
        });
    }
};
