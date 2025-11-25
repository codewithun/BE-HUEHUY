<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Corporate;
use App\Models\Cube;
use App\Models\User;
use App\Models\World;
use App\Models\Community;
use App\Models\HuehuyAd;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function counterData()
    {
        $cubes       = Cube::count('*');
        $ads         = Ad::count('*');
        $huehuyAds   = HuehuyAd::count('*');
        $corporates  = Corporate::count('*');
        $users       = User::count('*');
        $communities = Community::count('*');

        return response([
            'message' => 'Success',
            'data' => [
                'cubes'        => $cubes,
                'ads'          => $ads,
                'huehuy_ads'   => $huehuyAds,
                'corporates'   => $corporates,
                'users'        => $users,
                'communities'  => $communities,
            ]
        ]);
    }
}
