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
        Schema::table('users', function (Blueprint $table) {
            // Kolom untuk menyimpan Firebase UID
            $table->string('firebase_uid')->nullable()->unique()->after('id');

            // Kolom JSON untuk menyimpan providers (local, google, dll)
            $table->json('auth_providers')->nullable()->after('firebase_uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['firebase_uid', 'auth_providers']);
        });
    }
};
