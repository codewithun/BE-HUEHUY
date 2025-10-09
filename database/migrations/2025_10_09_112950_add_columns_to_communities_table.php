<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            // Mitra (corporate)
            if (!Schema::hasColumn('communities', 'corporate_id')) {
                $table->foreignId('corporate_id')->nullable()->constrained('corporates')->nullOnDelete()->after('id');
            }

            // Warna background
            if (!Schema::hasColumn('communities', 'bg_color_1')) {
                $table->string('bg_color_1', 16)->nullable()->after('logo');
            }
            if (!Schema::hasColumn('communities', 'bg_color_2')) {
                $table->string('bg_color_2', 16)->nullable()->after('bg_color_1');
            }

            // Jenis dunia
            if (!Schema::hasColumn('communities', 'world_type')) {
                $table->string('world_type', 50)->nullable()->after('bg_color_2');
            }

            // Aktif / tidak aktif
            if (!Schema::hasColumn('communities', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('world_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            if (Schema::hasColumn('communities', 'corporate_id')) {
                $table->dropConstrainedForeignId('corporate_id');
            }
            if (Schema::hasColumn('communities', 'bg_color_1')) {
                $table->dropColumn('bg_color_1');
            }
            if (Schema::hasColumn('communities', 'bg_color_2')) {
                $table->dropColumn('bg_color_2');
            }
            if (Schema::hasColumn('communities', 'world_type')) {
                $table->dropColumn('world_type');
            }
            if (Schema::hasColumn('communities', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
