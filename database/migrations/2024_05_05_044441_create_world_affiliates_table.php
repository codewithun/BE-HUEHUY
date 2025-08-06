<?php

use App\Models\Corporate;
use App\Models\World;
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
        Schema::create('world_affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(World::class)->onDelete('set null');
            $table->foreignIdFor(Corporate::class)->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('world_affiliates');
    }
};
