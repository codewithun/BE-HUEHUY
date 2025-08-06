<?php

namespace App\Helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StringHelper {
    
    public static function uniqueSlug($string, $additionalDigit = 4) {

        $slug = Str::slug($string);
        $rand = Str::random($additionalDigit);

        return "$slug-$rand";
    }
}