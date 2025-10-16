<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ambil definisi baru untuk enum source_type
        DB::statement("
            ALTER TABLE dynamic_contents 
            MODIFY source_type ENUM(
                'cube', 
                'ad', 
                'shuffle_cube', 
                'promo_selected', 
                'ad_category'
            ) NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE dynamic_contents 
            MODIFY source_type ENUM(
                'cube', 
                'ad', 
                'shuffle_cube', 
                'promo_selected'
            ) NULL
        ");
    }
};
