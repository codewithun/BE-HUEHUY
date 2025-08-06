<?php

use App\Models\Ad;
use App\Models\Grab;
use App\Models\User;
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
        Schema::create('ad_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Ad::class)->onDelete('cascade');
            $table->foreignIdFor(User::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(Grab::class)->nullable()->onDelete('set null');
            $table->integer('rate', false, true)->default(1);
            $table->string('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_reviews');
    }
};
