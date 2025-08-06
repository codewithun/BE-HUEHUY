<?php

use App\Models\Corporate;
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
        Schema::create('worlds', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Corporate::class)->nullable()->onDelete('set null');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('color', 10)->nullable();
            $table->enum('type', ['lock', 'general'])->index()->default('lock');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worlds');
    }
};
