<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_contents', function (Blueprint $table) {
            // Tambahkan kolom ad_category_id jika belum ada
            if (!Schema::hasColumn('dynamic_contents', 'ad_category_id')) {
                $table->foreignId('ad_category_id')
                    ->nullable()
                    ->constrained('ad_categories')
                    ->nullOnDelete()
                    ->after('source_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_contents', function (Blueprint $table) {
            if (Schema::hasColumn('dynamic_contents', 'ad_category_id')) {
                $table->dropConstrainedForeignId('ad_category_id');
            }
        });
    }
};
