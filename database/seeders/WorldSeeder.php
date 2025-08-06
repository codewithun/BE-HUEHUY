<?php

namespace Database\Seeders;

use App\Models\UserWorld;
use App\Models\World;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorldSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'corporate_id' => 1,
                'name' => 'Unpar',
                'description' => '',
                'worldMember' => [
                    [
                        'world_id' =>  1,
                        'user_id' => 4,
                    ]
                ]
            ]
        ];

        foreach ($data as $world) {

            $newWorld = World::create([
                'corporate_id' => $world['corporate_id'],
                'name' => $world['name'],
                'description' => $world['description'],
            ]);

            if (count($world['worldMember']) > 0) {

                foreach ($world['worldMember'] as $worldMember) {

                    $newWorldMember = UserWorld::create([
                        'world_id' => $newWorld->id,
                        'user_id' => $worldMember['user_id'],
                    ]);
                }
            }
        }
    }
}
