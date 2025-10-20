<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure table exists
        if (!Schema::hasTable('promo_items')) {
            return;
        }

        // Drop any existing FK on promo_items.promo_id regardless of its name
        $fkRows = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promo_items' AND COLUMN_NAME = 'promo_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
        foreach ($fkRows as $row) {
            $name = $row->CONSTRAINT_NAME;
            try {
                DB::statement("ALTER TABLE `promo_items` DROP FOREIGN KEY `{$name}`");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Add FK to ads.id if ads table exists
        if (Schema::hasTable('ads')) {
            Schema::table('promo_items', function (Blueprint $table) {
                $table->foreign('promo_id')->references('id')->on('ads')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('promo_items')) {
            return;
        }

        // Drop any FK on promo_id
        $fkRows = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promo_items' AND COLUMN_NAME = 'promo_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
        foreach ($fkRows as $row) {
            $name = $row->CONSTRAINT_NAME;
            try {
                DB::statement("ALTER TABLE `promo_items` DROP FOREIGN KEY `{$name}`");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Re-add FK to promos.id if promos table exists
        if (Schema::hasTable('promos')) {
            Schema::table('promo_items', function (Blueprint $table) {
                $table->foreign('promo_id')->references('id')->on('promos')->onDelete('cascade');
            });
        }
    }
};
