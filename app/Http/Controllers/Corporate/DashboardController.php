<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Cube;
use App\Models\User;
use App\Models\World;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function counterData()
    {
        $credentialUserCorporate = Auth::user()->corporate_user;

        $cubes = Cube::where('corporate_id', $credentialUserCorporate->corporate_id)->count('*');

        $ads = Ad::join('cubes', 'cubes.id', 'ads.cube_id')
            ->where('cubes.corporate_id', $credentialUserCorporate->corporate_id)
            ->count('*');

        $users = User::join('corporate_users', 'corporate_users.user_id', 'users.id')
            ->where('corporate_users.corporate_id', $credentialUserCorporate->corporate_id)
            ->count('*');

        $worlds = World::where('corporate_id', $credentialUserCorporate->corporate_id)
            ->count('*');

        return response([
            'message' => 'Success',
            'data' => [
                'cubes' => $cubes,
                'ads' => $ads,
                'users' => $users,
                'worlds' => $worlds,
            ]
        ]);
    }
}
