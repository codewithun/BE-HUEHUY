<?php

namespace Database\Seeders;

use App\Models\AppConfig;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AppConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'id' => 1,
                'code' => 'MAX_CUBE_ACTIVATION_EXPIRY',
                'name' => 'Maximal Cube Activation Expiry',
                'description' => 'Config untuk maksimal aktivasi kubus yg belum aktif, jika melebihi waktu maka kubus akan dihapus. Satuan dalam bentuk hari',
                'value' => json_encode([
                    'configval' => 3
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 2,
                'code' => 'OTHER_CATEGORY_PRODUCT',
                'name' => 'Other Category Product Config',
                'description' => 'Config untuk kategori produk lainnya.',
                'value' => json_encode([
                    'name' => 'Lainnya',
                    'picture_source' => 'ad-category/lainnya.jpg'
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]
        ];

        AppConfig::insert($data);
    }
}
