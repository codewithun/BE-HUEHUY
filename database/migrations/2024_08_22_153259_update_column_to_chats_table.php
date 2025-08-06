<?php

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
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignIdFor(Cube::class)->nullable()->onDelete('set null')->index()->after('user_sender_id');
            $table->foreignIdFor(Grab::class)->nullable()->onDelete('set null')->index()->after('cube_id');
            $table->text('message')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('cube_id');
            $table->dropColumn('grab_id');
        });
    }
};
