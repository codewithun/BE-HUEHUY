<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CubeTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'name' =>  'Kubus Putih',
                'code' => 'KUPU',
                'color' => 'FFFFFF',
                'description' => 'Kubus ini adalah jenis kubus yang paling umum, biasa berisi promosi/iklan yang di pasang oleh pemilik kubus.'
            ],
            [
                'name' =>  'Kubus Merah',
                'code' => 'KUME',
                'color' => 'FF0000',
                'description' => 'Kubus ini biasa dimiliki oleh komunitas atau mitra Huehuy, Isinya sama seperti kubus putih akan tetapi dapat kubus ini punya anak kubus berupa kubus putih!'
            ],
            // [
            //     'name' =>  'Kubus Biru',
            //     'code' => 'KUBI',
            //     'color' => '0000FF',
            //     'description' => 'Kaizoku ou ni naru otoko da!'
            // ],
            // [
            //     'name' =>  'Kubus Hijau',
            //     'code' => 'KUHI',
            //     'color' => '00FF00',
            //     'description' => 'Kaizoku ou ni naru otoko da!'
            // ]
        ];

        DB::table('cube_types')->insert($data);
    }
}
