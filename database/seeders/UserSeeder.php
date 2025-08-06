<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'name' => 'Joko Gunawan Dump',
                'email' => 'admin@gmail.com',
                'password' => bcrypt('password'),
                'role_id' => 1,
                'verified_at' => Carbon::now(),
            ],
            [
                'name' => 'Budi Susilo Dump',
                'email' => 'user@gmail.com',
                'password' => bcrypt('password'),
                'role_id' => 2,
                'verified_at' => Carbon::now(),
            ],
            [
                'name' => 'Danang Mahendra Dump',
                'email' => 'kep-unpar@gmail.com',
                'password' => bcrypt('password'),
                'role_id' => 2,
                'verified_at' => Carbon::now(),
            ],
            [
                'name' => 'Didik Mulyanto Dump',
                'email' => 'user-unpar@gmail.com',
                'password' => bcrypt('password'),
                'role_id' => 2,
                'verified_at' => Carbon::now(),
            ]
        ];

        DB::table('users')->insert($data);
    }
}
