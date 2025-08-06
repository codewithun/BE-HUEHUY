<?php

namespace Database\Seeders;

use App\Models\Ad;
use App\Models\Cube;
use App\Models\CubeTag;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CubeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (file_exists(storage_path('app/public/ads/')) && is_dir(storage_path('app/public/ads/'))) {
            array_map('unlink', glob(storage_path('app/public/ads/').'/*.*'));
            rmdir(storage_path('app/public/ads/'));
        } 
        
        mkdir(storage_path('app/public/ads/'));
        File::copy(public_path('pipinos.jpg'), storage_path('app/public/ads/pipinos.jpg'));
        File::copy(public_path('bariton.png'), storage_path('app/public/ads/bariton.png'));
        File::copy(public_path('markaz.jpg'), storage_path('app/public/ads/markaz.jpg'));
        File::copy(public_path('unpar.jpg'), storage_path('app/public/ads/unpar.jpg'));

        $data = [
            [
                'cube_type_id' =>  1,
                'user_id' => 2,
                'address' => 'Jl. Ciumbuleuit No.85, Hegarmanah, Kec. Cidadap, Kota Bandung, Jawa Barat 40141',
                'map_lat' => '-6.880363625925466',
                'map_lng' => '107.60369058040388',
                'title' => 'PIPINOS Central Kitchen',
                'category_id' => 1,
                'description' => 'Tempatnya lucu, nyaman buat nongkrong atau mau healing cantik',
                'picture_source' => 'ads/pipinos.jpg',
            ],
            [
                'cube_type_id' =>  1,
                'user_id' => 2,
                'address' => 'Jl. Ciumbuleuit No.101, Hegarmanah, Kec. Cidadap, Kota Bandung, Jawa Barat 40141',
                'map_lat' => '-6.879505590421219',
                'map_lng' => '107.60334159773718',
                'title' => 'Bariton Bakery',
                'category_id' => 1,
                'description' => 'Di bariton bakery ada risoles tp kalau jajanan pasar seperti di "pasar" pd umumnya tidak. Mereka lebih ke menjual roti-rotian (lapis, roti coklat ,roti pizza, kue gulung, cream puff dll)',
                'picture_source' => 'ads/bariton.png',
            ],
            [
                'cube_type_id' =>  1,
                'user_id' => 2,
                'address' => 'Jl. Ciumbuleuit No.73, Hegarmanah, Kec. Cidadap, Kota Bandung, Jawa Barat 40141',
                'map_lat' => '-6.881136444863934',
                'map_lng' => '107.60393467668239',
                'title' => 'Markaz Collective Space',
                'category_id' => 3,
                'description' => '',
                'picture_source' => 'ads/markaz.jpg',
            ],
            [
                'cube_type_id' =>  2,
                'corporate_id' => 1,
                'address' => 'Jl. Ciumbuleuit No.94, Hegarmanah, Kec. Cidadap, Kota Bandung, Jawa Barat 40141',
                'map_lat' => '-6.87465215777713',
                'map_lng' => '107.60463431751892',
                'title' => 'UNPAR: Pendaftaran Jalur Mitra',
                'category_id' => 7,
                'description' => 'Siapkan diri kamu untuk melakukan pendaftaran di Universitas Katolik Parahyangan jalur Mitra',
                'picture_source' => 'ads/unpar.jpg',
            ],
        ];

        foreach ($data as $item) {
            $cube_model = new Cube;

            $cube = Cube::create([
                'cube_type_id' => $item['cube_type_id'],
                'user_id' => $item['user_id'] ?? null,
                'corporate_id' => $item['corporate_id'] ?? null,
                'address' => $item['address'],
                'map_lat' => $item['map_lat'],
                'map_lng' => $item['map_lng'],
                'status' => 'active',
                'code' => $cube_model->generateCubeCode($item['cube_type_id']),
            ]);

            CubeTag::create([
                'cube_id' => $cube['id'],
                'address' => $item['address'],
                'map_lat' => $item['map_lat'],
                'map_lng' => $item['map_lng'],
            ]);
            
            Ad::create([
                'cube_id' => $cube['id'],
                'ad_category_id' => $item['category_id'],
                'title' => $item['title'],
                'slug' => \Str::slug($item['title']),
                'description' => $item['description'],
                'picture_source' => $item['picture_source'],
                'max_grab' => 10,
                'promo_type' => 'offline',
                'status' => 'active',
            ]);
        }
    }
}
