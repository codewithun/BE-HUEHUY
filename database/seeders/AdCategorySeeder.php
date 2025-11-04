<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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

        // Insert sample ad categories for development/testing
        $this->seedAdCategories();
    }

    /**
     * Seed sample ad categories
     */
    private function seedAdCategories(): void
    {
        $categories = [
            [
                'name' => 'Makanan & Minuman',
                'parent_id' => null,
                'community_id' => null,
                'is_primary_parent' => 1,
                'is_home_display' => 1,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Fashion & Pakaian',
                'parent_id' => null,
                'community_id' => null,
                'is_primary_parent' => 1,
                'is_home_display' => 1,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Elektronik & Gadget',
                'parent_id' => null,
                'community_id' => null,
                'is_primary_parent' => 1,
                'is_home_display' => 1,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Otomotif',
                'parent_id' => null,
                'community_id' => null,
                'is_primary_parent' => 1,
                'is_home_display' => 1,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Properti & Real Estate',
                'parent_id' => null,
                'community_id' => null,
                'is_primary_parent' => 1,
                'is_home_display' => 1,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Jasa & Layanan',
                'parent_id' => null,
                'community_id' => null,
                'is_primary_parent' => 1,
                'is_home_display' => 1,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Restoran Cepat Saji',
                'parent_id' => 1, // Parent: Makanan & Minuman
                'community_id' => null,
                'is_primary_parent' => 0,
                'is_home_display' => 0,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Pakaian Wanita',
                'parent_id' => 2, // Parent: Fashion & Pakaian
                'community_id' => null,
                'is_primary_parent' => 0,
                'is_home_display' => 0,
                'picture_source' => 'ad-category/default.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insert data menggunakan DB::table untuk performa yang lebih baik
        // Cek apakah sudah ada data, jika belum maka insert
        if (DB::table('ad_categories')->count() === 0) {
            DB::table('ad_categories')->insert($categories);
        }
    }
}
