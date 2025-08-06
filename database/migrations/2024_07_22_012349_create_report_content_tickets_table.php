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
        Schema::create('report_content_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 128)->index();
            $table->foreignIdFor(User::class, 'user_reporter_id')->onDelete('set null');
            $table->foreignIdFor(Ad::class);
            $table->string('message');
            $table->enum('status', ['pending', 'rejected', 'accepted']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_content_tickets');
    }
};
