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
            [
                'name' => 'Manager Tenant',
                'is_corporate' => 0
            ],
        ];

        // Hapus data lama dan insert ulang
        DB::table('roles')->truncate();
        DB::table('roles')->insert($data);
    }
}
