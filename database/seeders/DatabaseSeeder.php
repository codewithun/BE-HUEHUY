<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            AppConfigSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            CorporateSeeder::class,
            WorldSeeder::class,
            CubeTypeSeeder::class,
            ArticleSeeder::class,
            FaqSeeder::class,
            AdCategorySeeder::class,
            BannerSeeder::class,
            CubeSeeder::class,
        ]);
    }
}
