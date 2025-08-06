<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class BannerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        if (file_exists(storage_path('app/public/banner/')) && is_dir(storage_path('app/public/banner/'))) {
            array_map('unlink', glob(storage_path('app/public/banner/').'/*.*'));
            rmdir(storage_path('app/public/banner/'));
        } 
        
        mkdir(storage_path('app/public/banner/'));
        File::copy(public_path('banner.png'), storage_path('app/public/banner/banner.png'));

        Banner::insert([
            [
                'picture_source' => 'banner/banner.png',
            ],
            [
                'picture_source' => 'banner/banner.png',
            ],
            [
                'picture_source' => 'banner/banner.png',
            ]
        ]);

    }
}
