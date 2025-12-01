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
        $communityId = $request->get('community_id', '');
        $search = $request->get('search', '');

        $query = Cube::leftJoin('cube_types', 'cube_types.id', 'cubes.cube_type_id')
            ->select([
                'cubes.id as value',
                DB::raw('cubes.code as label'),
                'cubes.picture_source'
            ]);

        // Filter berdasarkan corporate_id
        if ($corporate && $corporate != '') {
            $query->where('cubes.corporate_id', $corporate);
        }

        // Filter berdasarkan community_id (untuk widget komunitas)
        // Hanya ambil cube yang ada di dynamic_content komunitas tersebut
        if ($communityId && $communityId != '') {
            $query->whereExists(function ($q) use ($communityId) {
                $q->select(DB::raw(1))
                    ->from('dynamic_content_cubes')
                    ->join('dynamic_contents', 'dynamic_contents.id', '=', 'dynamic_content_cubes.dynamic_content_id')
                    ->whereColumn('dynamic_content_cubes.cube_id', 'cubes.id')
                    ->where('dynamic_contents.community_id', $communityId);
            });
        }

        if ($search && $search != '') {
            $query->where(function ($q) use ($search) {
                $q->where('cubes.code', 'LIKE', "%{$search}%")
                    ->orWhere('cube_types.name', 'LIKE', "%{$search}%")
                    ->orWhere('cube_types.code', 'LIKE', "%{$search}%");
            });
        }

        return $query->distinct()->orderBy('cubes.code')->get();
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
