<?php

namespace Database\Seeders;

use App\Models\DynamicContent;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DynamicContentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'name' => 'Kategori Promo',
                'description' => null,
                'type' => 'home',
                'content_type' => 'category',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'level' => 1,
            ],
            [
                'name' => 'Promo Terdekat',
                'description' => 'Rekomendasi promo terderkat',
                'type' => 'home',
                'is_active' => true,
                'content_type' => 'nearby',
                'level' => 2,
                'created_at' => Carbon::now()
            ],
            [
                'name' => 'Rekomendasi Promo',
                'description' => 'Rekomendasi promo yang menarik',
                'type' => 'home',
                'is_active' => true,
                'content_type' => 'recommendation',
                'level' => 3,
                'created_at' => Carbon::now()
            ],
            [
                'name' => 'Rekomendasi Kategori Promo',
                'description' => null,
                'type' => 'home',
                'is_active' => true,
                'content_type' => 'ad_category',
                'level' => 4,
                'created_at' => Carbon::now()
            ],
            [
                'name' => 'Kategori Promo',
                'description' => null,
                'type' => 'hunting',
                'content_type' => 'category',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'level' => 1,
            ],
            [
                'name' => 'Promo Terdekat',
                'description' => null,
                'type' => 'hunting',
                'is_active' => true,
                'content_type' => 'nearby',
                'level' => 2,
                'created_at' => Carbon::now()
            ],
            [
                'name' => 'Rekomendasi Promo',
                'description' => null,
                'type' => 'hunting',
                'is_active' => true,
                'content_type' => 'recommendation',
                'level' => 3,
                'created_at' => Carbon::now()
            ],
            [
                'name' => 'Rekomendasi Kategori Promo',
                'description' => null,
                'type' => 'hunting',
                'is_active' => true,
                'content_type' => 'ad_category',
                'level' => 4,
                'created_at' => Carbon::now()
            ],
        ];

        DynamicContent::insert($data);
    }
}
