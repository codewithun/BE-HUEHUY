<?php

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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('subtitle')->nullable();
            $table->string('image')->nullable();
            $table->string('organizer_name');
            $table->string('organizer_logo')->nullable();
            $table->string('organizer_type')->nullable();
            $table->date('date');
            $table->string('time', 100)->nullable();
            $table->string('location');
            $table->text('address')->nullable();
            $table->string('category', 100)->nullable();
            $table->integer('participants')->default(0);
            $table->integer('max_participants')->default(100);
            $table->string('price', 100)->nullable();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->text('schedule')->nullable();
            $table->text('prizes')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_email', 100)->nullable();
            $table->text('tags')->nullable();
            $table->unsignedBigInteger('community_id')->nullable();
            $table->timestamps();

            $table->foreign('community_id')->references('id')->on('communities')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
