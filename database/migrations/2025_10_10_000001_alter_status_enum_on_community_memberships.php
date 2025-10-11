<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Extend enum to include 'pending' and 'removed'
            DB::statement("ALTER TABLE community_memberships MODIFY COLUMN status ENUM('active','pending','inactive','removed','banned') NOT NULL DEFAULT 'active'");
        } elseif ($driver === 'pgsql') {
            // Update CHECK constraint to allow new values
            try {
                DB::statement("ALTER TABLE community_memberships DROP CONSTRAINT IF EXISTS cm_status_check");
            } catch (\Throwable $e) {
                // ignore
            }
            DB::statement("ALTER TABLE community_memberships ADD CONSTRAINT cm_status_check CHECK (status IN ('active','pending','inactive','removed','banned'))");
        } else {
            // For other drivers, attempt a generic alter if possible (may be a no-op)
            try {
                DB::statement("ALTER TABLE community_memberships ALTER COLUMN status SET DEFAULT 'active'");
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Revert to the original enum set if needed
            DB::statement("ALTER TABLE community_memberships MODIFY COLUMN status ENUM('active','inactive','banned') NOT NULL DEFAULT 'active'");
        } elseif ($driver === 'pgsql') {
            try {
                DB::statement("ALTER TABLE community_memberships DROP CONSTRAINT IF EXISTS cm_status_check");
            } catch (\Throwable $e) {
                // ignore
            }
            DB::statement("ALTER TABLE community_memberships ADD CONSTRAINT cm_status_check CHECK (status IN ('active','inactive','banned'))");
        }
    }
};
