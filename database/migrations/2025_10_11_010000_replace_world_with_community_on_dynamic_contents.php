<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_contents', function (Blueprint $table) {
            // Add new nullable community_id
            if (!Schema::hasColumn('dynamic_contents', 'community_id')) {
                $table->unsignedBigInteger('community_id')->nullable()->after('id');
                // Add FK if communities table exists
                if (Schema::hasTable('communities')) {
                    $table->foreign('community_id')->references('id')->on('communities')->nullOnDelete();
                }
            }
        });

        // Backfill: copy world_id values into community_id when numeric matches
        // Note: we can't infer mapping; but if world_id already stored IDs of communities (as per FE intent), copy directly.
        try {
            DB::statement('UPDATE dynamic_contents SET community_id = world_id WHERE community_id IS NULL');
        } catch (\Throwable $e) {
            // ignore if world_id doesn't exist yet
        }

        Schema::table('dynamic_contents', function (Blueprint $table) {
            // Drop world_id column if exists (no FK expected in prior schema)
            if (Schema::hasColumn('dynamic_contents', 'world_id')) {
                try {
                    $table->dropColumn('world_id');
                } catch (\Throwable $e) {
                    // Fallback: ignore errors
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_contents', function (Blueprint $table) {
            // Recreate world_id as nullable
            if (!Schema::hasColumn('dynamic_contents', 'world_id')) {
                $table->unsignedBigInteger('world_id')->nullable()->after('id');
            }
        });

        // Backfill from community_id back to world_id for rollback symmetry
        try {
            DB::statement('UPDATE dynamic_contents SET world_id = community_id');
        } catch (\Throwable $e) {}

        Schema::table('dynamic_contents', function (Blueprint $table) {
            // Drop community_id FK and column
            try {
                $table->dropForeign(['community_id']);
            } catch (\Throwable $e) {}
            if (Schema::hasColumn('dynamic_contents', 'community_id')) {
                $table->dropColumn('community_id');
            }
        });
    }
};
