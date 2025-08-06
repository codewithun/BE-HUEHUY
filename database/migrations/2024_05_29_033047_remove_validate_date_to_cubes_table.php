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
            $table->dropColumn('start_validate');
            $table->dropColumn('finish_validate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cubes', function (Blueprint $table) {
            $table->date('start_validate')->nullable()->after('expired_activate_date');
            $table->date('finish_validate')->nullable()->after('start_validate');
        });
    }
};
