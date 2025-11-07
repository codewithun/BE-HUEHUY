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
        Schema::create('voucher_grabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('date')->comment('Tanggal grab untuk tracking harian');
            $table->integer('total_grab')->default(1)->comment('Jumlah grab di tanggal tersebut');
            $table->timestamps();

            // Indexes untuk performa query
            $table->index(['voucher_id', 'date']);
            $table->index(['voucher_id', 'user_id', 'date']);

            // Unique constraint: satu user hanya bisa grab sekali per hari per voucher
            $table->unique(['voucher_id', 'user_id', 'date'], 'voucher_user_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_grabs');
    }
};
