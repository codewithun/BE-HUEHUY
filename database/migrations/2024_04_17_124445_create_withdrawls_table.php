<?php

use App\Models\User;
use App\Models\WithdrawMethod;
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
        Schema::create('withdraws', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->onDelete('set null');
            $table->foreignIdFor(WithdrawMethod::class)->nullable()->onDelete('set null');
            $table->float('point');
            $table->string('number');
            $table->enum('status', ['pending', 'success', 'cancel']);
            $table->timestamp('success_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdraws');
    }
};
