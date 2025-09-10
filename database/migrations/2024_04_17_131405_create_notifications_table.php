<?php

use App\Models\User;
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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Penerima notifikasi
            $table->foreignIdFor(User::class)
                ->constrained()
                ->onDelete('cascade');

            // Tipe notifikasi (voucher|promo|grab|merchant|system|dll)
            $table->string('type')->default('system');

            // Konten utama
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->string('image_url')->nullable();

            // Target entity opsional (mis. voucher id / promo id / community id)
            $table->string('target_type')->nullable();     // 'voucher' | 'promo' | 'community' | dll
            $table->unsignedBigInteger('target_id')->nullable();

            // Deep link / URL aksi opsional
            $table->string('action_url')->nullable();

            // Metadata tambahan (JSON)
            $table->json('meta')->nullable();

            // Status baca
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
