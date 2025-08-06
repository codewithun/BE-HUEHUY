<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FaqSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'title' =>  'Bagaimana Huehuy Bekerja',
                'slug' => 'bagaimana-huehuy-bekerja',
                'description' => 'Cara kerja Heuhuy adalah dengan menjadikan kubus sebagai media menyebarkan promo/iklan oleh pelaku usaha. Harapanya dengan HueHuy pelaku usaha bisa lebih mudah dalam melakukan promosi, hanya dengan Gatget dimanapun dan kapanpun mereka bisa menjangkau banyak calon customer. Bagi pemburu promo Huehuy sangat berguna untuk mendapatkan promo atau voucher, promo atau voucher ini yang nantinya bisa digunakan oleh pengguna ketika berbelanja. Huehuy juga menyediakan fitur validasi promo agar lebih mudah bagi pelaku usaha untuk menvalidasi promo yang di buru oleh pengguna.'
            ],
            [
                'name' =>  'Apa itu dunia di huehuy?',
                'slug' => 'apa-itu-dunia-di-huehuy',
                'description' => 'Dunia atau Grub untuk mengelompokkan komunitas tertentu, di dalam dunia ini pengguna dapat saling berbagi kubus dan iklan/promo sesama member dari dunia yang sama.'
            ],
        ];

        DB::table('faqs')->insert($data);
    }
}
