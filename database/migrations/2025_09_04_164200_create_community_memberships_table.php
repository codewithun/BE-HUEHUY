<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_memberships', function (Blueprint $table) {
            $table->id();

            // FK ke users & communities
            $table->foreignId('user_id')
                ->constrained('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreignId('community_id')
                ->constrained('communities')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            // Status keanggotaan
            // Pakai enum untuk MySQL; di PostgreSQL akan di-cast ke check (lihat bawah).
            $table->enum('status', ['active', 'inactive', 'banned'])->default('active');

            // Waktu join (default now)
            $table->timestamp('joined_at')->useCurrent();

            $table->timestamps();

            // Cegah duplikasi member pada komunitas yang sama
            $table->unique(['user_id', 'community_id'], 'cm_user_community_unique');

            // Index untuk query yang sering dipakai
            $table->index(['community_id', 'status'], 'cm_community_status_idx');
            $table->index(['user_id', 'status'], 'cm_user_status_idx');
        });

        // OPTIONAL: Check constraint khusus PostgreSQL (biar enum-like benar2 dikunci)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE community_memberships
                ADD CONSTRAINT cm_status_check
                CHECK (status IN ('active','inactive','banned'))
            ");
        }
    }

    public function down(): void
    {
        // Hapus constraint PG optional (aman kalau gagal di MySQL)
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::statement("ALTER TABLE community_memberships DROP CONSTRAINT IF EXISTS cm_status_check");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        Schema::dropIfExists('community_memberships');
    }
};
