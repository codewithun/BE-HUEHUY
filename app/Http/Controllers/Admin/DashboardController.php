<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Corporate;
use App\Models\Cube;
use App\Models\User;
use App\Models\World;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function counterData()
    {
        $cubes = Cube::count('*');
        $ads = Ad::count('*');

        $corporates = Corporate::count('*');
        $users = User::count('*');

        $worlds = World::count('*');

        return response([
            'message' => 'Success',
            'data' => [
                'cubes' => $cubes,
                'ads' => $ads,
                'corporates' => $corporates,
                'users' => $users,
                'worlds' => $worlds,
            ]
        ]);
    }
}
