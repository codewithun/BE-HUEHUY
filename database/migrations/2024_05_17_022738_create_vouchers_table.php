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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('type')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('tenant_location')->nullable();
            $table->integer('stock')->default(0);
            $table->string('code')->unique();

            // Targeting fields (ganti delivery)
            $table->enum('target_type', ['all', 'user', 'community'])->default('all');
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Relasi opsional ke community
            $table->foreignId('community_id')->nullable()->constrained('communities')->nullOnDelete();

            $table->timestamps();

            // Index pendukung query
            $table->index(['target_type', 'community_id']);
            $table->index(['target_type', 'target_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
