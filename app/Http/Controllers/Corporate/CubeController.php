<?php

namespace App\Http\Controllers\Corporate;

use App\Helpers\AppConfigHelper;
use App\Helpers\StringHelper;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\CorporateUser;
use App\Models\Cube;
use App\Models\CubeTag;
use App\Models\CubeType;
use App\Models\OpeningHour;
use App\Models\Voucher;
use App\Models\World;
use App\Models\WorldAffiliate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CubeController extends Controller
{
    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function index(Request $request)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);
        $searchBy = $request->get('searchBy', '');

        // ? Preparation
        $columnAliases = [
            'created_at' => 'cubes.created_at',
            'corporate_id' => 'cubes.corporate_id',
            'world_id' => 'cubes.world_id',

            'cube_code' => 'cubes.code',
            'category_name' => 'ad_categories.name',
            'ads_title' => 'ads.title',
            'ads_huehuy_title' => 'ads.title',
        ];

        $credentialUserCorporate = Auth::user()->corporate_user;

        // ? Begin
        $model = new Cube();
        $query = Cube::with([
            'cube_type',
            'user',
            'corporate',
            'world',
            'opening_hours',
            'tags',
            'ads' => function ($query) {
                return $query->select([
                    'ads.*',
                    // DB::raw('SUM(summary_grabs.total_grab) AS total_grab'),
                    DB::raw('CAST(IF(ads.is_daily_grab = 1,
                            COALESCE((SELECT total_grab FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id LIMIT 1), 0),
                            COALESCE((SELECT COUNT(*) FROM promo_items pi 
                                      JOIN promos p ON p.id = pi.promo_id 
                                      WHERE p.code = ads.code 
                                      AND pi.status IN ("reserved", "redeemed")), 0)
                        ) AS SIGNED) AS total_grab'),
                    DB::raw('CAST(GREATEST(0, IF(ads.unlimited_grab = 1,
                            9999999,
                            IF(ads.is_daily_grab = 1,
                                ads.max_grab - COALESCE((SELECT total_grab FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id LIMIT 1), 0),
                                COALESCE((SELECT stock FROM promos WHERE code = ads.code LIMIT 1), ads.max_grab)
                            )
                        )) AS SIGNED) AS total_remaining'),
                ])
                    ->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
                    ->groupBy('ads.id')
                    ->get();
            },
            'ads.ad_category'
        ]);

        // ? When search
        if ($request->get("search") != "") {
            // $query = $this->search($request->get("search"), $model, $query);
            if ($searchBy == '') {
                $query = $this->search($request->get("search"), $model, $query);
            } else {

                if ($searchBy == 'ads_huehuy_title') {

                    $query = $query->leftJoin('ads', 'ads.cube_id', 'cubes.id')
                        ->where('ads.title', 'LIKE', "%" . $request->get('search') . "%")
                        ->where('ads.type', 'huehuy');
                } else {

                    $query = $query->leftJoin('ads', 'ads.cube_id', 'cubes.id')
                        ->leftJoin('ad_categories', 'ad_categories.id', 'ads.ad_category_id')
                        ->where($this->remark_column($searchBy, $columnAliases), "LIKE", "%" . $request->get('search') . "%");
                }
            }
        } else {
            $query = $query;
        }

        // ? When Filter
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        // ? Sort & executing with pagination
        $query = $query->leftJoin('world_affiliates', 'world_affiliates.id', 'cubes.world_affiliate_id')
            ->leftJoin('user_worlds', 'user_worlds.world_id', 'cubes.world_id')
            ->where(function ($q) use ($credentialUserCorporate) {
                return $q->where('cubes.corporate_id', $credentialUserCorporate->corporate_id)
                    ->orWhere('world_affiliates.corporate_id', $credentialUserCorporate->corporate_id)
                    ->orWhere('user_worlds.user_id', $credentialUserCorporate->user_id);
            })
            ->groupBy('cubes.id')
            ->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->select($model->selectable)->paginate($paginate);

        // ? When empty
        if (empty($query->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        // ? When success
        return response([
            "message" => "success",
            "data" => $query->all(),
            "total_row" => $query->total(),
        ]);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'nullable|numeric',
            'parent_id' => 'nullable|numeric|exists:cubes,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id' => 'nullable|numeric',
            'color' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'map_lat' => 'nullable|numeric',
            'map_lng' => 'nullable|numeric',
            'image' => 'nullable',
            'is_information' => 'nullable|boolean',

            'cube_tags.*' => 'nullable',
            'cube_tags.*.address' => 'nullable|string|max:255',
            'cube_tags.*.map_lat' => 'nullable|numeric',
            'cube_tags.*.map_lng' => 'nullable|numeric',
            'cube_tags.*.link' => 'nullable|string|max:255',

            'ads' => 'nullable',
            'ads.ad_category_id' => 'required|numeric|exists:ad_categories,id',
            'ads.title' => 'required|string|max:255',
            'ads.max_grab' => 'nullable|numeric',
            'ads.is_daily_grab' => 'nullable|boolean',
            'ads.status' => 'nullable|string',
            'ads.promo_type' => ['nullable', 'string', Rule::in(['offline', 'online'])],
            'ads.max_production_per_day' => 'nullable|numeric|min:0',
            'ads.sell_per_day' => 'nullable|numeric|min:0',
            // 'ads.level_umkm' => 'nullable|numeric|min:0',
            'ads.start_validate' => 'nullable|date_format:d-m-Y',
            'ads.finish_validate' => 'nullable|date_format:d-m-Y',
            'ads.validation_time_limit' => 'nullable|date_format:H:i:s',
            'ads.image' => 'nullable',

            'opening_hours.*' => 'nullable',
            'opening_hours.*.day' => ['required', 'string', Rule::in(['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'])],
            'opening_hours.*.open' => 'nullable|date_format:H:i',
            'opening_hours.*.close' => 'nullable|date_format:H:i',
            'opening_hours.*.is_24hour' => 'nullable|boolean',
            'opening_hours.*.is_closed' => 'nullable|boolean',
        ]);

        if ($validation) return $validation;

        // * Validate Cube Type
        $cubeType = CubeType::where('id', $request->cube_type_id)
            ->first();

        if (!$cubeType) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "cube_type_id" => [
                        "Tipe kubus tidak ditemukan"
                    ]
                ]
            ], 422);
        }

        // * Validate World
        $world = null;
        if ($request->world_id) {

            $world = World::where('id', $request->world_id)
                ->first();

            if (!$world) {
                return response([
                    "message" => "Error: Unprocessable Entity!",
                    "errors" => [
                        "world_id" => [
                            "Dunia tidak ditemukan"
                        ]
                    ]
                ], 422);
            }
        }

        $credentialUserCorporate = Auth::user()->corporate_user;

        // ? Initial
        DB::beginTransaction();
        $model = new Cube();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        if (!$request->world_id) {
            $model->corporate_id = $credentialUserCorporate->corporate_id;
        }
        $model->code = $model->generateCubeCode($request->cube_type_id);
        $model->status = 'active';
        if ($model->status != 'active') {

            $config = AppConfigHelper::getConfig('MAX_CUBE_ACTIVATION_EXPIRY');

            $model->expired_activate_date = Carbon::now()->addDays($config->value->configval);
        }

        // * If color not filled
        if (!$request->color) {
            $model->color = $cubeType->color;
        }

        // * If world is world affiliate
        if ($world) {

            $worldAffiliate = WorldAffiliate::where('world_id', $world->id)
                ->where('corporate_id', $credentialUserCorporate->corporate_id)
                ->first();

            if ($worldAffiliate) {
                $model->world_affiliate_id = $worldAffiliate->id;
            }
        }

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'cube');
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        // ? Process Cube Tags
        $preparedCubeTagsData = [];
        if ($request->cube_tags && count($request->cube_tags) > 0) {

            foreach ($request->cube_tags as $tag) {
                array_push($preparedCubeTagsData, [
                    'cube_id' => $model->id,
                    'address' => $tag['address'] ?? null,
                    'map_lat' => $tag['map_lat'] ?? null,
                    'map_lng' => $tag['map_lng'] ?? null,
                    'link' => $tag['link'] ?? null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            try {
                CubeTag::insert($preparedCubeTagsData);
            } catch (\Throwable $th) {
                DB::rollback();
                return response([
                    'message' => "Error: failed to insert new cube tags",
                ], 500);
            }
        }

        // ? Process Ads
        if ($request->ads) {

            $ad = new Ad();
            $ad->cube_id = $model->id;
            $ad->ad_category_id = $request->ads['ad_category_id'] ?? null;
            $ad->title = $request->ads['title'];
            $ad->slug = StringHelper::uniqueSlug($request->ads['title']);
            $ad->description = $request->ads['description'] ?? null;
            $ad->max_grab = $request->ads['max_grab'] ?? null;
            $ad->is_daily_grab = $request->ads['is_daily_grab'] ?? null;
            $ad->promo_type = $request->ads['promo_type'] ?? null;
            $ad->max_production_per_day = $request->ads['max_production_per_day'] ?? null;
            $ad->sell_per_day = $request->ads['sell_per_day'] ?? null;
            $ad->status = 'active';

            // * If start or finish validate filled
            if (isset($request->ads['start_validate'])) {
                $ad->start_validate = Carbon::create($request->ads['start_validate'])->format('Y-m-d');
            }
            if (isset($request->ads['finish_validate'])) {
                $ad->finish_validate = Carbon::create($request->ads['finish_validate'])->format('Y-m-d');
            }
            if (isset($request->ads['validation_time_limit'])) {
                $ad->validation_time_limit = Carbon::create($request->ads['validation_time_limit'])->format('H:i:s');
            }

            // * Check if has upload file
            if ($request->hasFile('ads.image')) {
                $ad->picture_source = $this->upload_file($request->file('ads.image'), 'ads');
            }

            try {
                $ad->save();
            } catch (\Throwable $th) {
                DB::rollback();
                return response([
                    'message' => "Error: failed to insert new ads",
                ], 500);
            }

            // ? Process Voucher
            if ($ad->promo_type == 'online') {

                try {
                    Voucher::create([
                        'ad_id' => $ad->id,
                        'name' => $ad->title,
                        'code' => (new Voucher())->generateVoucherCode(),
                    ]);
                } catch (\Throwable $th) {
                    DB::rollBack();
                    return response([
                        "message" => "Error: failed to create new voucher",
                        'data' => $th
                    ], 500);
                }
            }
        }

        // ? Process Opening Hour
        $preparedOpeningHourData = [];
        if ($request->opening_hours && count($request->opening_hours) > 0) {

            foreach ($request->opening_hours as $data) {
                array_push($preparedOpeningHourData, [
                    'cube_id' => $model->id,
                    'day' => $data['day'],
                    'open' => $data['open'] ?? null,
                    'close' => $data['close'] ?? null,
                    'is_24hour' => $data['is_24hour'] ?? null,
                    'is_closed' => $data['is_closed'] ?? null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            try {
                OpeningHour::insert($preparedOpeningHourData);
            } catch (\Throwable $th) {
                DB::rollBack();
                return response([
                    "message" => "Error: failed to insert new opening hours",
                ], 500);
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = Cube::with('world')->findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'nullable|numeric|exists:cube_types,id',
            'parent_id' => 'nullable|numeric|exists:cubes,id',
            'user_id' => 'nullable|numeric|exists:users,id',
            'world_id' => 'nullable|numeric|exists:worlds,id',
            'color' => 'nullable|string|max:255',
            'address' => 'required|string|max:255',
            'map_lat' => 'required|numeric',
            'map_lng' => 'required|numeric',
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'image' => 'nullable',
            'is_information' => 'nullable|boolean',

            'cube_tags.*' => 'nullable',
            'cube_tags.*.address' => 'nullable|string|max:255',
            'cube_tags.*.map_lat' => 'nullable|numeric',
            'cube_tags.*.map_lng' => 'nullable|numeric',
            'cube_tags.*.link' => 'nullable|string|max:255',
        ]);

        if ($validation) return $validation;

        $credentialUserCorporate = Auth::user()->corporate_user;
        if ($model->world->corporate_id == $credentialUserCorporate->corporate_id) {

            if (!in_array($credentialUserCorporate->role_id, [3, 4])) { // static for `Kepala Mitra` & `Manager Mitra`

                return response([
                    'message' => 'Anda tidak bisa mengubah data pada kubus ini'
                ], 403);
            }
        } else if ($model->world->corporate_id != $credentialUserCorporate->corporate_id) {

            return response([
                'message' => 'Anda tidak bisa mengubah data pada kubus ini'
            ], 403);
        } else if ($credentialUserCorporate->corporate_id != $model->corporate_id) {

            return response([
                'message' => 'Anda tidak bisa mengubah data pada kubus ini'
            ], 403);
        }

        // * If status change from `active` to `inactive`
        if ($model->status == 'active' && $request->status != 'active') {

            $config = AppConfigHelper::getConfig('MAX_CUBE_ACTIVATION_EXPIRY');

            $model->inactive_at = Carbon::now();
            $model->expired_activate_date = Carbon::now()->addDays($config->value->configval);
        }

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        if (!$request->world_id) {
            $model->corporate_id = $credentialUserCorporate->corporate_id;
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        // ? Process Cube Tags
        $preparedCubeTagsData = [];
        if ($request->cube_tags && count($request->cube_tags) > 0) {

            try {
                CubeTag::where('cube_id', $model->id)->delete();
            } catch (\Throwable $th) {
                DB::rollback();
                return response([
                    'message' => "Error: failed to delete tags",
                ], 500);
            }

            foreach ($request->cube_tags as $tag) {
                array_push($preparedCubeTagsData, [
                    'cube_id' => $model->id,
                    'address' => $tag['address'] ?? null,
                    'map_lat' => $tag['map_lat'] ?? null,
                    'map_lng' => $tag['map_lng'] ?? null,
                    'link' => $tag['link'] ?? null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            try {
                CubeTag::insert($preparedCubeTagsData);
            } catch (\Throwable $th) {
                DB::rollback();
                return response([
                    'message' => "Error: failed to insert new cube tags",
                ], 500);
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ]);
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = Cube::findOrFail($id);

        // * Remove image
        if ($model->picture_source) {
            $this->delete_file($model->picture_source ?? '');
        }

        // ? Executing
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

    // =============================================>
    // ## Store a newly created blank resource to others.
    // =============================================>
    public function createGiftCube(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'required|numeric',
            'parent_id' => 'nullable|numeric|exists:cubes,id',
            'user_id' => 'nullable|numeric|exists:users,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id' => 'nullable|numeric',
            'color' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'map_lat' => 'nullable|numeric',
            'map_lng' => 'nullable|numeric',
            'image' => 'nullable',
            'inactive_at' => 'nullable|date_format:d-m-Y H:i:s',
            'total' => 'required|numeric',
            'is_information' => 'nullable|boolean',

            'cube_tags.*' => 'nullable',
            'cube_tags.*.address' => 'nullable|string|max:255',
            'cube_tags.*.map_lat' => 'nullable|numeric',
            'cube_tags.*.map_lng' => 'nullable|numeric',

            'ads.*' => 'nullable',
            'ads.ad_category_id' => 'nullable|numeric|exists:ad_categories,id',
            'ads.title' => 'nullable|string|max:255',
            'ads.max_grab' => 'nullable|numeric',
            'ads.is_daily_grab' => 'nullable|boolean',
            'ads.status' => 'nullable|string',
            'ads.promo_type' => ['nullable', 'string', Rule::in(['offline', 'online'])],
            'ads.max_production_per_day' => 'nullable|numeric|min:0',
            'ads.sell_per_day' => 'nullable|numeric|min:0',
            // 'level_umkm' => 'nullable|numeric|min:0',
            'ads.start_validate' => 'nullable|date_format:d-m-Y',
            'ads.finish_validate' => 'nullable|date_format:d-m-Y',
            'ads.image' => 'nullable',
        ]);

        if ($validation) return $validation;

        // * Validate Cube Type
        $cubeType = CubeType::where('id', $request->cube_type_id)
            ->first();

        if (!$cubeType) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "cube_type_id" => [
                        "Tipe kubus tidak ditemukan"
                    ]
                ]
            ], 422);
        }

        // * Validate World
        $world = null;
        if ($request->world_id) {

            $world = World::where('id', $request->world_id)
                ->first();

            if (!$world) {
                return response([
                    "message" => "Error: Unprocessable Entity!",
                    "errors" => [
                        "world_id" => [
                            "Dunia tidak ditemukan"
                        ]
                    ]
                ], 422);
            }
        }

        $credentialUserCorporate = Auth::user()->corporate_user;

        // * Limit the creators must be an `Kepala Mitra` or `Manajer Mitra`
        $allowedRoleCreator = [3, 4]; // static for `Kepala Mitra` and `Manajer Mitra`
        if (!in_array($credentialUserCorporate->role_id, $allowedRoleCreator)) {
            return response([
                'message' => 'Kamu tidak mempunyai akses untuk memberikan kubus!'
            ], 403);
        }

        // ? Initial
        DB::beginTransaction();

        $cubeCreated = [];
        if ($request->total && $request->total > 0) {

            for ($i = 0; $i < $request->total; $i++) {

                $model = new Cube();

                // ? Dump data
                $model = $this->dump_field($request->all(), $model);

                $model->code = $model->generateCubeCode($request->cube_type_id);
                $model->status = 'active';
                if ($model->status != 'active') {

                    $config = AppConfigHelper::getConfig('MAX_CUBE_ACTIVATION_EXPIRY');

                    $model->expired_activate_date = Carbon::now()->addDays($config->value->configval);
                }

                if (!$request->world_id && $request->user_id) {

                    // * Check user member
                    $userMember = CorporateUser::where('corporate_id', $credentialUserCorporate->corporate_id)
                        ->where('user_id', $request->user_id)
                        ->first();

                    if (!$userMember) {
                        return response([
                            "message" => "Error: Unprocessable Entity!",
                            "errors" => [
                                "user_id" => [
                                    "User pemilik kubus bukan anggota dari mitra"
                                ]
                            ]
                        ], 422);
                    }

                    $model->user_id = $request->user_id;
                } else if (!$request->world_id) {
                    $model->corporate_id = $credentialUserCorporate->corporate_id;
                }

                if ($request->inactive_at) {
                    $model->inactive_at = Carbon::create($request->inactive_at)->format('Y-m-d H:i:s');
                }

                // * If world is world affiliate
                if ($world) {

                    $worldAffiliate = WorldAffiliate::where('world_id', $world->id)
                        ->where('corporate_id', $credentialUserCorporate->corporate_id)
                        ->first();

                    if ($worldAffiliate) {
                        $model->world_affiliate_id = $worldAffiliate->id;
                    }
                }

                // * If color not filled
                if (!$request->color) {
                    $model->color = $cubeType->color;
                }

                // * Check if has upload file
                if ($request->hasFile('image')) {
                    $model->picture_source = $this->upload_file($request->file('image'), 'cube');
                }

                // ? Executing
                try {
                    $model->save();
                } catch (\Throwable $th) {
                    DB::rollBack();
                    return response([
                        "message" => "Error: server side having problem!",
                    ], 500);
                }

                array_push($cubeCreated, $model);

                // ? Process Cube Tags
                $preparedCubeTagsData = [];
                if ($request->cube_tags && count($request->cube_tags) > 0) {

                    foreach ($request->cube_tags as $tag) {
                        array_push($preparedCubeTagsData, [
                            'cube_id' => $model->id,
                            'address' => $tag['address'],
                            'map_lat' => $tag['map_lat'],
                            'map_lng' => $tag['map_lng'],
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }

                    try {
                        CubeTag::insert($preparedCubeTagsData);
                    } catch (\Throwable $th) {
                        DB::rollback();
                        return response([
                            'message' => "Error: failed to insert new cube tags",
                        ], 500);
                    }
                }

                // ? Process Ads
                if ($request->ads) {

                    $ad = new Ad();
                    $ad->cube_id = $model->id;
                    $ad->ad_category_id = $request->ads['ad_category_id'];
                    $ad->title = $request->ads['title'];
                    $ad->slug = StringHelper::uniqueSlug($request->ads['title']);
                    $ad->description = $request->ads['description'] ?? null;
                    $ad->max_grab = $request->ads['max_grab'] ?? null;
                    $ad->is_daily_grab = $request->ads['is_daily_grab'];
                    $ad->promo_type = $request->ads['promo_type'];
                    $ad->max_production_per_day = $request->ads['max_production_per_day'] ?? null;
                    $ad->sell_per_day = $request->ads['sell_per_day'] ?? null;
                    $ad->status = 'active';

                    // * If start or finish validate filled
                    if ($request->ads['start_validate']) {
                        $ad->start_validate = Carbon::create($request->ads['start_validate'])->format('Y-m-d');
                    }
                    if ($request->ads['finish_validate']) {
                        $ad->finish_validate = Carbon::create($request->ads['finish_validate'])->format('Y-m-d');
                    }

                    // * Check if has upload file
                    if ($request->hasFile('ads.image')) {
                        $ad->picture_source = $this->upload_file($request->file('ads.image'), 'ads');
                    }

                    try {
                        $ad->save();
                    } catch (\Throwable $th) {
                        DB::rollback();
                        return response([
                            'message' => "Error: failed to insert new ads",
                        ], 500);
                    }
                }
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $cubeCreated
        ], 201);
    }
}
