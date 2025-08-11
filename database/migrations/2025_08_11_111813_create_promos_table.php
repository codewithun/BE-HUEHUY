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
            $table->string('title');
            $table->string('description')->nullable();
            $table->text('detail')->nullable();
            $table->integer('promo_distance')->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('always_available')->default(false);
            $table->integer('stock')->default(0);
            $table->enum('promo_type', ['offline', 'online'])->default('offline');
            $table->string('location')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_contact')->nullable();
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
