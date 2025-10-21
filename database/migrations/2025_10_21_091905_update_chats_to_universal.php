<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('chats', function (Blueprint $table) {
        // hapus kolom lama
        $table->dropForeign(['user_id']);
        $table->dropForeign(['admin_id']);
        $table->dropColumn(['user_id', 'admin_id']);

        // tambahkan struktur baru universal
        $table->unsignedBigInteger('sender_id')->nullable()->after('id');
        $table->unsignedBigInteger('receiver_id')->nullable()->after('sender_id');
        $table->string('receiver_type')->nullable()->after('receiver_id');

        // tambahkan relasi ke tabel users
        $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
    });
}

public function down()
{
    Schema::table('chats', function (Blueprint $table) {
        $table->dropForeign(['sender_id']);
        $table->dropForeign(['receiver_id']);
        $table->dropColumn(['sender_id', 'receiver_id', 'receiver_type']);

        // restore kolom lama
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('admin_id')->nullable();
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('admin_id')->references('id')->on('users')->onDelete('set null');
    });
}
};
