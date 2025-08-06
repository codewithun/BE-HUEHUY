<?php

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
        Schema::table('dynamic_contents', function (Blueprint $table) {
            $table->foreignIdFor(World::class)->nullable()->after('id');
            $table->string('description')->nullable()->after('name');
            $table->enum('content_type', ['nearby', 'horizontal', 'vertical', 'category', 'ad_category', 'recommendation'])->nullable()->after('type');
            $table->enum('source_type', ['cube', 'ad', 'shuffle_cube'])->nullable()->after('content_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dynamic_contents', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->dropColumn('content_type');
            $table->dropColumn('source_type');
        });
    }
};
