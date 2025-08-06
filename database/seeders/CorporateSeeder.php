<?php

namespace Database\Seeders;

use App\Models\Corporate;
use App\Models\CorporateUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CorporateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'name' => 'Universitas Katolik Parahyangan',
                'phone' => '0888888888888',
                'address' => 'Jl. Ciumbuleuit No.94, Hegarmanah, Kec. Cidadap, Kota Bandung, Jawa Barat 40141',
                'description' => '',
                'point' => 0,
                'corporateUser' => [
                    [
                        'corporate_id' =>  1,
                        'user_id' => 3,
                        'role_id' => 3,
                    ],
                    [
                        'corporate_id' =>  1,
                        'user_id' => 4,
                        'role_id' => 4,
                    ],
                ]
            ]
        ];

        foreach ($data as $corporate) {

            $newCorporate = Corporate::create([
                'name' => $corporate['name'],
                'phone' => $corporate['phone'],
                'address' => $corporate['address'],
                'description' => $corporate['description'],
                'point' => $corporate['point'],
            ]);

            if (count($corporate['corporateUser']) > 0) {

                foreach ($corporate['corporateUser'] as $corporateUser) {

                    $newCorporateUser = CorporateUser::create([
                        'corporate_id' => $newCorporate->id,
                        'user_id' => $corporateUser['user_id'],
                        'role_id' => $corporateUser['role_id'],
                    ]);
                }
            }
        }
    }
}
