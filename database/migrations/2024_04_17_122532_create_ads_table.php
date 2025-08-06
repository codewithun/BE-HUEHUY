<?php

use App\Models\AdCategory;
use App\Models\Cube;
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
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Cube::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(AdCategory::class)->nullable()->onDelete('set null');
            $table->string('title');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->string('picture_source')->nullable();
            $table->integer('max_grab')->nullable();
            $table->boolean('is_daily_grab')->default(false);
            $table->enum('type', ['general', 'huehuy', 'mitra'])->default('general');
            $table->enum('status', ['inactive', 'active', 'expired']);
            $table->enum('promo_type', ['offline', 'online'])->default('offline');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
