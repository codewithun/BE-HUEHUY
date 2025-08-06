<?php

use App\Models\VoucherItem;
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
        Schema::table('grabs', function (Blueprint $table) {
            $table->foreignIdFor(VoucherItem::class)->nullable()->onDelete('set null')->after('ad_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grabs', function (Blueprint $table) {
            $table->dropColumn('voucher_item_id');
        });
    }
};
