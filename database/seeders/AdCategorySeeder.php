<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class AdCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $destDir = storage_path('app/public/ad-category/');
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }

        // Only copy placeholder/default image if missing
        $placeholderSrc = database_path('seeders/assets/ad-category/default.png');
        $placeholderDest = $destDir . 'default.png';
        
        if (!file_exists($placeholderDest) && file_exists($placeholderSrc)) {
            File::copy($placeholderSrc, $placeholderDest);
        }

        // Do NOT insert ad_categories here anymore.
        // Categories should be managed via CRUD operations only.
    }
}
