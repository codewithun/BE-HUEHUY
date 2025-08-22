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
        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('code')->unique(); // <--- Tambahkan ini
            $table->string('title');
            $table->string('description')->nullable();
            $table->text('detail')->nullable();
            $table->double('promo_distance', 8, 2)->default(0);
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->boolean('always_available')->default(false);
            $table->integer('stock')->default(0);
            $table->enum('promo_type', ['offline', 'online'])->default('offline');
            $table->string('location')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_contact')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promos');
    }
};
