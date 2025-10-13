<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\StringHelper;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdController extends Controller
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

        // ? Preparation
        $columnAliases = [
            'created_at' => 'ads.created_at'
        ];

        // ? Begin
        $model = new Ad();
        $query = Ad::with('cube', 'ad_category', 'voucher');

        // ? When search
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
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
        $query = $query->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
            ->groupBy('ads.id')
            ->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->select([
                ...$model->selectable,
                DB::raw('CAST(IF(ads.is_daily_grab = 1,
                    (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                    SUM(total_grab)
                ) AS SIGNED) AS total_grab'),
                DB::raw('CAST(IF(ads.is_daily_grab = 1,
                    ads.max_grab - (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                    ads.max_grab - SUM(total_grab)
                ) AS SIGNED) AS total_remaining'),
            ])->paginate($paginate);

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
            'cube_id' => 'required|numeric|exists:cubes,id',
            'ad_category_id' => 'required|numeric|exists:ad_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'max_grab' => 'nullable|numeric',
            'is_daily_grab' => 'nullable|boolean',
            'status' => 'nullable|string',
            'type' => ['required', Rule::in(['general', 'voucher', 'huehuy', 'iklan'])],
            'promo_type' => ['required', Rule::in(['offline', 'online'])],
            'max_production_per_day' => 'nullable|numeric|min:0',
            'sell_per_day' => 'nullable|numeric|min:0',
            'level_umkm' => 'nullable|numeric|min:0',
            'start_validate' => 'nullable|date_format:d-m-Y',
            'finish_validate' => 'nullable|date_format:d-m-Y',
            'validation_time_limit' => 'nullable|date_format:H:i:s',
            'image' => 'nullable',
            'ads.image_1' => 'nullable',
            'ads.image_2' => 'nullable',
            'ads.image_3' => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new Ad();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->slug = StringHelper::uniqueSlug($request->title);

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'ads');
        }

        // * Check for additional images (ads[image_1], ads[image_2], ads[image_3])
        if ($request->hasFile('ads.image_1')) {
            $model->image_1 = $this->upload_file($request->file('ads.image_1'), 'ads');
        }
        if ($request->hasFile('ads.image_2')) {
            $model->image_2 = $this->upload_file($request->file('ads.image_2'), 'ads');
        }
        if ($request->hasFile('ads.image_3')) {
            $model->image_3 = $this->upload_file($request->file('ads.image_3'), 'ads');
        }

        // Update image timestamp for cache busting
        if ($request->hasFile('image') || $request->hasFile('ads.image_1') || 
            $request->hasFile('ads.image_2') || $request->hasFile('ads.image_3')) {
            $model->image_updated_at = now();
        }

        // * If start or finish validate filled
        if ($request->start_validate) {
            $model->start_validate = Carbon::create($request->start_validate)->format('Y-m-d');
        }
        if ($request->finish_validate) {
            $model->finish_validate = Carbon::create($request->finish_validate)->format('Y-m-d');
        }
        if ($request->validation_time_limit) {
            $model->validation_time_limit = Carbon::create($request->validation_time_limit)->format('H:i:s');
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: failed to create ads",
                'data' => $th
            ], 500);
        }

        /**
         * * Create Voucher
         */
        if ($request->promo_type == 'online') {

            try {
                Voucher::create([
                    'ad_id' => $model->id,
                    'name' => $model->title,
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
        $model = Ad::findOrFail($id);
        $oldPicture = $model->picture_source;
        $oldImage1 = $model->image_1;
        $oldImage2 = $model->image_2;
        $oldImage3 = $model->image_3;

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_id' => 'required|numeric|exists:cubes,id',
            'ad_category_id' => 'required|numeric|exists:ad_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'max_grab' => 'nullable|numeric',
            'is_daily_grab' => 'nullable|boolean',
            'type' => ['required', Rule::in(['general', 'voucher', 'huehuy', 'iklan'])],
            'promo_type' => ['required', Rule::in(['offline', 'online'])],
            'max_production_per_day' => 'nullable|numeric|min:0',
            'sell_per_day' => 'nullable|numeric|min:0',
            'level_umkm' => 'nullable|numeric|min:0',
            'status' => 'nullable|string',
            'start_validate' => 'nullable|date_format:d-m-Y',
            'finish_validate' => 'nullable|date_format:d-m-Y',
            'validation_time_limit' => 'nullable|date_format:H:i:s',
            'image' => 'nullable',
            'ads.image_1' => 'nullable',
            'ads.image_2' => 'nullable',
            'ads.image_3' => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->slug = StringHelper::uniqueSlug($request->title);

        if ($request->start_validate) {
            $model->start_validate = Carbon::create($request->start_validate)->format('Y-m-d');
        }
        if ($request->finish_validate) {
            $model->finish_validate = Carbon::create($request->finish_validate)->format('Y-m-d');
        }
        if ($request->validation_time_limit) {
            $model->validation_time_limit = Carbon::create($request->validation_time_limit)->format('H:i:s');
        }

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'ads');

            if ($oldPicture) {
                $this->delete_file($oldPicture ?? '');
            }
        }

        // * Check for additional images (ads[image_1], ads[image_2], ads[image_3])
        if ($request->hasFile('ads.image_1')) {
            $model->image_1 = $this->upload_file($request->file('ads.image_1'), 'ads');
            if ($oldImage1) {
                $this->delete_file($oldImage1);
            }
        }
        if ($request->hasFile('ads.image_2')) {
            $model->image_2 = $this->upload_file($request->file('ads.image_2'), 'ads');
            if ($oldImage2) {
                $this->delete_file($oldImage2);
            }
        }
        if ($request->hasFile('ads.image_3')) {
            $model->image_3 = $this->upload_file($request->file('ads.image_3'), 'ads');
            if ($oldImage3) {
                $this->delete_file($oldImage3);
            }
        }

        // Update image timestamp for cache busting
        if ($request->hasFile('image') || $request->hasFile('ads.image_1') || 
            $request->hasFile('ads.image_2') || $request->hasFile('ads.image_3')) {
            $model->image_updated_at = now();
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                'data' => $th
            ], 500);
        }

        if ($request->promo_type == 'online') {

            $voucher = Voucher::where('ad_id', $model->id)
                ->first();

            if (!$voucher) {
                try {
                    $voucher = new Voucher();
                    $voucher->ad_id = $model->id;
                    $voucher->name = $model->title;
                    $voucher->code = $voucher->generateVoucherCode();

                    $voucher->save();
                } catch (\Throwable $th) {
                    DB::rollBack();
                    return response([
                        "message" => "Error: failed to create new voucher",
                        'data' => $th
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

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = Ad::findOrFail($id);

        // * Remove image
        if ($model->picture_source) {
            $this->delete_file($model->picture_source ?? '');
        }

        // * Remove additional images
        if ($model->image_1) {
            $this->delete_file($model->image_1);
        }
        if ($model->image_2) {
            $this->delete_file($model->image_2);
        }
        if ($model->image_3) {
            $this->delete_file($model->image_3);
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

    // GET /api/admin/ads/{id}
    public function show(string $id)
    {
        try {
            $ad = \App\Models\Ad::select([
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
                ->with(['cube', 'ad_category'])
                ->where('ads.id', $id)
                ->groupBy('ads.id')
                ->first();

            if (!$ad) {
                return response(['message' => 'not found'], 404);
            }

            return response(['message' => 'success', 'data' => $ad], 200);
        } catch (\Throwable $e) {
            Log::error('AdController@show failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response(['message' => 'Error: server side having problem!'], 500);
        }
    }
}
