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
        Schema::create('qrcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('qr_code');
            $table->unsignedBigInteger('voucher_id')->nullable();
            $table->unsignedBigInteger('promo_id')->nullable();
            $table->string('tenant_name')->nullable();
            $table->timestamps();

            $table->foreign('admin_id')->references('id')->on('users')->onDelete('cascade');
            // Jika ada tabel vouchers dan promos, bisa tambahkan foreign key:
            // $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('set null');
            // $table->foreign('promo_id')->references('id')->on('promos')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qrcodes');
    }
};
