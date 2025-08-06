<?php

use App\Models\Corporate;
use App\Models\Cube;
use App\Models\CubeType;
use App\Models\User;
use App\Models\World;
use App\Models\WorldAffiliate;
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
        Schema::create('cubes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(CubeType::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(Cube::class, 'parent_id')->nullable()->onDelete('set null');
            $table->foreignIdFor(User::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(Corporate::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(World::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(WorldAffiliate::class)->nullable()->onDelete('set null');
            $table->char('code', 10);
            $table->string('picture_source')->nullable();
            $table->string('color')->nullable();
            $table->string('address');
            $table->double('map_lat');
            $table->double('map_lng');
            $table->date('expired_activate_date')->nullable();
            $table->date('start_validate')->nullable();
            $table->date('finish_validate')->nullable();
            $table->enum('status', ['inactive', 'active']);
            $table->timestamp('inactive_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cubes');
    }
};
