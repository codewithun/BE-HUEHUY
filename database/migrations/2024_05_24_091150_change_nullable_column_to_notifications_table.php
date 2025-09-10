<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // user_id
            if (!Schema::hasColumn('notifications', 'user_id')) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            } else {
                // pastikan nullable sesuai kebutuhanmu; di sini tetap not null (default)
                // $table->unsignedBigInteger('user_id')->nullable()->change();
            }

            // cube_id
            if (!Schema::hasColumn('notifications', 'cube_id')) {
                $table->foreignId('cube_id')->nullable()->constrained()->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('cube_id')->nullable()->change();
            }

            // ad_id
            if (!Schema::hasColumn('notifications', 'ad_id')) {
                $table->foreignId('ad_id')->nullable()->constrained()->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('ad_id')->nullable()->change();
            }

            // grab_id
            if (!Schema::hasColumn('notifications', 'grab_id')) {
                $table->foreignId('grab_id')->nullable()->constrained()->cascadeOnDelete();
            } else {
                $table->unsignedBigInteger('grab_id')->nullable()->change();
            }

            // type
            if (!Schema::hasColumn('notifications', 'type')) {
                $table->enum('type', ['merchant', 'hunter'])->nullable()->after('grab_id');
            } else {
                // pastikan nullable (kalau mau)
                // NOTE: ubah enum via change() butuh doctrine/dbal, yang kamu sudah pakai
                $table->enum('type', ['merchant', 'hunter'])->nullable()->change();
            }

            // message wajib ada & not null
            if (!Schema::hasColumn('notifications', 'message')) {
                $table->string('message');
            } else {
                $table->string('message')->nullable(false)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Balikinnya minimal: drop kolom yang tadi mungkin kita tambahkan
            // (hati-hati kalau sudah ada FK)
            if (Schema::hasColumn('notifications', 'grab_id')) {
                $table->dropConstrainedForeignId('grab_id');
            }
            if (Schema::hasColumn('notifications', 'ad_id')) {
                $table->dropConstrainedForeignId('ad_id');
            }
            if (Schema::hasColumn('notifications', 'cube_id')) {
                $table->dropConstrainedForeignId('cube_id');
            }
            if (Schema::hasColumn('notifications', 'user_id')) {
                // Jangan di-drop kalau memang required. Comment out jika tak ingin dihapus.
                // $table->dropConstrainedForeignId('user_id');
            }
            if (Schema::hasColumn('notifications', 'type')) {
                $table->dropColumn('type');
            }
            // message biarkan ada (kebanyakan sistem butuh)
        });
    }
};
