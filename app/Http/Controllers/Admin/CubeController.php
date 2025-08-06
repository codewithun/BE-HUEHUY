<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AppConfigHelper;
use App\Helpers\StringHelper;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Cube;
use App\Models\CubeTag;
use App\Models\CubeType;
use App\Models\OpeningHour;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

            'cube_code' => 'cubes.code',
            'category_name' => 'ad_categories.name',
            'ads_title' => 'ads.title',
            'ads_huehuy_title' => 'ads.title',
        ];

        // ? Begin
        $model = new Cube();
        $query = Cube::with([
            'cube_type', 'user', 'corporate', 'world', 'opening_hours', 'tags',
            'ads' => function ($query) {
                return $query->select([
                        'ads.*', 
                        // DB::raw('SUM(summary_grabs.total_grab) AS total_grab'),
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
                    ->groupBy('ads.id')
                    ->get();
            },
            'ads.ad_category'
        ]);

        // ? When search
        if ($request->get("search") != "") {

            if ($searchBy == '') {
                $query = $this->search($request->get("search"), $model, $query);
                $query = $query->orWhereRaw("id IN (SELECT cube_id from ads where title LIKE '%". $request->get("search") ."%')");
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
        $query = $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
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
            'cube_type_id' => 'required|numeric',
            'parent_id' => 'nullable|numeric|exists:cubes,id',
            'user_id' => 'nullable|numeric|exists:users,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id' => 'nullable|numeric|exists:worlds,id',
            'color' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'map_lat' => 'nullable|numeric',
            'map_lng' => 'nullable|numeric',
            'is_recommendation' => 'nullable|boolean',
            'is_information' => 'nullable|boolean',
            'link_information' => 'nullable|string',
            'image' => 'nullable',

            'cube_tags.*' => 'nullable',
            'cube_tags.*.address' => 'nullable|string|max:255',
            'cube_tags.*.map_lat' => 'nullable|numeric',
            'cube_tags.*.map_lng' => 'nullable|numeric',
            'cube_tags.*.link' => 'nullable|string|max:255',

            'ads.*' => 'nullable',
            'ads.ad_category_id' => 'nullable|numeric|exists:ad_categories,id',
            'ads.title' => 'nullable|string|max:255',
            'ads.max_grab' => 'nullable|numeric',
            'ads.is_daily_grab' => 'nullable|boolean',
            'ads.status' => 'nullable|string',
            'ads.promo_type' => ['nullable', 'string', Rule::in(['offline', 'online'])],
            'ads.max_production_per_day' => 'nullable|numeric|min:0',
            'ads.sell_per_day' => 'nullable|numeric|min:0',
            'ads.level_umkm' => 'nullable|numeric|min:0',
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

        // ? Initial
        DB::beginTransaction();
        $model = new Cube();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

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

            foreach($request->cube_tags as $tag) {
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
            $ad->level_umkm = $request->ads['level_umkm'] ?? null;
            $ad->validation_time_limit = $request->ads['validation_time_limit'] ?? null;
            $ad->status = 'active';

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

            foreach($request->opening_hours as $data) {
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
        $model = Cube::findOrFail($id);
        $oldPicture = $model->picture_source;

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'nullable|numeric|exists:cube_types,id',
            'parent_id' => 'nullable|numeric|exists:cubes,id',
            'user_id' => 'nullable|numeric|exists:users,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id' => 'nullable|numeric|exists:worlds,id',
            'color' => 'nullable|string|max:255',
            'address' => 'required|string|max:255',
            'map_lat' => 'required|numeric',
            'map_lng' => 'required|numeric',
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'is_recommendation' => 'nullable|boolean',
            'is_information' => 'nullable|boolean',
            'image' => 'nullable',

            'cube_tags.*' => 'nullable',
            'cube_tags.*.address' => 'nullable|string|max:255',
            'cube_tags.*.map_lat' => 'nullable|numeric',
            'cube_tags.*.map_lng' => 'nullable|numeric',
            'cube_tags.*.link' => 'nullable|string|max:255',
        ]);

        if ($validation) return $validation;

        // * If status change from `active` to `inactive`
        if ($model->status == 'active' && $request->status != 'active') {

            $config = AppConfigHelper::getConfig('MAX_CUBE_ACTIVATION_EXPIRY');

            $model->inactive_at = Carbon::now();
            $model->expired_activate_date = Carbon::now()->addDays($config->value->configval);
        }

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'cube');

            if ($oldPicture) {
                $this->delete_file($oldPicture ?? '');
            }
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

            foreach($request->cube_tags as $tag) {
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

    // ============================================>
    // ## Update the specified status.
    // ============================================>
    public function updateStatus(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = Cube::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'inactive_at' => 'nullable|date_format:d-m-Y H:i:s',
        ]);

        if ($validation) return $validation;

        // ? Update data
        $model->status = $request->status;
        if ($request->inactive_at) {
            $model->inactive_at = Carbon::create($request->inactive_at)->format('Y-m-d H:i:s');
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: failed to update cube data",
            ], 500);
        }

        // ? Update Ads Status
        try {
            Ad::where('cube_id', $model->id)
                ->update([
                    'status' => $request->status
                ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: failed to update ads data",
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
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
            'world_id' => 'nullable|numeric|exists:worlds,id',
            'color' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'map_lat' => 'nullable|numeric',
            'map_lng' => 'nullable|numeric',
            'image' => 'nullable',
            'inactive_at' => 'nullable|date_format:d-m-Y H:i:s',
            'is_information' => 'nullable|boolean',
            'total' => 'required|numeric',

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
            'ads.level_umkm' => 'nullable|numeric|min:0',
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

        // ? Initial
        DB::beginTransaction();

        $cubeCreated = [];
        if ($request->total && $request->total > 0) {

            for ($i=0; $i<$request->total; $i++) {

                $model = new Cube();

                // ? Dump data
                $model = $this->dump_field($request->all(), $model);
        
                $model->code = $model->generateCubeCode($request->cube_type_id);
                $model->status = 'active';
                if ($model->status != 'active') {
        
                    $config = AppConfigHelper::getConfig('MAX_CUBE_ACTIVATION_EXPIRY');
        
                    $model->expired_activate_date = Carbon::now()->addDays($config->value->configval);
                }

                if ($request->inactive_at) {
                    $model->inactive_at = Carbon::create($request->inactive_at)->format('Y-m-d H:i:s');
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
        
                    foreach($request->cube_tags as $tag) {
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
                    $ad->level_umkm = $request->ads['level_umkm'] ?? null;
                    $ad->status = 'active';
        
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
        