<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE community_memberships MODIFY COLUMN status ENUM('active','pending','inactive','removed','left','banned') NOT NULL DEFAULT 'active'");
        } else {
            // Postgres or others: adjust CHECK constraint
            try {
                DB::statement("ALTER TABLE community_memberships DROP CONSTRAINT IF EXISTS cm_status_check");
            } catch (\Throwable $e) {
                // ignore
            }
            DB::statement("ALTER TABLE community_memberships ADD CONSTRAINT cm_status_check CHECK (status IN ('active','pending','inactive','removed','left','banned'))");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE community_memberships MODIFY COLUMN status ENUM('active','pending','inactive','removed','banned') NOT NULL DEFAULT 'active'");
        } else {
            try {
                DB::statement("ALTER TABLE community_memberships DROP CONSTRAINT IF EXISTS cm_status_check");
            } catch (\Throwable $e) {
                // ignore
            }
            DB::statement("ALTER TABLE community_memberships ADD CONSTRAINT cm_status_check CHECK (status IN ('active','pending','inactive','removed','banned'))");
        }
    }
};
