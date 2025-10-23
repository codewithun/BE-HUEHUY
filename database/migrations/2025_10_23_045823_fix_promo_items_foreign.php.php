<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('promo_items')) return;

        // 1️⃣ Hapus semua FK lama di kolom promo_id (apapun namanya)
        $fkRows = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'promo_items' 
              AND COLUMN_NAME = 'promo_id' 
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        foreach ($fkRows as $row) {
            try {
                DB::statement("ALTER TABLE `promo_items` DROP FOREIGN KEY `{$row->CONSTRAINT_NAME}`");
            } catch (\Throwable $e) {
                // Abaikan error kalau constraint sudah tidak ada
            }
        }

        // 2️⃣ Perbaiki orphan promo_items (yang promo_id-nya tidak punya promo)
        DB::transaction(function () {
            $orphans = DB::table('promo_items as i')
                ->leftJoin('promos as p', 'p.id', '=', 'i.promo_id')
                ->whereNull('p.id')
                ->select('i.id', 'i.code', 'i.promo_id')
                ->get();

            foreach ($orphans as $row) {
                if (!$row->code) continue;

                // Cari promo master berdasarkan code
                $promoId = DB::table('promos')->where('code', $row->code)->value('id');

                // Jika belum ada promo master -> buat otomatis
                if (!$promoId) {
                    $promoId = DB::table('promos')->insertGetId([
                        'community_id'     => null,
                        'category_id'      => null,
                        'code'             => $row->code,
                        'title'            => 'Auto Restored ' . $row->code,
                        'description'      => 'Restored from orphan promo_items',
                        'detail'           => null,
                        'promo_distance'   => 0,
                        'start_date'       => now(),
                        'end_date'         => now()->addDays(7),
                        'always_available' => 1,
                        'stock'            => 0,
                        'promo_type'       => 'offline',
                        'validation_type'  => 'auto',
                        'location'         => null,
                        'owner_name'       => 'System',
                        'owner_contact'    => '-',
                        'image'            => null,
                        'image_updated_at' => now(),
                        'status'           => 'active',
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }

                // Update promo_item agar terhubung ke promo master
                DB::table('promo_items')->where('id', $row->id)->update(['promo_id' => $promoId]);
            }
        });

        // 3️⃣ Tambahkan kembali FK ke promos.id (ON DELETE CASCADE)
        Schema::table('promo_items', function (Blueprint $table) {
            $table->foreign('promo_id', 'promo_items_promo_id_foreign')
                ->references('id')->on('promos')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('promo_items')) return;

        // Lepas FK yang baru ditambahkan
        $fkRows = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'promo_items' 
              AND COLUMN_NAME = 'promo_id' 
              AND REFERENCED_TABLE_NAME = 'promos'
        ");
        foreach ($fkRows as $row) {
            try {
                DB::statement("ALTER TABLE `promo_items` DROP FOREIGN KEY `{$row->CONSTRAINT_NAME}`");
            } catch (\Throwable $e) {
                // Abaikan kalau sudah tidak ada
            }
        }
    }
};
