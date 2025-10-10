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
        if (!file_exists($destDir)) {
            mkdir($destDir, 0777, true);
        }
        
        $assetsDir = database_path('seeders/assets/ad-category');
        
        // Copy default images only if missing; do not delete user-uploaded files
        $defaults = [
            'resto-cafe.jpeg',
            'teknologi.jpg',
            'hotel.jpg',
            'hiburan.jpg',
            'otomotif.jpg',
            'properti.jpg',
        ];
        
        foreach ($defaults as $filename) {
            $dest = $destDir . DIRECTORY_SEPARATOR . $filename;
            if (!file_exists($dest)) {
                $candidate = $assetsDir . DIRECTORY_SEPARATOR . $filename;
                $source = file_exists($candidate) ? $candidate : public_path($filename);
                if (file_exists($source)) {
                    File::copy($source, $dest);
                }
            }
        }
        
        // Optional placeholder/category image if available
        $categoryDest = $destDir . DIRECTORY_SEPARATOR . 'category.png';
        if (!file_exists($categoryDest)) {
            $categoryAsset = $assetsDir . DIRECTORY_SEPARATOR . 'category.png';
            if (file_exists($categoryAsset)) {
                File::copy($categoryAsset, $categoryDest);
            } elseif (file_exists(public_path('category.png'))) {
                File::copy(public_path('category.png'), $categoryDest);
            }
        }

        $data = [
            [
                'name' =>  'Resto & Cafe',
                'parent_id' => null,
                'is_primary_parent' => true,
                'is_home_display' => true,
                'picture_source' => 'ad-category/resto-cafe.jpeg'
            ],
            [
                'name' =>  'Teknologi',
                'parent_id' => null,
                'is_primary_parent' => true,
                'is_home_display' => false,
                'picture_source' => 'ad-category/teknologi.jpg'
            ],
            [
                'name' =>  'Hotel & Penginapan',
                'parent_id' => null,
                'is_primary_parent' => true,
                'is_home_display' => true,
                'picture_source' => 'ad-category/hotel.jpg'
            ],
            [
                'name' =>  'Hiburan',
                'parent_id' => null,
                'is_primary_parent' => true,
                'is_home_display' => false,
                'picture_source' => 'ad-category/hiburan.jpg'
            ],
            [
                'name' =>  'Otomotif',
                'parent_id' => null,
                'is_primary_parent' => true,
                'is_home_display' => false,
                'picture_source' => 'ad-category/otomotif.jpg'
            ],
            [
                'name' =>  'Properti',
                'parent_id' => null,
                'is_primary_parent' => true,
                'is_home_display' => false,
                'picture_source' => 'ad-category/properti.jpg'
            ],
            [
                'name' =>  'Pendidikan & Karir',
                'parent_id' => null,
                'is_primary_parent' => false,
                'is_home_display' => true,
                'picture_source' => 'ad-category/category.png'
            ],
            [
                'name' =>  'Makanan',
                'parent_id' => 1,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Minuman',
                'parent_id' => 1,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Makanan Ringan',
                'parent_id' => 1,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Gadget',
                'parent_id' => 2,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Smartphone',
                'parent_id' => 2,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Laptop',
                'parent_id' => 2,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Hotel',
                'parent_id' => 3,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Kost',
                'parent_id' => 3,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Tanah',
                'parent_id' => 6,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Rumah',
                'parent_id' => 6,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
            [
                'name' =>  'Ruko',
                'parent_id' => 6,
                'is_primary_parent' => false,
                'is_home_display' => false,
                'picture_source' => null
            ],
        ];

        DB::table('ad_categories')->insert($data);
    }
}
