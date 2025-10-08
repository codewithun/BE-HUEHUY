<?php

namespace App\Http\Controllers;

use App\Models\AdCategory;
use App\Models\Community;
use App\Models\Corporate;
use App\Models\Cube;
use App\Models\CubeType;
use App\Models\Role;
use App\Models\User;
use App\Models\World;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PicklistController extends Controller
{
    public function role(Request $request)
    {
        $isCorporate = $request->get('isCorporate', '');

        if ($isCorporate && $isCorporate != '') {
            return Role::where('is_corporate', 1)->get(['id as value', 'name as label']);
        } else {
            return Role::where('is_corporate', 0)->get(['id as value', 'name as label']);
        }
    }

    public function cubeType()
    {
        return CubeType::get(['id as value', DB::raw('CONCAT(name, " (", code, ")") as label'), 'color']);
    }

    public function cube(Request $request)
    {
        $corporate = $request->get('corporate_id', '');

        if ($corporate && $corporate != '') {

            return Cube::join('cube_types', 'cube_types.id', 'cubes.id')
                ->where('corporate_id', $corporate)
                ->get([
                    'cubes.id as value', DB::raw('CONCAT(cubes.code, " (", cube_types.code, ")") as label'), 'picture_source'
                ]);
        }

        return Cube::leftJoin('cube_types', 'cube_types.id', 'cubes.id')
            ->get([
                'cubes.id as value', DB::raw('cubes.code as label'), 'picture_source'
            ]);
    }

    public function adCategory()
    {
        return AdCategory::get(['id as value', 'name as label']);
    }

    public function corporate()
    {
        return Corporate::orderBy('name', 'asc')->get(['id as value', 'name as label']);
    }

    public function world(Request $request)
    {
        $corporate = $request->get('corporate_id', '');

        if ($corporate && $corporate != '') {
            
            return World::where('corporate_id', $corporate)
                ->get(['id as value', 'name as label']);
        }

        return World::get(['id as value', 'name as label']);
    }

    public function user(Request $request)
    {
        $world = $request->get('world_id', '');
        $corporate = $request->get('corporate_id', '');

        if ($world && $world != '') {

            return User::join('user_worlds', 'user_worlds.user_id', 'users.id')
                ->where('user_worlds.world_id', $world)
                ->orderBy('name', 'asc')
                ->get(['users.id as value', DB::raw('CONCAT(name, " (", email, ")") as label'), 'picture_source']);
        } else if ($corporate && $corporate != '') {

            return User::join('corporate_users', 'corporate_users.user_id', 'users.id')
                ->where('corporate_users.corporate_id', $corporate)
                ->orderBy('name', 'asc')
                ->get(['users.id as value', DB::raw('CONCAT(name, " (", email, ")") as label'), 'picture_source']);
        }

        return User::orderBy('name', 'asc')->get(['id as value', DB::raw('CONCAT(name, " (", email, ")") as label'), 'picture_source']);
    }

    public function community()
    {
        return Community::get(['id as value', 'name as label']);
    }
}
