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
        Schema::table('summary_grabs', function (Blueprint $table) {
            if (!Schema::hasColumn('summary_grabs', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('ad_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('summary_grabs', function (Blueprint $table) {
            if (Schema::hasColumn('summary_grabs', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });
    }
};
