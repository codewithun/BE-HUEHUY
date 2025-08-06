<?php

use App\Models\Cube;
use App\Models\User;
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
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropColumn('cube_id');
            $table->foreignIdFor(World::class)->nullable()->onDelete('set null')->index()->after('id');
            $table->foreignIdFor(User::class, 'user_merchant_id')->nullable()->onDelete('set null')->index()->after('world_id');
            $table->foreignIdFor(User::class, 'user_hunter_id')->nullable()->onDelete('set null')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropColumn('world_id');
            $table->dropColumn('user_merchant_id');
            $table->foreignIdFor(Cube::class)->index()->onDelete('set null')->after('id');
        });
    }
};
