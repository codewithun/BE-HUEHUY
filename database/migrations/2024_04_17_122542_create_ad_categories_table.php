<?php

use App\Models\AdCategory;
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
        Schema::create('ad_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AdCategory::class, 'parent_id')->nullable()->onDelete('set null');
            $table->string('name');
            $table->string('picture_source')->nullable();
            $table->boolean('is_primary_parent')->default(false);
            $table->boolean('is_home_display')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_categories');
    }
};
