<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Tambah opsi 'iklan' ke enum kolom type
        DB::statement("
            ALTER TABLE ads
            MODIFY COLUMN type
            ENUM('general','voucher','huehuy','iklan')
            NOT NULL DEFAULT 'general'
        ");
    }

    public function down(): void
    {
        // Safety: ubah nilai 'iklan' jadi 'general' dulu biar rollback nggak gagal
        DB::table('ads')->where('type', 'iklan')->update(['type' => 'general']);

        DB::statement("
            ALTER TABLE ads
            MODIFY COLUMN type
            ENUM('general','voucher','huehuy')
            NOT NULL DEFAULT 'general'
        ");
    }
};
