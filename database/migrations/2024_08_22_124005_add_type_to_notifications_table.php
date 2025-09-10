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
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'type')) {
                $table->enum('type', ['merchant', 'hunter'])
                    ->nullable()
                    ->after('id'); // biasanya lebih aman after id, karena kadang `grab_id` belum ada
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
