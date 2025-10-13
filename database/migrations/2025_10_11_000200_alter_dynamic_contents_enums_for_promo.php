<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN content_type ENUM('nearby','horizontal','vertical','category','ad_category','recommendation','promo') NULL");
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN source_type ENUM('cube','ad','shuffle_cube','promo_selected') NULL");
        } elseif ($driver === 'pgsql') {
            // Drop and recreate constraints if they exist
            try { DB::statement("ALTER TABLE dynamic_contents ALTER COLUMN content_type TYPE VARCHAR(32)"); } catch (\Throwable $e) {}
            try { DB::statement("ALTER TABLE dynamic_contents ALTER COLUMN source_type TYPE VARCHAR(32)"); } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN content_type ENUM('nearby','horizontal','vertical','category','ad_category','recommendation') NULL");
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN source_type ENUM('cube','ad','shuffle_cube') NULL");
        }
    }
};
