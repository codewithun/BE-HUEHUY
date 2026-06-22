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
use Illuminate\Support\Facades\Schema;

class HomeController extends Controller
{
    /**
     * Filter promo/voucher visibility:
     * - target_type null/empty/all => visible to everyone
     * - target_type user => visible only to selected user
     * - target_type community => visible only to active community member
     */
    private function applyAdAudienceVisibilityFilter($query, $user = null)
    {
        if (!Schema::hasColumn('ads', 'target_type')) {
            return $query;
        }

        $userId = $user?->id;

        return $query->where(function ($q) use ($userId) {
            $q->whereNull('ads.target_type')
                ->orWhere('ads.target_type', '')
                ->orWhere('ads.target_type', 'all');

            if ($userId) {
                $q->orWhere(function ($userTarget) use ($userId) {
                    $userTarget->where('ads.target_type', 'user')
                        ->where(function ($w) use ($userId) {
                            if (Schema::hasColumn('ads', 'target_user_id')) {
                                $w->where('ads.target_user_id', $userId);
                            } else {
                                $w->whereRaw('1 = 0');
                            }

                            if (Schema::hasTable('ad_target_users')) {
                                $w->orWhereExists(function ($sub) use ($userId) {
                                    $sub->select(DB::raw(1))
                                        ->from('ad_target_users')
                                        ->whereColumn('ad_target_users.ad_id', 'ads.id')
                                        ->where('ad_target_users.user_id', $userId);
                                });
                            }

                            if (Schema::hasTable('notifications')) {
                                $w->orWhereExists(function ($sub) use ($userId) {
                                    $sub->select(DB::raw(1))
                                        ->from('notifications')
                                        ->where('notifications.user_id', $userId)
                                        ->whereColumn('notifications.target_id', 'ads.id')
                                        ->where(function ($n) {
                                            $n->where('notifications.target_type', 'ad')
                                                ->orWhere('notifications.target_type', 'promo')
                                                ->orWhere('notifications.target_type', 'voucher')
                                                ->orWhere('notifications.type', 'ad')
                                                ->orWhere('notifications.type', 'promo')
                                                ->orWhere('notifications.type', 'voucher');
                                        });
                                });
                            }
                        });
                });
            }

            if ($userId && Schema::hasTable('community_memberships')) {
                $q->orWhere(function ($communityTarget) use ($userId) {
                    $communityTarget->where('ads.target_type', 'community')
                        ->whereNotNull('ads.community_id')
                        ->whereExists(function ($sub) use ($userId) {
                            $sub->select(DB::raw(1))
                                ->from('community_memberships')
                                ->whereColumn('community_memberships.community_id', 'ads.community_id')
                                ->where('community_memberships.user_id', $userId)
                                ->where('community_memberships.status', 'active');
                        });
                });
            }
        });
    }

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

        if ($user && $user->worlds) {
            foreach ($user->worlds as $world) {
                array_push($worlds, $world->world_id);
            }
        }

        $homeCategory = AdCategory::where('is_home_display', true)->get();

        foreach ($homeCategory as $key => $category) {
            $adsQuery = Ad::with('ad_category', 'cube.cube_type')
                ->select(
                    'ads.*',
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
                            $q->whereNull('cubes.world_id');
                        } else {
                            $q->whereNull('cubes.world_id')
                                ->orWhereIn('cubes.world_id', $worlds);
                        }
                    });
                });

            $adsQuery = $this->applyAdAudienceVisibilityFilter($adsQuery, $user);

            $ads = $adsQuery->distinct('ads.id')
                ->groupBy('ads.id')
                ->inRandomOrder()
                ->limit(5)
                ->get();

            $childCategory = AdCategory::where('parent_id', $category->id)->get();

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

    function worlds()
    {
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
        $communityId = $request->get('community_id', null);
        $user = Auth::user();

        $query = DynamicContent::with([
            'dynamic_content_cubes' => function ($q) use ($communityId, $user) {
                $q->with([
                    'cube' => function ($cubeQ) use ($communityId, $user) {
                        $cubeQ->with([
                            'ads' => function ($adsQ) use ($communityId, $user) {
                                $adsQ->where('status', 'active');

                                $this->applyAdAudienceVisibilityFilter($adsQ, $user);

                                $adsQ->when($communityId, function ($scoped) use ($communityId) {
                                    $scoped->where(function ($nested) use ($communityId) {
                                        $nested->whereNull('ads.community_id')
                                            ->orWhere('ads.community_id', $communityId);
                                    });
                                }, function ($global) {
                                    $global->whereNull('ads.community_id');
                                });
                            },
                            'cube_type',
                            'opening_hours',
                        ]);
                    },
                ]);
            },
            'ad_category',
            'community',
        ]);

        if ($communityId && $communityId !== '' && $communityId !== 'null') {
            $query->where('community_id', $communityId);
        } else {
            $query->whereNull('community_id');
        }

        if ($filterType != '') {
            $model = $query->where('type', $filterType)
                ->orderBy('level', 'asc')
                ->get();
        } else {
            $model = $query->orderBy('level', 'asc')->get();
        }

        if (!$communityId) {
            $model->each(function ($widget) {
                if ($widget->community_id === null && $widget->relationLoaded('dynamic_content_cubes')) {
                    $widget->dynamic_content_cubes->each(function ($dcc) {
                        if ($dcc->relationLoaded('cube') && $dcc->cube && $dcc->cube->relationLoaded('ads')) {
                            $filtered = $dcc->cube->ads->filter(fn($ad) => $ad->community_id === null);
                            $dcc->cube->setRelation('ads', $filtered->values());
                        }
                    });
                }
            });
        }

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }
}
