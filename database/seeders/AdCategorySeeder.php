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
        if (file_exists(storage_path('app/public/ad-category/')) && is_dir(storage_path('app/public/ad-category/'))) {
            array_map('unlink', glob(storage_path('app/public/ad-category/').'/*.*'));
            rmdir(storage_path('app/public/ad-category/'));
        } 
        
        mkdir(storage_path('app/public/ad-category/'));
        File::copy(public_path('resto-cafe.jpeg'), storage_path('app/public/ad-category/resto-cafe.jpeg'));
        File::copy(public_path('teknologi.jpg'), storage_path('app/public/ad-category/teknologi.jpg'));
        File::copy(public_path('hotel.jpg'), storage_path('app/public/ad-category/hotel.jpg'));
        File::copy(public_path('hiburan.jpg'), storage_path('app/public/ad-category/hiburan.jpg'));
        File::copy(public_path('otomotif.jpg'), storage_path('app/public/ad-category/otomotif.jpg'));
        File::copy(public_path('properti.jpg'), storage_path('app/public/ad-category/properti.jpg'));
        
        // Optional placeholder/category image if available
        if (file_exists(public_path('category.png'))) {
            File::copy(public_path('category.png'), storage_path('app/public/ad-category/category.png'));
        } else if (file_exists(public_path('storage/ad-category/category.png'))) {
            File::copy(public_path('storage/ad-category/category.png'), storage_path('app/public/ad-category/category.png'));
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
