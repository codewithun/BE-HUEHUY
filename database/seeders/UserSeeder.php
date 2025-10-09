<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;

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

        foreach ($data as $row) {
            // Idempotent: create if not exists, else update core fields
            User::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name'        => $row['name'],
                    'password'    => $row['password'],
                    'role_id'     => $row['role_id'],
                    'verified_at' => $row['verified_at'],
                ]
            );
        }
    }
}
