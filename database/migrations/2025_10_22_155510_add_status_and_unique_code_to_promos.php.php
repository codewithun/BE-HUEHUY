<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            if (!Schema::hasColumn('promos', 'status')) {
                // pakai string sederhana; enum bikin rigid di MySQL lama
                $table->string('status', 16)->default('active')->after('image_updated_at');
            }
        });

        // Backfill NULL -> 'active'
        DB::table('promos')->whereNull('status')->update(['status' => 'active']);

        // Bereskan duplikasi code sebelum unique index
        // Ambil code yang duplikat
        $dupes = DB::table('promos')
            ->select('code', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('code')->where('code', '!=', '')
            ->groupBy('code')->having('cnt', '>', 1)->pluck('code');

        foreach ($dupes as $code) {
            // Sisakan 1, ubah yang lain jadi code+suffix agar tetap unik
            $ids = DB::table('promos')->where('code', $code)->orderBy('id')->pluck('id');
            $keep = $ids->shift(); // biarkan yang pertama tetap
            $n = 1;
            foreach ($ids as $id) {
                DB::table('promos')->where('id', $id)->update([
                    'code' => $code . '-DUP' . $n++
                ]);
            }
        }

        // Tambah unique index pada promos.code jika belum ada
        // MySQL: cek index by INFORMATION_SCHEMA
        $hasUnique = DB::table('INFORMATION_SCHEMA.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'promos')
            ->where('INDEX_NAME', 'promos_code_unique')
            ->exists();

        if (!$hasUnique) {
            Schema::table('promos', function (Blueprint $table) {
                $table->unique('code', 'promos_code_unique');
            });
        }
    }

    public function down(): void
    {
        // Lepas unique kalau ada
        try {
            Schema::table('promos', function (Blueprint $table) {
                $table->dropUnique('promos_code_unique');
            });
        } catch (\Throwable $e) {
        }

        // Hapus kolom status kalau ada
        Schema::table('promos', function (Blueprint $table) {
            if (Schema::hasColumn('promos', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
