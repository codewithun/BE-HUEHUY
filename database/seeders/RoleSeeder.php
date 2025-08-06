<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'name' => 'Admin',
                'is_corporate' => 0
            ],
            [
                'name' => 'User',
                'is_corporate' => 0
            ],
            [
                'name' => 'Kepala Mitra',
                'is_corporate' => 1
            ],
            [
                'name' => 'Manajer Mitra',
                'is_corporate' => 1
            ],
            [
                'name' => 'Staff Mitra',
                'is_corporate' => 1
            ],
        ];

        DB::table('roles')->insert($data);
    }
}
