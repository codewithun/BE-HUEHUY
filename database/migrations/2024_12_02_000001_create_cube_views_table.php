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
        Schema::create('cube_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cube_id');
            $table->unsignedBigInteger('user_id')->nullable(); // null = guest/anonymous
            $table->string('ip_address', 45)->nullable(); // IPv4 atau IPv6
            $table->string('user_agent', 500)->nullable();
            $table->string('session_id', 100)->nullable(); // untuk tracking guest unik
            $table->timestamps();

            // Indexes untuk performa
            $table->index('cube_id');
            $table->index('user_id');
            $table->index(['cube_id', 'user_id']);
            $table->index(['cube_id', 'session_id']);
            $table->index('created_at');

            // Foreign key
            $table->foreign('cube_id')->references('id')->on('cubes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            // Unique constraint: satu user/session hanya bisa view 1x per cube per hari
            $table->unique(['cube_id', 'user_id', 'session_id', 'created_at'], 'unique_daily_view');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cube_views');
    }
};
