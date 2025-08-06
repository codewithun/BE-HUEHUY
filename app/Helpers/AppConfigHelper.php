<?php

namespace App\Helpers;

use App\Models\AppConfig;
use Illuminate\Support\Str;

class AppConfigHelper {
    
    public static function getConfig($code) {

        $result = AppConfig::where('code', $code)
            ->first();

        $result->value = json_decode($result->value);

        return $result;
    }
}