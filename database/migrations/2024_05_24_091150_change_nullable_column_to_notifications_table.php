<?php

use App\Models\Ad;
use App\Models\Cube;
use App\Models\Grab;
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
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignIdFor(Cube::class)->nullable()->onDelete('cascade')->change();
            $table->foreignIdFor(Ad::class)->nullable()->onDelete('cascade')->change();
            $table->foreignIdFor(Grab ::class)->nullable()->onDelete('cascade')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignIdFor(Cube::class)->nullable(false)->onDelete('cascade')->change();
            $table->foreignIdFor(Ad::class)->nullable(false)->onDelete('cascade')->change();
            $table->foreignIdFor(Grab ::class)->nullable(false)->onDelete('cascade')->change();
        });
    }
};
