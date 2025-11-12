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
            // === HOME (Beranda Utama) ===
            [
                'name' => 'Kategori Promo',
                'description' => 'Menampilkan semua kategori promo dalam bentuk grid',
                'type' => 'home',
                'content_type' => 'category_box',
                'is_active' => true,
                'level' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Promo Terdekat',
                'description' => 'Rekomendasi promo terdekat berdasarkan lokasi',
                'type' => 'home',
                'content_type' => 'nearby',
                'is_active' => true,
                'level' => 2,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Rekomendasi Promo',
                'description' => 'Promo yang direkomendasikan untuk pengguna',
                'type' => 'home',
                'content_type' => 'recommendation',
                'is_active' => true,
                'level' => 3,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            // === HUNTING (Beranda Promo) ===
            [
                'name' => 'Kotak Kategori Promo',
                'description' => 'Kategori untuk halaman berburu promo',
                'type' => 'hunting',
                'content_type' => 'category_box',
                'is_active' => true,
                'level' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Promo Terdekat',
                'description' => 'Promo terdekat untuk berburu',
                'type' => 'hunting',
                'content_type' => 'nearby',
                'is_active' => true,
                'level' => 2,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Rekomendasi Promo',
                'description' => 'Rekomendasi promo untuk berburu',
                'type' => 'hunting',
                'content_type' => 'recommendation',
                'is_active' => true,
                'level' => 3,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Kategori Iklan',
                'description' => 'Kategori iklan untuk berburu',
                'type' => 'hunting',
                'content_type' => 'ad_category',
                'is_active' => true,
                'level' => 4,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            // === INFORMATION (Beranda Komunitas) ===
            [
                'name' => 'Kotak Kategori Komunitas',
                'description' => 'Kategori untuk halaman komunitas',
                'type' => 'information',
                'content_type' => 'category_box',
                'is_active' => true,
                'level' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Promo Terdekat Komunitas',
                'description' => 'Promo terdekat di komunitas',
                'type' => 'information',
                'content_type' => 'nearby',
                'is_active' => true,
                'level' => 2,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Rekomendasi Promo Komunitas',
                'description' => 'Rekomendasi promo di komunitas',
                'type' => 'information',
                'content_type' => 'recommendation',
                'is_active' => true,
                'level' => 3,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Kategori Iklan Komunitas',
                'description' => 'Kategori iklan di komunitas',
                'type' => 'information',
                'content_type' => 'ad_category',
                'is_active' => true,
                'level' => 4,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        // Gunakan updateOrInsert untuk menghindari duplikasi
        foreach ($data as $item) {
            DynamicContent::updateOrInsert(
                [
                    'type' => $item['type'],
                    'content_type' => $item['content_type'],
                    'level' => $item['level']
                ],
                $item
            );
        }
    }
}
