<?php

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ArticleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (file_exists(storage_path('app/public/article/')) && is_dir(storage_path('app/public/article/'))) {
            array_map('unlink', glob(storage_path('app/public/article/').'/*.*'));
            rmdir(storage_path('app/public/article/'));
        } 
        
        mkdir(storage_path('app/public/article/'));
        File::copy(public_path('article-1.jpg'), storage_path('app/public/article/article-1.jpg'));
        File::copy(public_path('article-2.jpg'), storage_path('app/public/article/article-2.jpg'));

        
        // Article::factory()->count(12)->create();
        $data = [
            [
                'title' =>  'Perkenalkan Huehuy',
                'slug' => 'perkenalkan-huehuy',
                'description' => 'Simplenya Huehuy itu aplikasi advertising dengan konsep berburu promo, dengan melibatkan penyedia promo atau disini biasanya pemiliik bisnis dan pemburu promo atau bisasanya customer yang menginginkan potongan harga dari smua produk. Bahkan untuk saat ini Huehuy tidak hanya berguna untuk media promosi saja  beberapa agensi menggunakannya untuk udangan online dan pengumuman untuk di sebarkan ke komunitas tertentu.',
                'picture_source' => 'article/article-1.jpg',
            ],
            [
                'title' =>  'Dunia, Fitur Baru huehuy',
                'slug' => 'dunia-fitur-baru-huehuy',
                'description' => 'Dunia atau Grub fitur ini berguna untuk mengelompokkan komunitas tertentu, di dalam dunia ini pengguna dapat saling berbagi kubus dan iklan/promo sesama member dari dunia yang sama.',
                'picture_source' => 'article/article-2.jpg',
            ],
        ];

        DB::table('articles')->insert($data);
    }
}
