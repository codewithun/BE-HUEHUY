<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::table('promos', function (Blueprint $table) {
            $table->timestamp('attached_at')->nullable()->index()->after('category_id');
        });

        // Backfill agar data lama tetap tersortir masuk akal
        DB::statement('UPDATE promos SET attached_at = COALESCE(attached_at, updated_at, created_at)');
    }

    public function down(): void {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn('attached_at');
        });
    }
};
