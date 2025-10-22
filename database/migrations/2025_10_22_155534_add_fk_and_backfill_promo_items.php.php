<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) Backfill orphan: promo_items yang promo_id-nya tidak ada di promos
        // Strategi:
        // - Cocokkan ke master promo berdasarkan code.
        // - Jika belum ada master promo dengan code tsb, buat master minimal (active).
        DB::transaction(function () {
            // Ambil item orphan
            $orphans = DB::table('promo_items as i')
                ->leftJoin('promos as p', 'p.id', '=', 'i.promo_id')
                ->whereNull('p.id')
                ->select('i.id', 'i.code', 'i.promo_id', 'i.user_id', 'i.expires_at')
                ->orderBy('i.id')
                ->get();

            foreach ($orphans as $row) {
                if (!$row->code) {
                    // tanpa code, lewati: biar ketahuan dan dibereskan manual
                    continue;
                }

                // Cari promo master by code
                $promoId = DB::table('promos')->where('code', $row->code)->value('id');

                // Jika belum ada, buat minimal
                if (!$promoId) {
                    $promoId = DB::table('promos')->insertGetId([
                        'community_id'     => null,
                        'category_id'      => null,
                        'code'             => $row->code,
                        'title'            => 'Auto Restored ' . $row->code,
                        'description'      => 'Restored from orphan promo_items',
                        'detail'           => null,
                        'promo_distance'   => 0,
                        'start_date'       => null,
                        'end_date'         => $row->expires_at, // selaraskan jika ada
                        'always_available' => 1,
                        'stock'            => null,
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

                // Tautkan kembali
                DB::table('promo_items')->where('id', $row->id)->update(['promo_id' => $promoId]);
            }
        });

        // 2) Tambah index untuk performa
        Schema::table('promo_items', function (Blueprint $table) {
            // index gabungan sering kepakai untuk query user history
            $table->index(['promo_id', 'user_id'], 'promo_items_promo_user_idx');
        });

        // 3) Tambah FK ON DELETE CASCADE (jika belum ada)
        $hasFk = DB::table('INFORMATION_SCHEMA.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'promo_items')
            ->where('CONSTRAINT_NAME', 'promo_items_promo_id_foreign')
            ->exists();

        if (!$hasFk) {
            Schema::table('promo_items', function (Blueprint $table) {
                // kolomnya sudah ada, tambahkan constraint saja
                $table->foreign('promo_id', 'promo_items_promo_id_foreign')
                    ->references('id')->on('promos')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Lepas FK
        try {
            Schema::table('promo_items', function (Blueprint $table) {
                $table->dropForeign('promo_items_promo_id_foreign');
            });
        } catch (\Throwable $e) {
        }

        // Lepas index
        try {
            Schema::table('promo_items', function (Blueprint $table) {
                $table->dropIndex('promo_items_promo_user_idx');
            });
        } catch (\Throwable $e) {
        }
    }
};
