<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->enum('validation_type', ['auto', 'manual'])
                  ->default('auto')
                  ->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('validation_type');
        });
    }
};
