<?php

use App\Models\Ad;
use App\Models\Cube;
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
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignIdFor(Ad::class)->nullable()->index()->onDelete('cascade')->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('ad_id');
            $table->foreignIdFor(Cube::class)->nullable(false)->index()->onDelete('cascade')->after('id');
        });
    }
};
