<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Add 'category_box' to content_type enum
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN content_type ENUM('nearby','horizontal','vertical','category','ad_category','recommendation','promo','category_box') NULL");
            
            // Add 'category_box' to source_type enum
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN source_type ENUM('cube','ad','shuffle_cube','promo_selected','ad_category','category_box') NULL");
        } elseif ($driver === 'pgsql') {
            // For PostgreSQL, ensure columns can handle the new values
            try { 
                DB::statement("ALTER TABLE dynamic_contents ALTER COLUMN content_type TYPE VARCHAR(32)"); 
            } catch (\Throwable $e) {}
            try { 
                DB::statement("ALTER TABLE dynamic_contents ALTER COLUMN source_type TYPE VARCHAR(32)"); 
            } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Remove 'category_box' from content_type enum
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN content_type ENUM('nearby','horizontal','vertical','category','ad_category','recommendation','promo') NULL");
            
            // Remove 'category_box' from source_type enum  
            DB::statement("ALTER TABLE dynamic_contents MODIFY COLUMN source_type ENUM('cube','ad','shuffle_cube','promo_selected','ad_category') NULL");
        }
    }
};