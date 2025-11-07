<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Untuk support voucher harian yang auto-reset setiap hari
            $table->boolean('is_daily_grab')->default(false)->after('stock')
                ->comment('Jika true, maka stock adalah stok PER HARI yang auto-reset');

            $table->boolean('unlimited_grab')->default(false)->after('is_daily_grab')
                ->comment('Jika true, voucher tidak terbatas (unlimited stock)');

            // Tanggal validasi untuk mengontrol periode voucher
            $table->dateTime('start_validate')->nullable()->after('unlimited_grab')
                ->comment('Tanggal mulai voucher aktif');

            $table->dateTime('finish_validate')->nullable()->after('start_validate')
                ->comment('Tanggal berakhir voucher - untuk daily grab, auto-reset sampai tanggal ini');

            // Index untuk performa query
            $table->index('is_daily_grab');
            $table->index(['start_validate', 'finish_validate']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['vouchers_is_daily_grab_index']);
            $table->dropIndex(['vouchers_start_validate_finish_validate_index']);

            $table->dropColumn([
                'is_daily_grab',
                'unlimited_grab',
                'start_validate',
                'finish_validate'
            ]);
        });
    }
};
