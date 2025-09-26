<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Tambahkan setelah kolom tenant_location biar rapi (boleh diubah)
            $table->string('owner_name', 150)->nullable()->after('tenant_location');
            $table->string('owner_phone', 30)->nullable()->after('owner_name');

            // (Opsional) index kalau sering dipakai filter/pencarian
            $table->index('owner_name');
            $table->index('owner_phone');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['owner_name']);
            $table->dropIndex(['owner_phone']);
            $table->dropColumn(['owner_name', 'owner_phone']);
        });
    }
};
