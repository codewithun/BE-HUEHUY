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
        // Tabel pivot untuk menyimpan target users voucher (many-to-many)
        Schema::create('ad_target_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained('ads')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Unique constraint agar user tidak bisa ditarget dua kali untuk ad yang sama
            $table->unique(['ad_id', 'user_id']);
            
            // Index untuk performa
            $table->index(['ad_id']);
            $table->index(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_target_users');
    }
};