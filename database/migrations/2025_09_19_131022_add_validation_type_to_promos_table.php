<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            if (!Schema::hasColumn('promos', 'validation_type')) {
                $table->string('validation_type', 10)->default('auto')->after('promo_type'); // 'auto' | 'manual'
            }
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            if (Schema::hasColumn('promos', 'validation_type')) {
                $table->dropColumn('validation_type');
            }
        });
    }
};
