<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicContent;
use App\Models\DynamicContentCube;
use App\Models\AdCategory;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class DynamicContentController extends Controller
{
    /**
     * Filter promo/voucher visibility inside widget ads.
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

    public function index(Request $request)
    {
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);
        $community_id = $request->get("community_id", null);
        $corporate_id = $request->get("corporate_id", null);

        $columnAliases = [];
        $model = new DynamicContent();
        $user = Auth::user();

        $query = DynamicContent::with([
            'dynamic_content_cubes',
            'dynamic_content_cubes.cube',
            'dynamic_content_cubes.cube.cube_type',
            'dynamic_content_cubes.cube.ads' => function ($query) use ($user, $community_id) {
                $query->where('status', 'active');

                $this->applyAdAudienceVisibilityFilter($query, $user);

                $query->when($community_id && $community_id !== '' && $community_id !== 'null', function ($scoped) use ($community_id) {
                    $scoped->where(function ($nested) use ($community_id) {
                        $nested->whereNull('ads.community_id')
                            ->orWhere('ads.community_id', $community_id);
                    });
                }, function ($global) {
                    $global->whereNull('ads.community_id');
                });
            },
            'dynamic_content_cubes.cube.ads.ad_category',
            'dynamic_content_cubes.cube.tags',
            'dynamic_content_cubes.cube.user',
            'dynamic_content_cubes.cube.corporate',
            'dynamic_content_cubes.cube.opening_hours',
            'ad_category',
            'community'
        ]);

        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
        }

        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                if ($column === 'corporate_id') continue;
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        if ($request->filled('type')) {
            $query->where('type', $request->get('type'));
        }

        if ($community_id && $community_id !== '' && $community_id !== 'null') {
            $query->where('community_id', $community_id);
        } else {
            $query->whereNull('community_id');
        }

        if ($corporate_id && $corporate_id !== '' && $corporate_id !== 'null') {
            $query->whereHas('community', function ($q) use ($corporate_id) {
                $q->where('corporate_id', $corporate_id);
            });
        }

        if ($paginate === 'all') {
            $widgets = $query->orderBy('level', 'asc')->get();
            $totalRow = $widgets->count();

            return response([
                "message" => $widgets->isEmpty() ? "empty data" : "success",
                "data" => $widgets,
                "total_row" => $totalRow,
            ]);
        }

        $paginated = $query->orderBy('level', 'asc')->paginate($paginate);

        return response([
            "message" => $paginated->isEmpty() ? "empty data" : "success",
            "data" => $paginated->items(),
            "total_row" => $paginated->total(),
        ]);
    }

    public function store(Request $request)
    {
        Log::info('=== DYNAMIC CONTENT STORE START ===');
        Log::info('Request data:', $request->all());
        Log::info('Source type: ' . $request->source_type);
        Log::info('Dynamic content cubes:', ['dynamic_content_cubes' => $request->dynamic_content_cubes]);
        Log::info('Community ID: ' . $request->community_id);

        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'type' => ['required', Rule::in(['home', 'hunting', 'information'])],
            'content_type' => ['required', Rule::in(['nearby', 'horizontal', 'vertical', 'category', 'category_box', 'ad_category', 'recommendation', 'promo'])],
            'source_type' => ['nullable', Rule::in(['cube', 'ad', 'shuffle_cube', 'promo_selected', 'ad_category', 'category_box'])],
            'size' => ['nullable', Rule::in(['S', 'M', 'L', 'XL', 'XL-Ads'])],
            'community_id' => 'nullable|numeric|exists:communities,id',
            'dynamic_content_cubes' => [Rule::requiredIf(function () use ($request) {
                return $request->source_type === 'cube' && $request->content_type === 'promo';
            })],
        ]);

        if ($validation) return $validation;

        DB::beginTransaction();
        $model = new DynamicContent();

        $query = DynamicContent::where('type', $request->type);

        if ($request->community_id) {
            $query->where('community_id', $request->community_id);
        } else {
            $query->whereNull('community_id');
        }

        $last_level = $query->orderBy('level', 'DESC')->first();

        $model = $this->dump_field($request->all(), $model);
        $model->level = ($last_level && $last_level->level) ? $last_level->level + 1 : 1;
        $model->is_active = 1;

        if ($request->content_type === 'category') {
            $model->source_type = 'ad_category';
        }

        if ($request->content_type === 'category_box') {
            $model->source_type = 'category_box';
        }

        try {
            $model->save();
            Log::info('Dynamic content saved successfully:', ['id' => $model->id]);
        } catch (\Throwable $th) {
            Log::error('Failed to save dynamic content:', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'data' => $model->toArray()
            ]);
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem! - " . $th->getMessage(),
            ], 500);
        }

        if ($request->source_type == 'cube' && $request->dynamic_content_cubes) {
            Log::info('Processing cubes...');
            Log::info('Raw cubes data', ['raw' => $request->dynamic_content_cubes]);
            Log::info('Cubes data type: ' . gettype($request->dynamic_content_cubes));

            $cubes = [];
            if (is_array($request->dynamic_content_cubes)) {
                $cubes = $request->dynamic_content_cubes;
            } elseif (is_string($request->dynamic_content_cubes)) {
                $cubes = explode(',', $request->dynamic_content_cubes);
            } else {
                $cubes = [$request->dynamic_content_cubes];
            }

            $cubes = array_filter(array_map('intval', $cubes), function ($cube) {
                return $cube > 0;
            });

            Log::info('Processed cubes:', $cubes);

            if (!empty($cubes)) {
                $prepareDynamicContentCubes = [];
                foreach ($cubes as $item) {
                    $prepareDynamicContentCubes[] = [
                        'dynamic_content_id' => $model->id,
                        'cube_id' => intval($item),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ];
                }

                try {
                    DynamicContentCube::insert($prepareDynamicContentCubes);
                    Log::info('Successfully inserted cubes:', $prepareDynamicContentCubes);
                } catch (\Throwable $th) {
                    Log::error('Failed to insert cubes:', [
                        'error' => $th->getMessage(),
                        'data' => $prepareDynamicContentCubes
                    ]);
                    DB::rollBack();
                    return response([
                        "message" => "Error: failed to insert new dynamic content cubes - " . $th->getMessage(),
                    ], 500);
                }
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        DB::beginTransaction();
        $model = DynamicContent::findOrFail($id);

        $validation = $this->validation($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'type' => ['nullable', Rule::in(['home', 'hunting', 'information'])],
            'content_type' => ['nullable', Rule::in(['nearby', 'horizontal', 'vertical', 'category', 'category_box', 'ad_category', 'recommendation', 'promo'])],
            'source_type' => ['nullable', Rule::in(['cube', 'ad', 'shuffle_cube', 'promo_selected', 'ad_category', 'category_box'])],
            'size' => ['nullable', Rule::in(['S', 'M', 'L', 'XL', 'XL-Ads'])],
            'level' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'community_id' => 'nullable|numeric|exists:communities,id',
            'dynamic_content_cubes' => [Rule::requiredIf($request->source_type == 'cube')],
        ]);

        if ($validation) return $validation;

        if ($request->level) {
            if ($request->level < $model->level) {
                $lowest_stages = DynamicContent::where('type', $model->type)
                    ->where('community_id', $model->community_id)
                    ->where('level', '<', $model->level)
                    ->where('level', '>=', $request->level)
                    ->get();

                foreach ($lowest_stages as $lowest_stage) {
                    $lowest_stage->update(['level' => $lowest_stage->level + 1]);
                }
            }

            if ($request->level > $model->level) {
                $lowest_stages = DynamicContent::where('type', $model->type)
                    ->where('community_id', $model->community_id)
                    ->where('level', '>', $model->level)
                    ->where('level', '<=', $request->level)
                    ->get();

                foreach ($lowest_stages as $lowest_stage) {
                    $lowest_stage->update(['level' => $lowest_stage->level - 1]);
                }
            }
        }

        $model = $this->dump_field($request->all(), $model);

        if ($request->content_type === 'category') {
            $model->source_type = 'ad_category';
        }

        if ($request->content_type === 'category_box') {
            $model->source_type = 'category_box';
        }

        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        if ($request->source_type == 'cube' && $request->dynamic_content_cubes) {
            $cubes = [];
            if (is_array($request->dynamic_content_cubes)) {
                $cubes = $request->dynamic_content_cubes;
            } elseif (is_string($request->dynamic_content_cubes)) {
                $cubes = explode(',', $request->dynamic_content_cubes);
            } else {
                $cubes = [$request->dynamic_content_cubes];
            }

            $cubes = array_filter(array_map('intval', $cubes), function ($cube) {
                return $cube > 0;
            });

            if (!empty($cubes)) {
                $prepareDynamicContentCubes = [];

                DynamicContentCube::where('dynamic_content_id', $model->id)->delete();

                foreach ($cubes as $item) {
                    $prepareDynamicContentCubes[] = [
                        'dynamic_content_id' => $model->id,
                        'cube_id' => intval($item),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ];
                }

                try {
                    DynamicContentCube::insert($prepareDynamicContentCubes);
                } catch (\Throwable $th) {
                    DB::rollBack();
                    return response([
                        "message" => "Error: failed to insert new dynamic content cubes - " . $th->getMessage(),
                    ], 500);
                }
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ]);
    }

    public function destroy(string $id)
    {
        $model = DynamicContent::findOrFail($id);

        try {
            $model->delete();
        } catch (\Throwable $th) {
            return response([
                "message" => "Error: server side having problem!"
            ], 500);
        }

        return response([
            "message" => "Success",
            "data" => $model
        ]);
    }
}
