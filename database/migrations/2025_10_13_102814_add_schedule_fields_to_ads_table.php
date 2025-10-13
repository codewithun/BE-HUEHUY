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
        Schema::table('ads', function (Blueprint $table) {
            // Tambah field untuk jam mulai dan jam berakhir
            $table->time('jam_mulai')->nullable()->after('validation_time_limit')->comment('Jam mulai promo/voucher per hari');
            $table->time('jam_berakhir')->nullable()->after('jam_mulai')->comment('Jam berakhir promo/voucher per hari');

            // Tambah field untuk tipe hari (weekend/weekday/custom)
            $table->enum('day_type', ['weekend', 'weekday', 'custom'])->default('custom')->after('jam_berakhir')->comment('Tipe hari promo/voucher berlaku');

            // Tambah field untuk hari-hari kustom (JSON)
            $table->json('custom_days')->nullable()->after('day_type')->comment('Hari-hari kustom dalam format JSON');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->dropColumn([
                'jam_mulai',
                'jam_berakhir',
                'day_type',
                'custom_days'
            ]);
        });
    }
};
