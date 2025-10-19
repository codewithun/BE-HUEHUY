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
    /**
     * Normalize various date string formats to 'Y-m-d'.
     * Supports:
     * - d-m-Y (e.g., 17-10-2025)
     * - Y-m-d (e.g., 2025-10-17)
     * - ISO strings (e.g., 2025-10-17T00:00:00.000000Z)
     * Returns null if cannot be parsed.
     */
    private function toYmdDate($value): ?string
    {
        try {
            if (!$value) return null;
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->format('Y-m-d');
            }

            $str = is_string($value) ? trim($value) : (string) $value;
            if ($str === '') return null;

            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $str)) {
                $dt = Carbon::createFromFormat('d-m-Y', $str);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                $dt = Carbon::createFromFormat('Y-m-d', $str);
            } else {
                $dt = Carbon::parse($str);
            }

            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            Log::warning('AdController date normalization failed', [
                'raw' => $value,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

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
        // Normalize empty date strings to null to pass 'nullable|date' rule
        if ($request->has('start_validate') && $request->input('start_validate') === '') {
            $request->merge(['start_validate' => null]);
        }
        if ($request->has('finish_validate') && $request->input('finish_validate') === '') {
            $request->merge(['finish_validate' => null]);
        }

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_id' => 'required|numeric|exists:cubes,id',
            'ad_category_id' => 'required|numeric|exists:ad_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'max_grab' => 'nullable|numeric',
            'unlimited_grab' => 'nullable',
            'is_daily_grab' => 'nullable',
            'status' => 'nullable|string',
            'type' => ['required', Rule::in(['general', 'voucher', 'huehuy', 'iklan'])],
            'promo_type' => ['required', Rule::in(['offline', 'online'])],
            'validation_type' => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'code' => [
                'nullable',
                'string',
                'max:255',
                'unique:ads,code',
                'required_if:validation_type,manual'
            ],
            'target_type' => ['nullable', 'string', Rule::in(['all', 'user', 'community'])],
            'target_user_ids' => 'nullable|array',
            'target_user_ids.*' => 'numeric|exists:users,id',
            'community_id' => 'nullable|numeric|exists:communities,id',
            'max_production_per_day' => 'nullable|numeric|min:0',
            'sell_per_day' => 'nullable|numeric|min:0',
            'level_umkm' => 'nullable|numeric|min:0',
            'start_validate' => 'nullable|date',
            'finish_validate' => 'nullable|date|after_or_equal:start_validate',
            'validation_time_limit' => 'nullable|regex:/^\d{1,2}:\d{2}(:\d{2})?$/',
            'jam_mulai' => 'nullable|regex:/^\d{1,2}:\d{2}(:\d{2})?$/',
            'jam_berakhir' => 'nullable|regex:/^\d{1,2}:\d{2}(:\d{2})?$/',
            'day_type' => ['nullable', 'string', Rule::in(['weekend', 'weekday', 'custom'])],
            'custom_days' => 'nullable|array',
            'image' => 'nullable',
            'ads.image_1' => 'nullable',
            'ads.image_2' => 'nullable',
            'ads.image_3' => 'nullable',
            'ads[image_1]' => 'nullable',
            'ads[image_2]' => 'nullable',
            'ads[image_3]' => 'nullable',
            'ads_image_1' => 'nullable',
            'ads_image_2' => 'nullable',
            'ads_image_3' => 'nullable',
            'image_1' => 'nullable',
            'image_2' => 'nullable',
            'image_3' => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new Ad();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->slug = StringHelper::uniqueSlug($request->title);

        // Handle schedule fields with better boolean processing
        $unlimited_grab = $request->input('unlimited_grab');
        $model->unlimited_grab = in_array($unlimited_grab, [1, '1', true, 'true', 'on'], true);

        $is_daily_grab = $request->input('is_daily_grab');
        $model->is_daily_grab = in_array($is_daily_grab, [1, '1', true, 'true', 'on'], true);

        // Handle time fields - ensure proper format conversion
        $jam_mulai = $request->input('jam_mulai');
        if ($jam_mulai && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam_mulai)) {
            $jam_mulai = strlen($jam_mulai) == 5 ? $jam_mulai . ':00' : $jam_mulai;
        }
        $model->jam_mulai = $jam_mulai;

        $jam_berakhir = $request->input('jam_berakhir');
        if ($jam_berakhir && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam_berakhir)) {
            $jam_berakhir = strlen($jam_berakhir) == 5 ? $jam_berakhir . ':00' : $jam_berakhir;
        }
        $model->jam_berakhir = $jam_berakhir;

        $model->day_type = $request->input('day_type', 'custom');

        // Handle custom days
        $customDays = $request->input('custom_days', []);
        if (is_array($customDays) && !empty($customDays)) {
            $model->custom_days = $customDays;
        } else {
            $model->custom_days = null;
        }

        // Handle validation fields
        $model->validation_type = $request->input('validation_type', 'auto');
        $model->code = $request->input('code');
        $model->target_type = $request->input('target_type', 'all');
        $model->community_id = $request->input('community_id');

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'ads');
        }

        // * Check for additional images (support multiple formats: ads[image_1], ads.image_1, ads_image_1, image_1)
        if ($request->hasFile('ads[image_1]') || $request->hasFile('ads.image_1') || $request->hasFile('ads_image_1') || $request->hasFile('image_1')) {
            $file = null;
            if ($request->hasFile('ads[image_1]')) {
                $file = $request->file('ads[image_1]');
            } elseif ($request->hasFile('ads.image_1')) {
                $file = $request->file('ads.image_1');
            } elseif ($request->hasFile('ads_image_1')) {
                $file = $request->file('ads_image_1');
            } elseif ($request->hasFile('image_1')) {
                $file = $request->file('image_1');
            }

            if ($file) {
                $model->image_1 = $this->upload_file($file, 'ads');
            }
        }

        if ($request->hasFile('ads[image_2]') || $request->hasFile('ads.image_2') || $request->hasFile('ads_image_2') || $request->hasFile('image_2')) {
            $file = null;
            if ($request->hasFile('ads[image_2]')) {
                $file = $request->file('ads[image_2]');
            } elseif ($request->hasFile('ads.image_2')) {
                $file = $request->file('ads.image_2');
            } elseif ($request->hasFile('ads_image_2')) {
                $file = $request->file('ads_image_2');
            } elseif ($request->hasFile('image_2')) {
                $file = $request->file('image_2');
            }

            if ($file) {
                $model->image_2 = $this->upload_file($file, 'ads');
            }
        }

        if ($request->hasFile('ads[image_3]') || $request->hasFile('ads.image_3') || $request->hasFile('ads_image_3') || $request->hasFile('image_3')) {
            $file = null;
            if ($request->hasFile('ads[image_3]')) {
                $file = $request->file('ads[image_3]');
            } elseif ($request->hasFile('ads.image_3')) {
                $file = $request->file('ads.image_3');
            } elseif ($request->hasFile('ads_image_3')) {
                $file = $request->file('ads_image_3');
            } elseif ($request->hasFile('image_3')) {
                $file = $request->file('image_3');
            }

            if ($file) {
                $model->image_3 = $this->upload_file($file, 'ads');
            }
        }

        // Update image timestamp for cache busting
        if (
            $request->hasFile('image') ||
            $request->hasFile('ads[image_1]') || $request->hasFile('ads.image_1') || $request->hasFile('ads_image_1') || $request->hasFile('image_1') ||
            $request->hasFile('ads[image_2]') || $request->hasFile('ads.image_2') || $request->hasFile('ads_image_2') || $request->hasFile('image_2') ||
            $request->hasFile('ads[image_3]') || $request->hasFile('ads.image_3') || $request->hasFile('ads_image_3') || $request->hasFile('image_3')
        ) {
            $model->image_updated_at = now();
        }

        // * If start or finish validate filled
        if ($request->start_validate) {
            $normalized = $this->toYmdDate($request->start_validate);
            if ($normalized) {
                $model->start_validate = $normalized;
            }
        }
        if ($request->finish_validate) {
            $normalized = $this->toYmdDate($request->finish_validate);
            if ($normalized) {
                $model->finish_validate = $normalized;
            }
        }

        // Handle validation_time_limit with proper format conversion
        $validation_time_limit = $request->input('validation_time_limit');
        if ($validation_time_limit && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $validation_time_limit)) {
            $validation_time_limit = strlen($validation_time_limit) == 5 ? $validation_time_limit . ':00' : $validation_time_limit;
        }
        $model->validation_time_limit = $validation_time_limit;

        // Log data untuk debugging
        Log::info('AdController@store processing data', [
            'unlimited_grab' => $model->unlimited_grab,
            'jam_mulai' => $model->jam_mulai,
            'jam_berakhir' => $model->jam_berakhir,
            'day_type' => $model->day_type,
            'custom_days' => $model->custom_days,
            'validation_type' => $model->validation_type,
            'code' => $model->code
        ]);

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

        // Process target users untuk voucher dengan target user tertentu
        if ($model->type === 'voucher' && $model->target_type === 'user' && $request->has('target_user_ids')) {
            try {
                // Sync target users menggunakan tabel pivot
                $model->target_users()->sync($request->input('target_user_ids', []));
            } catch (\Throwable $th) {
                Log::error('AdController@store sync target users failed', [
                    'ad_id' => $model->id,
                    'error' => $th->getMessage()
                ]);
                // Don't rollback for this, just log the error
            }
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
        // Log incoming request for debugging
        Log::info('AdController@update received request', [
            'ad_id' => $id,
            'method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'all_data' => $request->all(),
            'files' => array_keys($request->allFiles()),
            'is_daily_grab_value' => $request->input('is_daily_grab'),
            'jam_berakhir_value' => $request->input('jam_berakhir'),
            'unlimited_grab_value' => $request->input('unlimited_grab'),
        ]);

        // Convert empty date strings to null so 'nullable|date' passes
        if ($request->has('start_validate') && $request->input('start_validate') === '') {
            $request->merge(['start_validate' => null]);
        }
        if ($request->has('finish_validate') && $request->input('finish_validate') === '') {
            $request->merge(['finish_validate' => null]);
        }

        // ? Initial
        DB::beginTransaction();
        $model = Ad::findOrFail($id);
        $oldPicture = $model->picture_source;
        $oldImage1 = $model->image_1;
        $oldImage2 = $model->image_2;
        $oldImage3 = $model->image_3;

        // ? Validate request - Make most fields nullable for update
        $validation = $this->validation($request->all(), [
            'cube_id' => 'nullable|numeric|exists:cubes,id',
            'ad_category_id' => 'nullable|numeric|exists:ad_categories,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'max_grab' => 'nullable|numeric',
            'unlimited_grab' => 'nullable',
            'is_daily_grab' => 'nullable',
            'type' => ['nullable', Rule::in(['general', 'voucher', 'huehuy', 'iklan'])],
            'promo_type' => ['nullable', Rule::in(['offline', 'online'])],
            'validation_type' => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'code' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request, $id) {
                    // Validasi unique untuk update (ignore current ad id)
                    if ($value) {
                        $exists = \App\Models\Ad::where('code', $value)
                            ->where('id', '!=', $id)
                            ->exists();

                        if ($exists) {
                            $fail('Kode ini sudah digunakan oleh ads lain.');
                        }
                    }
                },
                'required_if:validation_type,manual'
            ],
            'target_type' => ['nullable', 'string', Rule::in(['all', 'user', 'community'])],
            'target_user_ids' => 'nullable|array',
            'target_user_ids.*' => 'numeric|exists:users,id',
            'community_id' => 'nullable|numeric|exists:communities,id',
            'max_production_per_day' => 'nullable|numeric|min:0',
            'sell_per_day' => 'nullable|numeric|min:0',
            'level_umkm' => 'nullable|numeric|min:0',
            'status' => 'nullable|string',
            'start_validate' => 'nullable|date',
            'finish_validate' => 'nullable|date|after_or_equal:start_validate',
            'validation_time_limit' => 'nullable|regex:/^\d{1,2}:\d{2}(:\d{2})?$/',
            'jam_mulai' => 'nullable|regex:/^\d{1,2}:\d{2}(:\d{2})?$/',
            'jam_berakhir' => 'nullable|regex:/^\d{1,2}:\d{2}(:\d{2})?$/',
            'day_type' => ['nullable', 'string', Rule::in(['weekend', 'weekday', 'custom'])],
            'custom_days' => 'nullable|array',
            'image' => 'nullable',
            'ads.image_1' => 'nullable',
            'ads.image_2' => 'nullable',
            'ads.image_3' => 'nullable',
            'ads[image_1]' => 'nullable',
            'ads[image_2]' => 'nullable',
            'ads[image_3]' => 'nullable',
            'ads_image_1' => 'nullable',
            'ads_image_2' => 'nullable',
            'ads_image_3' => 'nullable',
            'image_1' => 'nullable',
            'image_2' => 'nullable',
            'image_3' => 'nullable',
        ]);

        if ($validation) {
            Log::error('AdController@update validation failed', [
                'ad_id' => $id,
                'validation_errors' => $validation->getData(),
                'request_data' => $request->all()
            ]);
            return $validation;
        }

        // Log request data untuk debugging
        Log::info('AdController@update received data', [
            'ad_id' => $id,
            'all_request' => $request->all(),
            'files' => array_keys($request->allFiles()),
            'has_ads_image_1_bracket' => $request->hasFile('ads[image_1]'),
            'has_ads_image_1_dot' => $request->hasFile('ads.image_1'),
            'existing_data' => [
                'picture_source' => $model->picture_source,
                'image_1' => $model->image_1,
                'image_2' => $model->image_2,
                'image_3' => $model->image_3,
            ]
        ]);

        // ? Manual field assignment instead of dump_field for better control
        if ($request->has('title')) {
            $newTitle = $request->input('title');
            // Only update slug if the title actually changed to avoid regenerating on no-op updates
            $titleChanged = $model->getOriginal('title') !== $newTitle;
            $model->title = $newTitle;
            if ($titleChanged) {
                $model->slug = StringHelper::uniqueSlug($newTitle);
            }
        }
        if ($request->has('description')) {
            $model->description = $request->input('description');
        }
        if ($request->has('ad_category_id')) {
            $adCategoryId = $request->input('ad_category_id');
            // Handle empty string or null
            $model->ad_category_id = ($adCategoryId === '' || $adCategoryId === 'null') ? null : $adCategoryId;
        }
        if ($request->has('promo_type')) {
            $model->promo_type = $request->input('promo_type');
        }
        if ($request->has('validation_type')) {
            $model->validation_type = $request->input('validation_type', 'auto');
        }
        if ($request->has('code')) {
            $model->code = $request->input('code');
        }
        if ($request->has('target_type')) {
            $model->target_type = $request->input('target_type', 'all');
        }
        if ($request->has('community_id')) {
            $model->community_id = $request->input('community_id');
        }
        if ($request->has('max_grab')) {
            $model->max_grab = $request->input('max_grab');
        }
        if ($request->has('status')) {
            $model->status = $request->input('status');
        }

        // Handle schedule fields with better boolean processing  
        if ($request->has('unlimited_grab')) {
            $unlimited_grab = $request->input('unlimited_grab');
            $model->unlimited_grab = in_array($unlimited_grab, [1, '1', true, 'true', 'on'], true);
        }

        if ($request->has('is_daily_grab')) {
            $is_daily_grab = $request->input('is_daily_grab');
            $model->is_daily_grab = in_array($is_daily_grab, [1, '1', true, 'true', 'on'], true);
        }

        // Handle time fields - ensure proper format conversion
        if ($request->has('jam_mulai')) {
            $jam_mulai = $request->input('jam_mulai');
            if ($jam_mulai && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam_mulai)) {
                $jam_mulai = strlen($jam_mulai) == 5 ? $jam_mulai . ':00' : $jam_mulai;
            }
            $model->jam_mulai = $jam_mulai;
        }

        if ($request->has('jam_berakhir')) {
            $jam_berakhir = $request->input('jam_berakhir');
            if ($jam_berakhir && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam_berakhir)) {
                $jam_berakhir = strlen($jam_berakhir) == 5 ? $jam_berakhir . ':00' : $jam_berakhir;
            }
            $model->jam_berakhir = $jam_berakhir;
        }

        if ($request->has('day_type')) {
            $model->day_type = $request->input('day_type', 'custom');
        }

        // Handle custom days - support both empty array and object
        if ($request->has('custom_days')) {
            $customDays = $request->input('custom_days', []);
            if (is_array($customDays)) {
                // Convert empty array to empty object for JSON storage
                $model->custom_days = empty($customDays) ? (object)[] : $customDays;
            } else {
                $model->custom_days = null;
            }
        }

        // Handle validation fields
        if ($request->has('validation_type')) {
            $model->validation_type = $request->input('validation_type', 'auto');
        }
        if ($request->has('code')) {
            $model->code = $request->input('code');
        }
        if ($request->has('target_type')) {
            $model->target_type = $request->input('target_type', 'all');
        }
        if ($request->has('community_id')) {
            $model->community_id = $request->input('community_id');
        }

        // Handle date fields with normalization
        if ($request->has('start_validate')) {
            $normalized = $this->toYmdDate($request->start_validate);
            if ($normalized) {
                $model->start_validate = $normalized;
            }
        }
        if ($request->has('finish_validate')) {
            $normalized = $this->toYmdDate($request->finish_validate);
            if ($normalized) {
                $model->finish_validate = $normalized;
            }
        }

        // Handle validation_time_limit with proper format conversion
        if ($request->has('validation_time_limit')) {
            $validation_time_limit = $request->input('validation_time_limit');
            if ($validation_time_limit && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $validation_time_limit)) {
                $validation_time_limit = strlen($validation_time_limit) == 5 ? $validation_time_limit . ':00' : $validation_time_limit;
            }
            $model->validation_time_limit = $validation_time_limit;
        }

        // Handle UMKM fields - ADD THIS CODE
        if ($request->has('level_umkm')) {
            $levelUmkm = $request->input('level_umkm');
            $model->level_umkm = ($levelUmkm === '' || $levelUmkm === 'null') ? null : $levelUmkm;
        }

        if ($request->has('max_production_per_day')) {
            $maxProduction = $request->input('max_production_per_day');
            $model->max_production_per_day = ($maxProduction === '' || $maxProduction === 'null') ? null : $maxProduction;
        }

        if ($request->has('sell_per_day')) {
            $sellPerDay = $request->input('sell_per_day');
            $model->sell_per_day = ($sellPerDay === '' || $sellPerDay === 'null') ? null : $sellPerDay;
        }

        // Log untuk debugging
        Log::info('AdController@update UMKM fields updated', [
            'ad_id' => $id,
            'level_umkm' => $model->level_umkm,
            'max_production_per_day' => $model->max_production_per_day,
            'sell_per_day' => $model->sell_per_day,
            'request_level_umkm' => $request->input('level_umkm'),
            'request_max_production' => $request->input('max_production_per_day'),
            'request_sell_per_day' => $request->input('sell_per_day'),
        ]);

        // Log data untuk debugging
        Log::info('AdController@update processing data', [
            'ad_id' => $id,
            'unlimited_grab' => $model->unlimited_grab,
            'jam_mulai' => $model->jam_mulai,
            'jam_berakhir' => $model->jam_berakhir,
            'day_type' => $model->day_type,
            'custom_days' => $model->custom_days,
            'validation_type' => $model->validation_type,
            'code' => $model->code,
            'title' => $model->title,
            'description' => $model->description,
            'promo_type' => $model->promo_type,
            'start_validate' => $model->start_validate,
            'finish_validate' => $model->finish_validate,
            'original_title' => $model->getOriginal('title'),
            'original_description' => $model->getOriginal('description'),
            'request_title' => $request->input('title'),
            'request_description' => $request->input('description'),
            'dirty_fields' => $model->getDirty(),
        ]);

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'ads');

            if ($oldPicture) {
                $this->delete_file($oldPicture ?? '');
            }
        }

        // * Check for additional images (support multiple formats: ads[image_1], ads.image_1, ads_image_1, image_1)
        // Handle image_1
        if ($request->hasFile('ads[image_1]') || $request->hasFile('ads.image_1') || $request->hasFile('ads_image_1') || $request->hasFile('image_1')) {
            $file = null;
            if ($request->hasFile('ads[image_1]')) {
                $file = $request->file('ads[image_1]');
            } elseif ($request->hasFile('ads.image_1')) {
                $file = $request->file('ads.image_1');
            } elseif ($request->hasFile('ads_image_1')) {
                $file = $request->file('ads_image_1');
            } elseif ($request->hasFile('image_1')) {
                $file = $request->file('image_1');
            }

            if ($file) {
                $model->image_1 = $this->upload_file($file, 'ads');
                if ($oldImage1) {
                    $this->delete_file($oldImage1);
                }
            }
        } else {
            // Preserve existing image_1 if no new file but URL exists in request
            $existingImage1 = $request->input('ads_image_1') ?:
                $request->input('image_1') ?:
                $request->input('ads.image_1') ?:
                $request->input('ads[image_1]');

            if ($existingImage1 && is_string($existingImage1)) {
                // Convert URL back to storage path
                if (strpos($existingImage1, '/storage/') !== false) {
                    $model->image_1 = str_replace('/storage/', '', parse_url($existingImage1, PHP_URL_PATH));
                } elseif (strpos($existingImage1, 'storage/') === 0) {
                    $model->image_1 = substr($existingImage1, 8); // Remove 'storage/' prefix
                } else {
                    $model->image_1 = $existingImage1;
                }

                Log::info('AdController@update preserved existing image_1', [
                    'ad_id' => $id,
                    'original_url' => $existingImage1,
                    'converted_path' => $model->image_1
                ]);
            }
        }

        // Handle image_2
        if ($request->hasFile('ads[image_2]') || $request->hasFile('ads.image_2') || $request->hasFile('ads_image_2') || $request->hasFile('image_2')) {
            $file = null;
            if ($request->hasFile('ads[image_2]')) {
                $file = $request->file('ads[image_2]');
            } elseif ($request->hasFile('ads.image_2')) {
                $file = $request->file('ads.image_2');
            } elseif ($request->hasFile('ads_image_2')) {
                $file = $request->file('ads_image_2');
            } elseif ($request->hasFile('image_2')) {
                $file = $request->file('image_2');
            }

            if ($file) {
                $model->image_2 = $this->upload_file($file, 'ads');
                if ($oldImage2) {
                    $this->delete_file($oldImage2);
                }
            }
        } else {
            // Preserve existing image_2 if no new file but URL exists in request
            $existingImage2 = $request->input('ads_image_2') ?:
                $request->input('image_2') ?:
                $request->input('ads.image_2') ?:
                $request->input('ads[image_2]');

            if ($existingImage2 && is_string($existingImage2)) {
                // Convert URL back to storage path
                if (strpos($existingImage2, '/storage/') !== false) {
                    $model->image_2 = str_replace('/storage/', '', parse_url($existingImage2, PHP_URL_PATH));
                } elseif (strpos($existingImage2, 'storage/') === 0) {
                    $model->image_2 = substr($existingImage2, 8); // Remove 'storage/' prefix
                } else {
                    $model->image_2 = $existingImage2;
                }

                Log::info('AdController@update preserved existing image_2', [
                    'ad_id' => $id,
                    'original_url' => $existingImage2,
                    'converted_path' => $model->image_2
                ]);
            }
        }

        // Handle image_3
        if ($request->hasFile('ads[image_3]') || $request->hasFile('ads.image_3') || $request->hasFile('ads_image_3') || $request->hasFile('image_3')) {
            $file = null;
            if ($request->hasFile('ads[image_3]')) {
                $file = $request->file('ads[image_3]');
            } elseif ($request->hasFile('ads.image_3')) {
                $file = $request->file('ads.image_3');
            } elseif ($request->hasFile('ads_image_3')) {
                $file = $request->file('ads_image_3');
            } elseif ($request->hasFile('image_3')) {
                $file = $request->file('image_3');
            }

            if ($file) {
                $model->image_3 = $this->upload_file($file, 'ads');
                if ($oldImage3) {
                    $this->delete_file($oldImage3);
                }
            }
        } else {
            // Preserve existing image_3 if no new file but URL exists in request
            $existingImage3 = $request->input('ads_image_3') ?:
                $request->input('image_3') ?:
                $request->input('ads.image_3') ?:
                $request->input('ads[image_3]');

            if ($existingImage3 && is_string($existingImage3)) {
                // Convert URL back to storage path
                if (strpos($existingImage3, '/storage/') !== false) {
                    $model->image_3 = str_replace('/storage/', '', parse_url($existingImage3, PHP_URL_PATH));
                } elseif (strpos($existingImage3, 'storage/') === 0) {
                    $model->image_3 = substr($existingImage3, 8); // Remove 'storage/' prefix
                } else {
                    $model->image_3 = $existingImage3;
                }

                Log::info('AdController@update preserved existing image_3', [
                    'ad_id' => $id,
                    'original_url' => $existingImage3,
                    'converted_path' => $model->image_3
                ]);
            }
        }

        // Update image timestamp for cache busting
        if (
            $request->hasFile('image') ||
            $request->hasFile('ads[image_1]') || $request->hasFile('ads.image_1') || $request->hasFile('ads_image_1') || $request->hasFile('image_1') ||
            $request->hasFile('ads[image_2]') || $request->hasFile('ads.image_2') || $request->hasFile('ads_image_2') || $request->hasFile('image_2') ||
            $request->hasFile('ads[image_3]') || $request->hasFile('ads.image_3') || $request->hasFile('ads_image_3') || $request->hasFile('image_3')
        ) {
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

        // Process target users untuk voucher dengan target user tertentu
        if ($model->type === 'voucher' && $model->target_type === 'user' && $request->has('target_user_ids')) {
            try {
                // Sync target users menggunakan tabel pivot
                $model->target_users()->sync($request->input('target_user_ids', []));
            } catch (\Throwable $th) {
                Log::error('AdController@update sync target users failed', [
                    'ad_id' => $model->id,
                    'error' => $th->getMessage()
                ]);
                // Don't rollback for this, just log the error
            }
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

        // Refresh model to get latest data including relationships
        $model->refresh();

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
                ->with(['cube.tags', 'cube.user', 'cube.corporate', 'ad_category'])
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
