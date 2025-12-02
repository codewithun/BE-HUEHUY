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
        Schema::create('ad_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ad_id'); // ads table
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('session_id', 100)->nullable();
            $table->timestamps();

            // Indexes
            $table->index('ad_id');
            $table->index('user_id');
            $table->index(['ad_id', 'user_id']);
            $table->index(['ad_id', 'session_id']);
            $table->index('created_at');

            // Foreign keys
            $table->foreign('ad_id')->references('id')->on('ads')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            // Unique constraint
            $table->unique(['ad_id', 'user_id', 'session_id', 'created_at'], 'unique_daily_ad_view');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_views');
    }
};
