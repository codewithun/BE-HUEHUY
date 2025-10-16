<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend enum values for `dynamic_contents.type` to include 'information'
        try {
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN type ENUM('home','hunting','information') NOT NULL");
        } catch (\Throwable $e) {
            // On non-MySQL drivers or if enum not supported, silently ignore.
            // If you're on PostgreSQL/SQLite, consider changing the column to VARCHAR via a dedicated migration.
        }
    }

    public function down(): void
    {
        try {
            // Normalize existing rows that use 'information' back to 'home' before shrinking enum
            try {
                DB::table('dynamic_contents')->where('type', 'information')->update(['type' => 'home']);
            } catch (\Throwable $e) {
                // ignore if table/column missing in rollback context
            }
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN type ENUM('home','hunting') NOT NULL");
        } catch (\Throwable $e) {
            // ignore on non-MySQL or if enum not supported
        }
    }
};
