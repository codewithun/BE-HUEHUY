<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdCategory;
use App\Models\AppConfig;
use App\Models\Cube;
use App\Models\DynamicContentCube;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdController extends Controller
{
    public function getPromoRecommendation(Request $request)
    {
        $worldIdFilter = $request->get('world_id', null);

        $user = Auth::user();

        $worlds = [];

        // Handle case when user is not authenticated (public endpoint)
        if ($user && $user->worlds) {
            foreach ($user->worlds as $world) {
                array_push($worlds, $world->world_id);
            }
        }

        $model = Ad::with('cube', 'cube.cube_type', 'cube.opening_hours', 'cube.world')
            ->select([
                'ads.*',
                DB::raw('CAST(IF(ads.is_daily_grab = 1,
                    (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                    SUM(total_grab)
                ) AS SIGNED) AS total_grab'),
                DB::raw('CAST(IF(ads.is_daily_grab = 1,
                    ads.max_grab - (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                    ads.max_grab - SUM(total_grab)
                ) AS SIGNED) AS total_remaining'),
            ])
            ->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
            ->leftJoin('cubes', 'cubes.id', 'ads.cube_id')
            ->where('cubes.status', 'active')
            ->where('is_information', 0)
            // ->where('cubes.is_information', '<>', '1')
            ->when($worldIdFilter, function ($query) use ($worldIdFilter) {

                return $query->where('cubes.world_id', $worldIdFilter);
            }, function ($query) use ($worlds) {

                return $query->where(function ($q) use ($worlds) {
                    if (empty($worlds)) {
                        // If no user worlds, show all ads with null world_id or show all
                        $q->whereNull('cubes.world_id');
                    } else {
                        $q->whereNull('cubes.world_id')
                            ->orWhereIn('cubes.world_id', $worlds);
                    }
                });
            })
            ->groupBy('ads.id')
            ->orderBy('cubes.is_recommendation', 'desc')
            ->inRandomOrder()
            ->limit(10)
            ->get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function getPromoNearest(Request $request, $lat, $long)
    {
        $worldIdFilter = $request->get('world_id', null);

        $user = Auth::user();

        $worlds = [];

        // Handle case when user is not authenticated (public endpoint)
        if ($user && $user->worlds) {
            foreach ($user->worlds as $world) {
                array_push($worlds, $world->world_id);
            }
        }

        $model = Ad::with('cube', 'cube.cube_type', 'cube.world')
            ->select([
                'ads.*',
                DB::raw("
                (
                    (
                        (
                            acos(
                                sin(($lat * pi() / 180))
                                *
                                sin((map_lat * pi() / 180)) + cos(($lat * pi() / 180))
                                *
                                cos((map_lat * pi() / 180)) * cos(((". $long . " - map_lng) * pi()/180))
                            )
                        ) * 180/pi()
                    ) * 60 * 1.1515 * 1.609344
                ) as distance
                ")
            ])
            ->join('cubes', 'cubes.id', 'ads.cube_id')
            ->when($worldIdFilter, function ($query) use ($worldIdFilter) {

                return $query->where('cubes.world_id', $worldIdFilter);
            }, function ($query) use ($worlds) {

                return $query->where(function ($q) use ($worlds) {
                    if (empty($worlds)) {
                        // If no user worlds, show all ads with null world_id or show all
                        $q->whereNull('cubes.world_id');
                    } else {
                        $q->whereNull('cubes.world_id')
                            ->orWhereIn('cubes.world_id', $worlds);
                    }
                });
            })
            ->where('is_information', 0)
            ->where('cubes.status', 'active')
            ->where('cubes.is_information', '<>', '1')
            ->orderBy('distance', 'ASC')
            ->limit(6)
            ->get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function getPrimaryCategory()
    {
        $model = AdCategory::where('is_primary_parent', true)
            ->limit(7)
            ->get();

        $other_category_icon = AppConfig::where('code', 'OTHER_CATEGORY_PRODUCT')
            ->first('value')->toArray()['value'] ?? null;

        // $other_category_icon = $other_category_icon ? json_decode($other_category_icon->value) : null;

        return response([
            'message' => 'Success',
            'data' => $model,
            'other_category_icon' => $other_category_icon,
        ]);
    }

    public function getCategory()
    {
        $model = AdCategory::with('childs')
            ->whereNull('parent_id')
            ->get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function getAds(Request $request, $lat, $long)
    {
        $world_id = $request->get("world_id", null);
        $sortBy = $request->get("sortBy", "created_at");
        $sortDirection = $request->get("sortDirection", "DESC");

        $model = new Ad();
        $query = Ad::with('cube', 'cube.cube_type');
        
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query, ['ad_category.name']);
        } else {
            $query = $query;
        }

        if($world_id) {
            $query = $query->where('cubes.world_id', $world_id);
        } else {
            $user = Auth::user();
            
            // Handle case when user is not authenticated (public endpoint)
            if ($user && $user->worlds) {
                $worldRegisteredId = $user->worlds->map(function ($item) {
                    return $item->world_id;
                });

                $query = $query->where(function ($q) use ($worldRegisteredId) {
                    $q->whereNull('cubes.world_id')
                        ->orWhereIn('cubes.world_id', $worldRegisteredId);
                });
            } else {
                // If no user or no worlds, show only ads with null world_id
                $query = $query->whereNull('cubes.world_id');
            }
        }

        $query =  $query->select([
                'ads.*',
                DB::raw("
                (
                    (
                        (
                            acos(
                                sin(($lat * pi() / 180))
                                *
                                sin((map_lat * pi() / 180)) + cos(($lat * pi() / 180))
                                *
                                cos((map_lat * pi() / 180)) * cos(((". $long . " - map_lng) * pi()/180))
                            )
                        ) * 180/pi()
                    ) * 60 * 1.1515 * 1.609344
                ) as distance
                ")
            ])
            ->join('cubes', 'cubes.id', 'ads.cube_id')
            ->where('cubes.status', 'active')
            ->where('is_information', 0)
            ->where('cubes.is_information', '<>', '1')
            // ->orderBy('distance', 'ASC')
            ->orderBy($sortBy, $sortDirection)
            ->limit(20)
            ->get();


        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }

    public function getCubeByCode(Request $request, $code)
    {
        $user = Auth::user();

        $worlds = [];

        foreach ($user->worlds as $world) {
            array_push($worlds, $world->world_id);
        }

        DB::beginTransaction();

        $query = Cube::with('ads', 'tags', 'ads.ad_category', 'cube_type', 'user', 'corporate', 'world');

        $query =  $query->select([
                'cubes.*',
            ])
            ->where('code', $code)
            ->where(function ($query) use ($worlds) {
                $query->whereNull('cubes.world_id')
                    ->orWhereIn('cubes.world_id', $worlds);
            })
            ->where(function ($query) use ($user) {
                $query->where('status', 'active')
                    ->orWhere('cubes.user_id', $user->id);
            })
            ->first();
        
        if ($query->user_id == $user->id) {
            $query->is_my_cube = true;
        }

        // * Add Viewer Counter
        if ($query->ads) {

            foreach ($query->ads as $item) {

                try {
                    $item->viewer += 1;
                    $item->save();
                } catch (\Throwable $th) {
                    info($th);
                    DB::rollBack();
                    return response([
                        "message" => "Error: failed to count viewer",
                        'data' => $th
                    ], 500);
                }
            }

            DB::commit();
        }

        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }

    public function getCubeByCodeGeneral(Request $request, $code)
    {
        $query = Cube::with('ads', 'tags', 'ads.ad_category', 'cube_type', 'user', 'corporate', 'opening_hours')
            ->select(['cubes.*'])
            ->where('cubes.status', 'active')
            ->where('code', $code)
            ->first();

        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }

    public function getMenuCubes($id)
    {
        $menu_cubes = DynamicContentCube::where('dynamic_content_id', $id)->get();
        
        $id_cubes = [];
        foreach ($menu_cubes as $cube) {
            array_push($id_cubes, $cube->cube_id);
        }

        $model = Ad::with('cube', 'cube.cube_type', 'cube.opening_hours', 'cube.world')
            ->select([
                'ads.*'
            ])
            ->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
            ->leftJoin('cubes', 'cubes.id', 'ads.cube_id')
            ->where('cubes.status', 'active')
            ->whereIn('cubes.id', $id_cubes)
            ->groupBy('ads.id')
            ->inRandomOrder()
            ->limit(10)
            ->get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }


    public function getShuffleAds(Request $request)
    {
        $world_id = $request->get("world_id", null);
        $sortBy = $request->get("sortBy", "created_at");
        $sortDirection = $request->get("sortDirection", "DESC");

        $model = new Ad();
        $query = Ad::with('cube', 'cube.cube_type');
        
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query, ['ad_category.name']);
        } else {
            $query = $query;
        }

        if($world_id) {
            $query = $query->where('cubes.world_id', $world_id);
        } else {
            $user = Auth::user();
            
            // Handle case when user is not authenticated (public endpoint)
            if ($user && $user->worlds) {
                $worldRegisteredId = $user->worlds->map(function ($item) {
                    return $item->world_id;
                });

                $query = $query->where(function ($q) use ($worldRegisteredId) {
                    $q->whereNull('cubes.world_id')
                        ->orWhereIn('cubes.world_id', $worldRegisteredId);
                });
            } else {
                // If no user or no worlds, show only ads with null world_id
                $query = $query->whereNull('cubes.world_id');
            }
        }

        $query =  $query->select([
                'ads.*'
            ])
            ->join('cubes', 'cubes.id', 'ads.cube_id')
            ->where('cubes.status', 'active')
            ->where('is_information', 0)
            ->inRandomOrder()
            ->limit(20)
            ->get();


        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }
}
