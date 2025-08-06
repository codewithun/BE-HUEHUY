<?php

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
        Schema::create('cube_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Cube::class)->onDelete('cascade');
            $table->string('address');
            $table->string('map_lat');
            $table->string('map_lng');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cube_tags');
    }
};
