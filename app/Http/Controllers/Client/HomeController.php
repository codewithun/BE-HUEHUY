<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdCategory;
use App\Models\AdminContact;
use App\Models\Article;
use App\Models\Banner;
use App\Models\CubeType;
use App\Models\DynamicContent;
use App\Models\Faq;
use App\Models\World;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function banner() 
    {
        $model = Banner::get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function faq() 
    {
        $model = Faq::get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function faqBySlugOrId($slug)
    {
        $model = Faq::where(function ($query) use ($slug) {
            return $query->where('id', $slug)
                ->orWhere('slug', $slug);
        })->first();

        if (!$model) {
            return response([
                'message' => 'Data not found'
            ], 404);
        }

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function article() 
    {
        $model = Article::get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function articleBySlugOrId($slug)
    {
        $model = Article::where(function ($query) use ($slug) {
            return $query->where('id', $slug)
                ->orWhere('slug', $slug);
        })->first();

        if (!$model) {
            return response([
                'message' => 'Data not found'
            ], 404);
        }

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function category(Request $request)
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

        $homeCategory = AdCategory::where('is_home_display', true)
            ->get();

        foreach ($homeCategory as $key => $category) {

            $ads = Ad::with('ad_category', 'cube.cube_type')
                ->select('ads.*', 
                    DB::raw('CAST(IF(ads.is_daily_grab = 1,
                        (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                        SUM(total_grab)
                    ) AS SIGNED) AS total_grab'),
                    DB::raw('CAST(IF(ads.is_daily_grab = 1,
                        ads.max_grab - (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                        ads.max_grab - SUM(total_grab)
                    ) AS SIGNED) AS total_remaining')
                )
                ->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
                ->leftJoin('ad_categories', 'ad_categories.id', 'ads.ad_category_id')
                ->leftJoin('ad_categories as parent_category', 'parent_category.id', 'ad_categories.parent_id')
                ->leftJoin('ad_categories as grand_category', 'grand_category.id', 'parent_category.parent_id')
                ->rightJoin('cubes', 'cubes.id', 'ads.cube_id')
                ->where(function ($query) use ($category) { 
                    $query->orWhere('ads.ad_category_id', $category->id)
                    ->orWhere('ad_categories.parent_id', $category->id)
                    ->orWhere('parent_category.parent_id', $category->id)
                    ->orWhere('grand_category.parent_id', $category->id);
                })
                ->where('cubes.status', 'active')
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
                ->distinct('ads.id')
                ->groupBy('ads.id')
                // ->orderBy('cubes.is_recommendation', 'desc')
                ->inRandomOrder()
                ->limit(5)
                ->get();

            $childCategory = AdCategory::where('parent_id', $category->id)
                ->get();

            $category->child_categories = $childCategory;
            $category->ads = $ads;
        }

        return response([
            'message' => 'Success',
            'data' => $homeCategory
        ]);
    }

    public function adminContact()
    {
        $model = AdminContact::get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function cubeType() 
    {
        $model = CubeType::get();

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    function worlds() {
        $user = Auth::user();

        $user_worlds = [];

        foreach ($user->worlds as $world) {
            array_push($user_worlds, $world->world_id);
        }

        $user_has_worlds = World::whereIn('id', $user_worlds)->get()->toArray();
        foreach ($user_has_worlds as $key => $world) {
            $user_has_worlds[$key]['active'] = true;
        }
        
        $worlds = World::whereNotIn('id', $user_worlds)->get()->toArray();

        $worlds = array_merge($user_has_worlds, $worlds);

        return response([
            'message' => 'Success',
            'data' => $worlds
        ]);
    }

    public function getDynamicContentConfig(Request $request)
    {
        $filterType = $request->get('type', '');

        // Load relasi yang diperlukan untuk FE
        $query = DynamicContent::with([
            'dynamic_content_cubes' => function($query) {
                $query->with('cube');
            },
            'ad_category',
            'community'
        ]);

        if ($filterType != '') {
            $model = $query->where('type', $filterType)
                ->orderBy('level', 'asc')
                ->get();
        } else {
            $model = $query->orderBy('level', 'asc')->get();
        }

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }
}
