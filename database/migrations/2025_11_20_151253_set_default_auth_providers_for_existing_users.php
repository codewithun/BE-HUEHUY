<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Set auth_providers untuk user yang sudah ada
        // User dengan password = punya provider 'local'
        // User dengan firebase_uid & tanpa password = punya provider 'google'

        DB::table('users')
            ->whereNull('auth_providers')
            ->whereNotNull('password')
            ->update(['auth_providers' => json_encode(['local'])]);

        DB::table('users')
            ->whereNull('auth_providers')
            ->whereNotNull('firebase_uid')
            ->whereNull('password')
            ->update(['auth_providers' => json_encode(['google'])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tidak perlu rollback, karena ini hanya set default value
    }
};
