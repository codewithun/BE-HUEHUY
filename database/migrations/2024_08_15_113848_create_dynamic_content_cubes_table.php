<?php

use App\Models\Cube;
use App\Models\DynamicContent;
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
        Schema::create('dynamic_content_cubes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(DynamicContent::class)->onDelete('cascade')->index();
            $table->foreignIdFor(Cube::class)->onDelete('cascade')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_content_cubes');
    }
};
