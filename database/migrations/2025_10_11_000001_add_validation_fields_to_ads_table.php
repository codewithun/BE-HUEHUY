<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            // Validation type untuk promo/voucher
            if (!Schema::hasColumn('ads', 'validation_type')) {
                $table->enum('validation_type', ['auto', 'manual'])->default('auto')->after('promo_type');
            }
            
            // Kode unik untuk validasi manual
            if (!Schema::hasColumn('ads', 'code')) {
                $table->string('code')->nullable()->after('validation_type');
            }
            
            // Target type untuk voucher
            if (!Schema::hasColumn('ads', 'target_type')) {
                $table->enum('target_type', ['all', 'user', 'community'])->default('all')->after('code');
            }
            
            // Target user ID untuk voucher dengan user tertentu
            if (!Schema::hasColumn('ads', 'target_user_id')) {
                $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete()->after('target_type');
            }
            
            // Community ID untuk voucher dengan komunitas tertentu
            if (!Schema::hasColumn('ads', 'community_id')) {
                $table->foreignId('community_id')->nullable()->constrained('communities')->nullOnDelete()->after('target_user_id');
            }
            
            // Tanggal validasi
            if (!Schema::hasColumn('ads', 'start_validate')) {
                $table->dateTime('start_validate')->nullable()->after('community_id');
            }
            
            if (!Schema::hasColumn('ads', 'finish_validate')) {
                $table->dateTime('finish_validate')->nullable()->after('start_validate');
            }
            
            // Level UMKM
            if (!Schema::hasColumn('ads', 'level_umkm')) {
                $table->integer('level_umkm')->nullable()->after('finish_validate');
            }
            
            // Produksi dan penjualan per hari
            if (!Schema::hasColumn('ads', 'max_production_per_day')) {
                $table->integer('max_production_per_day')->nullable()->after('level_umkm');
            }
            
            if (!Schema::hasColumn('ads', 'sell_per_day')) {
                $table->integer('sell_per_day')->nullable()->after('max_production_per_day');
            }
            
            // Batas waktu validasi (HH:mm format)
            if (!Schema::hasColumn('ads', 'validation_time_limit')) {
                $table->time('validation_time_limit')->nullable()->after('sell_per_day');
            }
            
            // Update enum type untuk mendukung voucher dan iklan
            // Note: Laravel tidak bisa mengubah enum langsung, jadi kita perlu drop dan recreate
            if (Schema::hasColumn('ads', 'type')) {
                DB::statement("ALTER TABLE ads MODIFY COLUMN type ENUM('general', 'huehuy', 'mitra', 'promo', 'voucher', 'iklan') DEFAULT 'general'");
            }
            
            // Tambah index untuk performa
            $table->index(['type', 'status']);
            $table->index(['target_type', 'community_id']);
            $table->index(['target_type', 'target_user_id']);
            $table->index(['validation_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['target_type', 'community_id']);
            $table->dropIndex(['target_type', 'target_user_id']);
            $table->dropIndex(['validation_type']);
            
            // Drop foreign keys
            if (Schema::hasColumn('ads', 'target_user_id')) {
                $table->dropForeign(['target_user_id']);
            }
            if (Schema::hasColumn('ads', 'community_id')) {
                $table->dropForeign(['community_id']);
            }
            
            // Drop columns
            $columns = [
                'validation_type',
                'code',
                'target_type',
                'target_user_id',
                'community_id',
                'start_validate',
                'finish_validate',
                'level_umkm',
                'max_production_per_day',
                'sell_per_day',
                'validation_time_limit'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('ads', $column)) {
                    $table->dropColumn($column);
                }
            }
            
            // Revert enum type
            DB::statement("ALTER TABLE ads MODIFY COLUMN type ENUM('general', 'huehuy', 'mitra') DEFAULT 'general'");
        });
    }
};