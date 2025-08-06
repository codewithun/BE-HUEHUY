<?php

use App\Models\Ad;
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
        Schema::create('grabs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(User::class, 'validation_by')->nullable()->onDelete('set null');
            $table->foreignIdFor(Ad::class)->nullable()->onDelete('set null');
            $table->char('code', 10);
            $table->timestamp('validation_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grabs');
    }
};
