<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdCategory;
use App\Models\AppConfig;
use App\Models\Cube;
use App\Models\DynamicContentCube;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class AdController extends Controller
{
    private function applyAudienceVisibilityFilter($query, ?\App\Models\User $user = null)
    {
        // Safety: kalau kolom target_type belum ada di tabel ads, jangan paksa filter
        // supaya endpoint lama tidak error SQL.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('ads', 'target_type')) {
            return $query;
        }

        $userId = $user?->id;

        $hasTargetUserIdColumn = \Illuminate\Support\Facades\Schema::hasColumn('ads', 'target_user_id');

        $hasAdTargetUsers =
            \Illuminate\Support\Facades\Schema::hasTable('ad_target_users') &&
            \Illuminate\Support\Facades\Schema::hasColumn('ad_target_users', 'ad_id') &&
            \Illuminate\Support\Facades\Schema::hasColumn('ad_target_users', 'user_id');

        $hasNotifications =
            \Illuminate\Support\Facades\Schema::hasTable('notifications') &&
            \Illuminate\Support\Facades\Schema::hasColumn('notifications', 'target_id') &&
            \Illuminate\Support\Facades\Schema::hasColumn('notifications', 'user_id');

        $hasNotificationTargetType =
            $hasNotifications &&
            \Illuminate\Support\Facades\Schema::hasColumn('notifications', 'target_type');

        $hasNotificationType =
            $hasNotifications &&
            \Illuminate\Support\Facades\Schema::hasColumn('notifications', 'type');

        $hasCommunityMemberships =
            \Illuminate\Support\Facades\Schema::hasTable('community_memberships') &&
            \Illuminate\Support\Facades\Schema::hasColumn('community_memberships', 'community_id') &&
            \Illuminate\Support\Facades\Schema::hasColumn('community_memberships', 'user_id');

        $hasCommunityMembershipStatus =
            $hasCommunityMemberships &&
            \Illuminate\Support\Facades\Schema::hasColumn('community_memberships', 'status');

        return $query->where(function ($q) use (
            $userId,
            $hasTargetUserIdColumn,
            $hasAdTargetUsers,
            $hasNotifications,
            $hasNotificationTargetType,
            $hasNotificationType,
            $hasCommunityMemberships,
            $hasCommunityMembershipStatus
        ) {
            // 1) Konten umum: boleh tampil ke semua user.
            $q->whereNull('ads.target_type')
                ->orWhere('ads.target_type', '')
                ->orWhere('ads.target_type', 'all');

            // Kalau tidak login, jangan tampilkan konten target user/community.
            if (!$userId) {
                return;
            }

            // 2) Konten khusus user tertentu.
            $q->orWhere(function ($userTarget) use (
                $userId,
                $hasTargetUserIdColumn,
                $hasAdTargetUsers,
                $hasNotifications,
                $hasNotificationTargetType,
                $hasNotificationType
            ) {
                $userTarget->where('ads.target_type', 'user')
                    ->where(function ($w) use (
                        $userId,
                        $hasTargetUserIdColumn,
                        $hasAdTargetUsers,
                        $hasNotifications,
                        $hasNotificationTargetType,
                        $hasNotificationType
                    ) {
                        // Kolom langsung di ads, kalau ada.
                        if ($hasTargetUserIdColumn) {
                            $w->where('ads.target_user_id', $userId);
                        } else {
                            // Biar group where tetap punya kondisi awal yang aman.
                            $w->whereRaw('1 = 0');
                        }

                        // Pivot target user, kalau ada.
                        if ($hasAdTargetUsers) {
                            $w->orWhereExists(function ($sub) use ($userId) {
                                $sub->select(DB::raw(1))
                                    ->from('ad_target_users')
                                    ->whereColumn('ad_target_users.ad_id', 'ads.id')
                                    ->where('ad_target_users.user_id', $userId);
                            });
                        }

                        // Fallback: target user lewat notifications, kalau ada.
                        if ($hasNotifications) {
                            $w->orWhereExists(function ($sub) use ($userId, $hasNotificationTargetType, $hasNotificationType) {
                                $sub->select(DB::raw(1))
                                    ->from('notifications')
                                    ->where('notifications.user_id', $userId)
                                    ->whereColumn('notifications.target_id', 'ads.id');

                                if ($hasNotificationTargetType || $hasNotificationType) {
                                    $sub->where(function ($n) use ($hasNotificationTargetType, $hasNotificationType) {
                                        if ($hasNotificationTargetType) {
                                            $n->whereIn('notifications.target_type', ['ad', 'promo', 'voucher']);
                                        }

                                        if ($hasNotificationType) {
                                            if ($hasNotificationTargetType) {
                                                $n->orWhereIn('notifications.type', ['ad', 'promo', 'voucher']);
                                            } else {
                                                $n->whereIn('notifications.type', ['ad', 'promo', 'voucher']);
                                            }
                                        }
                                    });
                                }
                            });
                        }
                    });
            });

            // 3) Konten khusus komunitas: hanya tampil ke member komunitas tersebut.
            if ($hasCommunityMemberships) {
                $q->orWhere(function ($communityTarget) use ($userId, $hasCommunityMembershipStatus) {
                    $communityTarget->where('ads.target_type', 'community')
                        ->whereNotNull('ads.community_id')
                        ->whereExists(function ($sub) use ($userId, $hasCommunityMembershipStatus) {
                            $sub->select(DB::raw(1))
                                ->from('community_memberships')
                                ->whereColumn('community_memberships.community_id', 'ads.community_id')
                                ->where('community_memberships.user_id', $userId);

                            if ($hasCommunityMembershipStatus) {
                                $sub->whereIn('community_memberships.status', [
                                    'active',
                                    'approved',
                                    'accepted',
                                    'joined',
                                    'member',
                                    1,
                                    '1',
                                ]);
                            }
                        });
                });
            }
        });
    }

    public function getPromoRecommendation(Request $request)
    {
        $worldIdFilter = $request->get('world_id', null);
        $communityId = $request->get('community_id', null);
        $user = Auth::user();

        $worlds = [];
        if ($user && $user->worlds) {
            foreach ($user->worlds as $world) {
                $worlds[] = $world->world_id;
            }
        }

        $model = Ad::with('cube', 'cube.cube_type', 'cube.opening_hours', 'cube.world')
            ->select([
                'ads.*',
                DB::raw('(
                    SELECT COALESCE(SUM(total_grab),0)
                    FROM summary_grabs
                    WHERE ad_id = ads.id
                ) as total_grab'),
                DB::raw('(
                    CASE
                        WHEN ads.unlimited_grab = 1 THEN 9999999
                        WHEN ads.is_daily_grab = 1 THEN
                            ads.max_grab - (
                                SELECT COALESCE(SUM(total_grab),0)
                                FROM summary_grabs
                                WHERE ad_id = ads.id
                                AND date = CURDATE()
                            )
                        ELSE ads.max_grab
                    END
                ) as total_remaining')
            ])
            ->leftJoin('cubes', 'cubes.id', 'ads.cube_id')
            ->where('cubes.status', 'active')
            ->where('is_information', 0)
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
            })
            ->when($communityId, function ($q) use ($communityId) {
                $q->where(function ($sub) use ($communityId) {
                    $sub->where('ads.community_id', $communityId)
                        ->orWhereNull('ads.community_id');
                });
            }, function ($q) {
                $q->whereNull('ads.community_id');
            });

        $model = $this->applyAudienceVisibilityFilter($model, $user)
            ->groupBy('ads.id', 'ads.cube_id')
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
        $communityId = $request->get('community_id', null);
        $user = Auth::user();

        $worlds = [];
        if ($user && $user->worlds) {
            foreach ($user->worlds as $world) {
                $worlds[] = $world->world_id;
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
                                cos((map_lat * pi() / 180)) * cos(((" . $long . " - map_lng) * pi()/180))
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
            ->when($communityId, function ($q) use ($communityId) {
                $q->where(function ($sub) use ($communityId) {
                    $sub->where('ads.community_id', $communityId)
                        ->orWhereNull('ads.community_id');
                });
            }, function ($q) {
                $q->whereNull('ads.community_id');
            });

        $model = $this->applyAudienceVisibilityFilter($model, $user)
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
        // * Update status kubus yang kadaluarsa menjadi inactive
        // Cek berdasarkan inactive_at
        DB::table('cubes')
            ->whereNotNull('inactive_at')
            ->whereDate('inactive_at', '<=', Carbon::now())
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        // Cek berdasarkan ads finish_validate yang sudah lewat
        $expiredCubeIds = DB::table('ads')
            ->join('cubes', 'cubes.id', '=', 'ads.cube_id')
            ->whereNotNull('ads.finish_validate')
            ->whereDate('ads.finish_validate', '<', Carbon::now())
            ->where('cubes.status', 'active')
            ->pluck('cubes.id')
            ->toArray();

        if (!empty($expiredCubeIds)) {
            DB::table('cubes')
                ->whereIn('id', $expiredCubeIds)
                ->update(['status' => 'inactive']);
        }

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

        if ($world_id) {
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

        $user = Auth::user();
        $query = $this->applyAudienceVisibilityFilter($query, $user);

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
                                cos((map_lat * pi() / 180)) * cos(((" . $long . " - map_lng) * pi()/180))
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

        // * Update status kubus yang kadaluarsa menjadi inactive
        // Cek berdasarkan inactive_at
        DB::table('cubes')
            ->whereNotNull('inactive_at')
            ->whereDate('inactive_at', '<=', Carbon::now())
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        // Cek berdasarkan ads finish_validate yang sudah lewat
        $expiredCubeIds = DB::table('ads')
            ->join('cubes', 'cubes.id', '=', 'ads.cube_id')
            ->whereNotNull('ads.finish_validate')
            ->whereDate('ads.finish_validate', '<', Carbon::now())
            ->where('cubes.status', 'active')
            ->pluck('cubes.id')
            ->toArray();

        if (!empty($expiredCubeIds)) {
            DB::table('cubes')
                ->whereIn('id', $expiredCubeIds)
                ->update(['status' => 'inactive']);
        }

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
        // * Update status kubus yang kadaluarsa menjadi inactive
        // Cek berdasarkan inactive_at
        DB::table('cubes')
            ->whereNotNull('inactive_at')
            ->whereDate('inactive_at', '<=', Carbon::now())
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        // Cek berdasarkan ads finish_validate yang sudah lewat
        $expiredCubeIds = DB::table('ads')
            ->join('cubes', 'cubes.id', '=', 'ads.cube_id')
            ->whereNotNull('ads.finish_validate')
            ->whereDate('ads.finish_validate', '<', Carbon::now())
            ->where('cubes.status', 'active')
            ->pluck('cubes.id')
            ->toArray();

        if (!empty($expiredCubeIds)) {
            DB::table('cubes')
                ->whereIn('id', $expiredCubeIds)
                ->update(['status' => 'inactive']);
        }

        $query = Cube::with([
            'ads' => function ($query) {
                $query->where('status', 'active');
            },
            'ads.ad_category',
            'tags',
            'cube_type',
            'user',
            'corporate',
            'opening_hours'
        ])
            ->select(['cubes.*'])
            ->where('cubes.status', 'active')
            ->where('code', $code)
            ->first();

        // Jika tidak ditemukan, coba cari berdasarkan code di ads
        if (!$query) {
            $adQuery = \App\Models\Ad::with([
                'cube' => function ($query) {
                    $query->where('status', 'active');
                },
                'cube.ads' => function ($query) {
                    $query->where('status', 'active');
                },
                'cube.ads.ad_category',
                'cube.tags',
                'cube.cube_type',
                'cube.user',
                'cube.corporate',
                'cube.opening_hours'
            ])
                ->where('code', $code)
                ->where('status', 'active')
                ->first();

            if ($adQuery && $adQuery->cube) {
                $query = $adQuery->cube;
            }
        }

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
            $id_cubes[] = $cube->cube_id;
        }

        $user = Auth::user();

        $model = Ad::with('cube', 'cube.cube_type', 'cube.opening_hours', 'cube.world')
            ->select([
                'ads.*'
            ])
            ->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
            ->leftJoin('cubes', 'cubes.id', 'ads.cube_id')
            ->where('cubes.status', 'active')
            ->whereIn('cubes.id', $id_cubes);

        $model = $this->applyAudienceVisibilityFilter($model, $user)
            ->groupBy('ads.id', 'ads.cube_id', 'cubes.is_recommendation')
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
        $communityId = $request->get("community_id", null);
        $sortBy = $request->get("sortBy", "created_at");
        $sortDirection = $request->get("sortDirection", "DESC");

        // Debug logging
        Log::info('=== SHUFFLE ADS REQUEST ===', [
            'community_id' => $communityId,
            'world_id' => $world_id,
            'user_id' => Auth::id()
        ]);

        $model = new Ad();
        $query = Ad::with('cube', 'cube.cube_type', 'ad_category');

        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query, ['ad_category.name']);
        }

        // Join dan filter dasar
        $query = $query->select(['ads.*'])
            ->join('cubes', 'cubes.id', 'ads.cube_id')
            ->where('ads.status', 'active')
            ->where('cubes.status', 'active')
            ->where('cubes.is_information', 0)
            // HAPUS filter ke kolom cubes.content_type karena kolom tsb tidak ada
            // Filter cukup via tipe ads agar tidak menampilkan voucher
            ->where('ads.type', '!=', 'voucher');

        // Jika ada community_id, ambil ads di komunitas tsb ATAU ads global (community_id = null)
        if ($communityId) {
            $query = $query->where(function ($q) use ($communityId) {
                $q->where('ads.community_id', $communityId)
                    ->orWhereNull('ads.community_id');
            });
        } else {
            // Jika tidak ada community_id, gunakan filter world seperti semula
            if ($world_id) {
                $query = $query->where('cubes.world_id', $world_id);
            } else {
                $user = Auth::user();

                if ($user && $user->worlds) {
                    $worldRegisteredId = $user->worlds->map(function ($item) {
                        return $item->world_id;
                    });

                    $query = $query->where(function ($q) use ($worldRegisteredId) {
                        $q->whereNull('cubes.world_id')
                            ->orWhereIn('cubes.world_id', $worldRegisteredId);
                    });
                } else {
                    $query = $query->whereNull('cubes.world_id');
                }
            }
        }

        $user = Auth::user();
        $query = $this->applyAudienceVisibilityFilter($query, $user);

        $query = $query->inRandomOrder()
            ->limit(20)
            ->get();

        // Debug logging hasil
        Log::info('=== SHUFFLE ADS RESULT ===', [
            'total_ads' => $query->count(),
            'ads_ids' => $query->pluck('id')->toArray(),
            'community_id' => $communityId,
            'voucher_filter_applied' => true,
            'note' => 'Voucher cubes/ads have been filtered out from shuffle ads'
        ]);

        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }

    /**
     * Get cubes by ad category for community category page
     */
    public function getCubesByCategory(Request $request)
    {
        try {
            // * Update status kubus yang kadaluarsa menjadi inactive
            // Cek berdasarkan inactive_at
            DB::table('cubes')
                ->whereNotNull('inactive_at')
                ->whereDate('inactive_at', '<=', Carbon::now())
                ->where('status', 'active')
                ->update(['status' => 'inactive']);

            // Cek berdasarkan ads finish_validate yang sudah lewat
            $expiredCubeIds = DB::table('ads')
                ->join('cubes', 'cubes.id', '=', 'ads.cube_id')
                ->whereNotNull('ads.finish_validate')
                ->whereDate('ads.finish_validate', '<', Carbon::now())
                ->where('cubes.status', 'active')
                ->pluck('cubes.id')
                ->toArray();

            if (!empty($expiredCubeIds)) {
                DB::table('cubes')
                    ->whereIn('id', $expiredCubeIds)
                    ->update(['status' => 'inactive']);
            }

            $adCategoryId = $request->get('ad_category_id');
            $communityId = $request->get('community_id');
            $limit = $request->get('limit', 50);

            // Debug logging
            Log::info('=== CUBES BY CATEGORY REQUEST ===', [
                'ad_category_id' => $adCategoryId,
                'community_id' => $communityId,
                'limit' => $limit,
                'user_id' => Auth::id()
            ]);

            if (!$adCategoryId) {
                return response([
                    'message' => 'ad_category_id is required',
                    'data' => []
                ], 400);
            }

            $query = Ad::with(['cube', 'cube.cube_type', 'ad_category'])
                ->where('ads.status', 'active')
                ->where('ads.ad_category_id', $adCategoryId)
                ->whereHas('cube', function ($q) {
                    $q->where('status', 'active');
                });

            // Filter by community if provided
            if ($communityId) {
                $query = $query->where(function ($q) use ($communityId) {
                    $q->where('ads.community_id', $communityId)
                        ->orWhereNull('ads.community_id');
                });

                // Also filter cubes by community
                $query = $query->whereHas('cube', function ($q) use ($communityId) {
                    $q->where(function ($subQ) use ($communityId) {
                        $subQ->where('community_id', $communityId)
                            ->orWhereNull('community_id');
                    });
                });
            } else {
                // Apply world filter if no community specified
                $user = Auth::user();
                if ($user && $user->worlds) {
                    $worldRegisteredId = $user->worlds->pluck('world_id')->toArray();

                    $query = $query->whereHas('cube', function ($q) use ($worldRegisteredId) {
                        $q->where(function ($subQ) use ($worldRegisteredId) {
                            $subQ->whereNull('world_id')
                                ->orWhereIn('world_id', $worldRegisteredId);
                        });
                    });
                } else {
                    $query = $query->whereHas('cube', function ($q) {
                        $q->whereNull('world_id');
                    });
                }
            }

            $user = Auth::user();
            $query = $this->applyAudienceVisibilityFilter($query, $user);

            $ads = $query->orderBy('ads.created_at', 'DESC')
                ->limit($limit)
                ->get();

            // Transform data to include both ad and cube information
            $transformedData = $ads->map(function ($ad) {
                return [
                    'id' => $ad->id,
                    'title' => $ad->title,
                    'description' => $ad->description,
                    'image' => $ad->image_1
                    ? url('storage/' . ltrim($ad->image_1, '/'))
                    : (
                    $ad->picture_source
                    ? url('storage/' . ltrim($ad->picture_source, '/'))
                    : null
                ),
                    'merchant' => $ad->cube->name ?? 'Merchant',
                    'ad' => $ad,
                    'cube' => $ad->cube,
                    'category' => $ad->ad_category
                ];
            });

            // Debug logging hasil
            Log::info('=== CUBES BY CATEGORY RESULT ===', [
                'total_cubes' => $transformedData->count(),
                'ad_category_id' => $adCategoryId,
                'community_id' => $communityId
            ]);

            return response([
                'message' => 'success',
                'data' => $transformedData
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getCubesByCategory: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response([
                'message' => 'Internal server error',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get public ad data for Open Graph meta tags (SSR)
     * Endpoint ini tidak memerlukan autentikasi
     */
    public function showPublic($id)
    {
        try {
            $ad = Ad::find($id);

            if (!$ad) {
                return response()->json([
                    'success' => false,
                    'message' => 'Iklan tidak ditemukan'
                ], 404);
            }

            // Kumpulin gambar asli TANPA memanipulasi dengan asset()
            $images = [];

            $pushImage = function ($img) use (&$images) {
                if (!$img) return;

                // Jika sudah URL absolut → pakai langsung
                if (filter_var($img, FILTER_VALIDATE_URL)) {
                    $images[] = $img;
                }
                // Jika path storage → ubah ke URL storage yang benar
                else {
                    $images[] = url('storage/' . ltrim($img, '/'));
                }
            };

            $pushImage($ad->picture_source);
            $pushImage($ad->image_1);
            $pushImage($ad->image_2);
            $pushImage($ad->image_3);

            // Fallback default jika tidak ada gambar
            if (empty($images)) {
                $images[] = url('/default-avatar.png');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $ad->id,
                    'title' => $ad->title,
                    'description' => $ad->description ?? '',
                    'merchant' => $ad->cube->name ?? 'HueHuy',
                    'images' => $images,
                    'image' => $images[0],
                    'community_id' => $ad->community_id,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching public ad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching ad'
            ], 500);
        }
    }
}
