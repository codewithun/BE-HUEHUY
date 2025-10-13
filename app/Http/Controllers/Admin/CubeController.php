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
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CubeController extends Controller
{
    /**
     * Decode field array yang mungkin terkirim sebagai string JSON via multipart/form-data.
     */
    private function normalizeArrayInput(Request $request): void
    {
        foreach (['ads', 'cube_tags', 'opening_hours'] as $key) {
            $val = $request->input($key);
            if (is_string($val)) {
                $decoded = json_decode($val, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $request->merge([$key => $decoded]);
                }
            }
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
        $searchBy = $request->get('searchBy', '');

        // ? Preparation
        $columnAliases = [
            'created_at'       => 'cubes.created_at',
            'cube_code'        => 'cubes.code',
            'category_name'    => 'ad_categories.name',
            'ads_title'        => 'ads.title',
            'ads_huehuy_title' => 'ads.title',
        ];

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
                $query = $query->orWhereRaw("id IN (SELECT cube_id from ads where title LIKE '%" . $request->get("search") . "%')");
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
        // Pastikan array ter-normalisasi
        $this->normalizeArrayInput($request);
        Log::info('opening_hours raw', ['opening_hours' => $request->input('opening_hours')]);

        // * Debug log untuk melihat data yang diterima
        Log::info('CubeController@store received data', [
            'cube_type_id' => $request->cube_type_id,
            'owner_user_id' => $request->owner_user_id,
            'user_id' => $request->user_id,
            'corporate_id' => $request->corporate_id,
            'all_request' => $request->all()
        ]);

        // ? Validate request
        $request->merge([
            'owner_user_id' => $request->filled('owner_user_id') ? $request->owner_user_id : null,
            'user_id'       => $request->filled('user_id')       ? $request->user_id       : null,
            'corporate_id'  => $request->filled('corporate_id')  ? $request->corporate_id  : null,
        ]);
        // Skip validation untuk manager tenant jika kubus informasi
        $isInformation = $request->boolean('is_information');

        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'required|numeric',
            'parent_id'    => 'nullable|numeric|exists:cubes,id',
            'user_id'      => $isInformation ? 'nullable|numeric|exists:users,id' : 'nullable|numeric|exists:users,id',
            'owner_user_id' => $isInformation ? 'nullable|numeric|exists:users,id' : 'nullable|numeric|exists:users,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id'     => 'nullable|numeric|exists:worlds,id',
            'color'        => 'nullable|string|max:255',
            'address'      => 'nullable|string|max:255',
            'map_lat'      => 'nullable|numeric',
            'map_lng'      => 'nullable|numeric',
            'is_recommendation' => 'nullable|boolean',
            'is_information'    => 'nullable|boolean',
            'link_information'  => 'nullable|string',
            'image'             => 'nullable',

            'cube_tags.*'          => 'nullable',
            'cube_tags.*.address'  => 'nullable|string|max:255',
            'cube_tags.*.map_lat'  => 'nullable|numeric',
            'cube_tags.*.map_lng'  => 'nullable|numeric',
            'cube_tags.*.link'     => 'nullable|string|max:255',

            'ads.*'                      => 'nullable',
            'ads.ad_category_id'         => 'nullable|numeric|exists:ad_categories,id',
            'ads.title'                  => 'nullable|string|max:255',
            'ads.max_grab'               => 'nullable|numeric',
            'ads.unlimited_grab'         => 'nullable|boolean',
            'ads.is_daily_grab'          => 'nullable|boolean',
            'ads.status'                 => 'nullable|string',
            'ads.promo_type'             => ['nullable', 'string', Rule::in(['offline', 'online'])],
            'ads.validation_type'        => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'ads.code'                   => [
                'nullable',
                'string',
                'max:255',
                'unique:ads,code',
                'required_if:ads.validation_type,manual'
            ],
            'ads.target_type'            => ['nullable', 'string', Rule::in(['all', 'user', 'community'])],
            'ads.target_user_ids'        => 'nullable|array',
            'ads.target_user_ids.*'      => 'numeric|exists:users,id',
            'ads.community_id'           => 'nullable|numeric|exists:communities,id',
            'ads.start_validate'         => 'nullable|date',
            'ads.finish_validate'        => 'nullable|date|after_or_equal:ads.start_validate',
            'ads.max_production_per_day' => 'nullable|numeric|min:0',
            'ads.sell_per_day'           => 'nullable|numeric|min:0',
            'ads.level_umkm'             => 'nullable|numeric|min:0',
            'ads.image'                  => 'nullable',
            'ads.validation_time_limit'  => 'nullable|date_format:H:i',
            'ads.jam_mulai'              => 'nullable|date_format:H:i',
            'ads.jam_berakhir'           => 'nullable|date_format:H:i',
            'ads.day_type'               => ['nullable', 'string', Rule::in(['weekend', 'weekday', 'custom'])],
            'ads.custom_days'            => 'nullable|array',

            'opening_hours.*'          => 'nullable',
            'opening_hours.*.day'      => ['required', 'string', Rule::in(['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'])],
            'opening_hours.*.open'     => 'nullable|date_format:H:i',
            'opening_hours.*.close'    => 'nullable|date_format:H:i',
            'opening_hours.*.is_24hour' => 'nullable|boolean',
            'opening_hours.*.is_closed' => 'nullable|boolean',
        ]);
        if ($validation) return $validation;

        // * Validate Cube Type
        $cubeType = CubeType::where('id', $request->cube_type_id)->first();
        if (!$cubeType) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "cube_type_id" => ["Tipe kubus tidak ditemukan"]
                ]
            ], 422);
        }

        // * Log info untuk debugging
        Log::info('CubeController@store validation passed', [
            'cube_type_id' => $request->cube_type_id,
            'owner_user_id' => $request->owner_user_id,
            'corporate_id' => $request->corporate_id,
            'is_information' => $request->boolean('is_information'),
            'cube_type_name' => $cubeType->name
        ]);

        // ? Initial
        DB::beginTransaction();
        $model = new Cube();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        // * Handle owner_user_id mapping to user_id (hanya jika diisi)
        if ($request->has('owner_user_id') && $request->owner_user_id) {
            $model->user_id = $request->owner_user_id;
            Log::info('CubeController@store mapping owner_user_id to user_id', [
                'owner_user_id' => $request->owner_user_id,
                'mapped_user_id' => $model->user_id
            ]);
        } else {
            // Jika tidak ada manager tenant, set user_id ke null
            $model->user_id = null;
            Log::info('CubeController@store no manager tenant provided', [
                'cube_type_id' => $request->cube_type_id,
                'is_information' => $request->boolean('is_information')
            ]);
        }

        $model->code   = $model->generateCubeCode($request->cube_type_id);
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
        try {
            if ($request->hasFile('image')) {
                $model->picture_source = $this->upload_file($request->file('image'), 'cube');
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('CubeController@store upload cube image failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(["message" => "Error: server side having problem!"], 500);
        }

        // ? Executing save cube
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('CubeController@store save cube failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(["message" => "Error: server side having problem!"], 500);
        }

        // ? Process Cube Tags
        try {
            $preparedCubeTagsData = [];
            $cubeTags = $request->input('cube_tags', []);
            if (is_array($cubeTags) && count($cubeTags) > 0) {
                foreach ($cubeTags as $tag) {
                    if (!is_array($tag)) continue;
                    $preparedCubeTagsData[] = [
                        'cube_id'    => $model->id,
                        'address'    => $tag['address'] ?? null,
                        'map_lat'    => $tag['map_lat'] ?? null,
                        'map_lng'    => $tag['map_lng'] ?? null,
                        'link'       => $tag['link'] ?? null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                }
                if (!empty($preparedCubeTagsData)) {
                    CubeTag::insert($preparedCubeTagsData);
                }
            }
        } catch (\Throwable $th) {
            DB::rollback();
            Log::error('CubeController@store insert cube tags failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(['message' => "Error: failed to insert new cube tags"], 500);
        }

        // ? Process Ads
        try {
            $adsPayload = $request->input('ads');

            // Also check for ads fields at root level (from frontend forms)
            if (empty($adsPayload)) {
                $adsPayload = [];
                $adsFields = [
                    'ad_category_id',
                    'title',
                    'description',
                    'max_grab',
                    'unlimited_grab',
                    'is_daily_grab',
                    'promo_type',
                    'validation_type',
                    'code',
                    'target_type',
                    'target_user_id',
                    'community_id',
                    'start_validate',
                    'finish_validate',
                    'max_production_per_day',
                    'sell_per_day',
                    'level_umkm',
                    'validation_time_limit',
                    'jam_mulai',
                    'jam_berakhir',
                    'day_type'
                ];

                foreach ($adsFields as $field) {
                    $value = $request->input("ads.{$field}") ?: $request->input($field);
                    if ($value !== null) {
                        $adsPayload[$field] = $value;
                    }
                }

                // Handle custom_days specially as it comes as array from frontend
                $customDaysFromRequest = $request->input('custom_days') ?: $request->input('ads.custom_days', []);
                if (!empty($customDaysFromRequest)) {
                    $adsPayload['custom_days'] = $customDaysFromRequest;
                }

                // Log the payload for debugging
                Log::info('CubeController@store ads payload', ['payload' => $adsPayload]);
            }

            if (is_array($adsPayload) && !empty($adsPayload)) {
                $ad = new Ad();
                $ad->cube_id                 = $model->id;
                $ad->ad_category_id          = $adsPayload['ad_category_id'] ?? null;
                $title                       = $adsPayload['title'] ?? null;
                $ad->title                   = $title;
                $ad->slug                    = $title ? StringHelper::uniqueSlug($title) : null;
                $ad->description             = $adsPayload['description'] ?? null;
                $ad->max_grab                = $adsPayload['max_grab'] ?? null;
                $ad->unlimited_grab          = ($adsPayload['unlimited_grab'] ?? $request->input('ads.unlimited_grab') ?? $request->input('unlimited_grab') ?? false) == 1;
                $ad->is_daily_grab           = ($adsPayload['is_daily_grab'] ?? false) == 1;
                $ad->promo_type              = $adsPayload['promo_type'] ?? null;

                // New validation fields
                $ad->validation_type         = $adsPayload['validation_type'] ?? 'auto';
                $ad->code                    = $adsPayload['code'] ?? null;

                // Target fields for voucher
                $ad->target_type             = $adsPayload['target_type'] ?? 'all';
                $ad->target_user_id          = $adsPayload['target_user_id'] ?? null;
                $ad->community_id            = $adsPayload['community_id'] ?? null;

                // Date validation fields
                if (!empty($adsPayload['start_validate'])) {
                    $ad->start_validate = Carbon::createFromFormat('d-m-Y', $adsPayload['start_validate'])->format('Y-m-d H:i:s');
                }
                if (!empty($adsPayload['finish_validate'])) {
                    $ad->finish_validate = Carbon::createFromFormat('d-m-Y', $adsPayload['finish_validate'])->format('Y-m-d H:i:s');
                }

                $ad->max_production_per_day  = $adsPayload['max_production_per_day'] ?? null;
                $ad->sell_per_day            = $adsPayload['sell_per_day'] ?? null;
                $ad->level_umkm              = $adsPayload['level_umkm'] ?? null;
                $ad->validation_time_limit   = $adsPayload['validation_time_limit'] ?? null;

                // Schedule fields for promo/voucher  
                $ad->jam_mulai               = $adsPayload['jam_mulai'] ?? $request->input('jam_mulai') ?? null;
                $ad->jam_berakhir            = $adsPayload['jam_berakhir'] ?? $request->input('jam_berakhir') ?? null;
                $ad->day_type                = $adsPayload['day_type'] ?? $request->input('day_type') ?? 'custom';

                // Handle custom days from frontend
                $customDays = [];

                // First try from ads payload
                if (!empty($adsPayload['custom_days']) && is_array($adsPayload['custom_days'])) {
                    $customDays = $adsPayload['custom_days'];
                } else {
                    // Check individual day fields from frontend (custom_days[monday], etc.)
                    $dayNames = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                    foreach ($dayNames as $day) {
                        $dayValue = $request->input("custom_days[{$day}]") ?? $request->input("custom_days.{$day}");
                        if ($dayValue && ($dayValue === true || $dayValue === 1 || $dayValue === '1' || $dayValue === 'true')) {
                            $customDays[$day] = true;
                        }
                    }

                    // Also check from top-level custom_days if it's an array
                    $topLevelCustomDays = $request->input('custom_days');
                    if (is_array($topLevelCustomDays)) {
                        $customDays = array_merge($customDays, $topLevelCustomDays);
                    }
                }

                $ad->custom_days = !empty($customDays) ? $customDays : null;

                // Log custom days for debugging
                Log::info('CubeController@store custom_days processing', [
                    'custom_days_raw' => $request->input('custom_days'),
                    'custom_days_processed' => $customDays,
                    'custom_days_final' => $ad->custom_days
                ]);

                $ad->status                  = 'active';

                $contentType = $request->input('content_type', 'promo');
                if ($contentType === 'iklan') {
                    $ad->type = 'iklan';
                } elseif ($contentType === 'voucher') {
                    $ad->type = 'voucher';
                } else {
                    $ad->type = 'general'; // promo & lainnya
                }

                // * ads image (field: ads.image)
                if ($request->hasFile('ads.image')) {
                    $ad->picture_source = $this->upload_file($request->file('ads.image'), 'ads');
                }

                // * ads additional images
                if ($request->hasFile('ads.image_1')) {
                    $ad->image_1 = $this->upload_file($request->file('ads.image_1'), 'ads');
                }
                if ($request->hasFile('ads.image_2')) {
                    $ad->image_2 = $this->upload_file($request->file('ads.image_2'), 'ads');
                }
                if ($request->hasFile('ads.image_3')) {
                    $ad->image_3 = $this->upload_file($request->file('ads.image_3'), 'ads');
                }

                // Update image timestamp for cache busting
                $ad->image_updated_at = now();

                $ad->save();

                // Process target users untuk voucher dengan target user tertentu
                if ($ad->type === 'voucher' && $ad->target_type === 'user' && !empty($adsPayload['target_user_ids'])) {
                    // Sync target users menggunakan tabel pivot
                    $ad->target_users()->sync($adsPayload['target_user_ids']);

                    // Send notifications
                    $this->sendVoucherNotifications($ad, $adsPayload['target_user_ids']);
                }

                // ? Process Voucher (only online)
                if ($ad->promo_type === 'online') {
                    Voucher::create([
                        'ad_id' => $ad->id,
                        'name'  => $ad->title ?? ('Voucher-' . $ad->id),
                        'code'  => (new Voucher())->generateVoucherCode(),
                    ]);
                }
            }
        } catch (\Throwable $th) {
            DB::rollback();
            Log::error('CubeController@store insert ads/voucher failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(['message' => "Error: failed to insert new ads"], 500);
        }

        // ? Process Opening Hour
        try {
            $preparedOpeningHourData = [];
            $openingHours = $request->input('opening_hours', []);
            if (is_array($openingHours) && count($openingHours) > 0) {
                foreach ($openingHours as $data) {
                    if (!is_array($data)) continue;
                    $preparedOpeningHourData[] = [
                        'cube_id'   => $model->id,
                        'day'       => $data['day'] ?? null,
                        'open'      => $data['open'] ?? null,
                        'close'     => $data['close'] ?? null,
                        'is_24hour' => $data['is_24hour'] ?? null,
                        'is_closed' => $data['is_closed'] ?? null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                }
                if (!empty($preparedOpeningHourData)) {
                    OpeningHour::insert($preparedOpeningHourData);
                }
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('CubeController@store insert opening hours failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(["message" => "Error: failed to insert new opening hours"], 500);
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
        // Pastikan array ter-normalisasi
        $this->normalizeArrayInput($request);

        // ? Initial
        DB::beginTransaction();
        $model = Cube::findOrFail($id);
        $oldPicture = $model->picture_source;

        // ? Validate request
        $request->merge([
            'owner_user_id' => $request->filled('owner_user_id') ? $request->owner_user_id : null,
            'user_id'       => $request->filled('user_id')       ? $request->user_id       : null,
            'corporate_id'  => $request->filled('corporate_id')  ? $request->corporate_id  : null,
        ]);
        // Skip validation untuk manager tenant jika kubus informasi
        $isInformation = $request->boolean('is_information');

        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'nullable|numeric|exists:cube_types,id',
            'parent_id'    => 'nullable|numeric|exists:cubes,id',
            'user_id'      => 'nullable|numeric|exists:users,id',
            'owner_user_id' => 'nullable|numeric|exists:users,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id'     => 'nullable|numeric|exists:worlds,id',
            'color'        => 'nullable|string|max:255',
            'address'      => 'required|string|max:255',
            'map_lat'      => 'required|numeric',
            'map_lng'      => 'required|numeric',
            'status'       => ['required', Rule::in(['active', 'inactive'])],
            'is_recommendation' => 'nullable|boolean',
            'is_information'    => 'nullable|boolean',
            'image'             => 'nullable',

            'cube_tags.*'          => 'nullable',
            'cube_tags.*.address'  => 'nullable|string|max:255',
            'cube_tags.*.map_lat'  => 'nullable|numeric',
            'cube_tags.*.map_lng'  => 'nullable|numeric',
            'cube_tags.*.link'     => 'nullable|string|max:255',

            // Add validation for ads fields in update
            'ads.*'                      => 'nullable',
            'ads.ad_category_id'         => 'nullable|numeric|exists:ad_categories,id',
            'ads.title'                  => 'nullable|string|max:255',
            'ads.max_grab'               => 'nullable|numeric',
            'ads.unlimited_grab'         => 'nullable|boolean',
            'ads.is_daily_grab'          => 'nullable|boolean',
            'ads.status'                 => 'nullable|string',
            'ads.promo_type'             => ['nullable', 'string', Rule::in(['offline', 'online'])],
            'ads.validation_type'        => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'ads.code'                   => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    // Validasi unique untuk update (ignore current ad id jika ada)
                    if ($value) {
                        $adId = $request->input('ads.id');
                        $exists = \App\Models\Ad::where('code', $value)
                            ->when($adId, function ($query) use ($adId) {
                                return $query->where('id', '!=', $adId);
                            })
                            ->exists();

                        if ($exists) {
                            $fail('Kode ini sudah digunakan oleh ads lain.');
                        }
                    }
                },
                'required_if:ads.validation_type,manual'
            ],
            'ads.target_type'            => ['nullable', 'string', Rule::in(['all', 'user', 'community'])],
            'ads.target_user_ids'        => 'nullable|array',
            'ads.target_user_ids.*'      => 'numeric|exists:users,id',
            'ads.community_id'           => 'nullable|numeric|exists:communities,id',
            'ads.start_validate'         => 'nullable|date',
            'ads.finish_validate'        => 'nullable|date|after_or_equal:ads.start_validate',
            'ads.jam_mulai'              => 'nullable|date_format:H:i',
            'ads.jam_berakhir'           => 'nullable|date_format:H:i',
            'ads.day_type'               => ['nullable', 'string', Rule::in(['weekend', 'weekday', 'custom'])],
            'ads.custom_days'            => 'nullable|array',
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

        // * Handle owner_user_id mapping to user_id (hanya jika diisi)
        if ($request->has('owner_user_id') && $request->owner_user_id) {
            $model->user_id = $request->owner_user_id;
            Log::info('CubeController@update mapping owner_user_id to user_id', [
                'cube_id' => $model->id,
                'owner_user_id' => $request->owner_user_id,
                'mapped_user_id' => $model->user_id
            ]);
        } else {
            // Jika tidak ada manager tenant, set user_id ke null
            $model->user_id = null;
            Log::info('CubeController@update no manager tenant provided', [
                'cube_id' => $model->id,
                'cube_type_id' => $request->cube_type_id
            ]);
        }

        // * Check if has upload file
        try {
            if ($request->hasFile('image')) {
                $model->picture_source = $this->upload_file($request->file('image'), 'cube');
                if ($oldPicture) {
                    $this->delete_file($oldPicture ?? '');
                }
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('CubeController@update upload cube image failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(["message" => "Error: server side having problem!"], 500);
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('CubeController@update save cube failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(["message" => "Error: server side having problem!"], 500);
        }

        // ? Process Cube Tags
        try {
            $cubeTags = $request->input('cube_tags', []);
            if (is_array($cubeTags)) {
                CubeTag::where('cube_id', $model->id)->delete();

                $preparedCubeTagsData = [];
                foreach ($cubeTags as $tag) {
                    if (!is_array($tag)) continue;
                    $preparedCubeTagsData[] = [
                        'cube_id'    => $model->id,
                        'address'    => $tag['address'] ?? null,
                        'map_lat'    => $tag['map_lat'] ?? null,
                        'map_lng'    => $tag['map_lng'] ?? null,
                        'link'       => $tag['link'] ?? null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                }
                if (!empty($preparedCubeTagsData)) {
                    CubeTag::insert($preparedCubeTagsData);
                }
            }
        } catch (\Throwable $th) {
            DB::rollback();
            Log::error('CubeController@update upsert cube tags failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(['message' => "Error: failed to insert new cube tags"], 500);
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
        try {
            if ($model->picture_source) {
                $this->delete_file($model->picture_source ?? '');
            }
        } catch (\Throwable $th) {
            Log::error('CubeController@destroy delete image failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
        }

        // ? Executing
        try {
            $model->delete();
        } catch (\Throwable $th) {
            Log::error('CubeController@destroy delete cube failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
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
            Log::error('CubeController@updateStatus save cube failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(["message" => "Error: failed to update cube data"], 500);
        }

        // ? Update Ads Status
        try {
            Ad::where('cube_id', $model->id)
                ->update(['status' => $request->status]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('CubeController@updateStatus update ads failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);
            return response(["message" => "Error: failed to update ads data"], 500);
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
        // Pastikan array ter-normalisasi
        $this->normalizeArrayInput($request);

        // ? Validate request
        $request->merge([
            'owner_user_id' => $request->filled('owner_user_id') ? $request->owner_user_id : null,
            'user_id'       => $request->filled('user_id')       ? $request->user_id       : null,
            'corporate_id'  => $request->filled('corporate_id')  ? $request->corporate_id  : null,
        ]);
        // Skip validation untuk manager tenant jika kubus informasi
        $isInformation = $request->boolean('is_information');

        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'required|numeric',
            'parent_id'    => 'nullable|numeric|exists:cubes,id',
            'user_id'      => 'nullable|numeric|exists:users,id',
            'owner_user_id' => 'nullable|numeric|exists:users,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id'     => 'nullable|numeric|exists:worlds,id',
            'color'        => 'nullable|string|max:255',
            'address'      => 'nullable|string|max:255',
            'map_lat'      => 'nullable|numeric',
            'map_lng'      => 'nullable|numeric',
            'inactive_at'  => 'nullable|date_format:d-m-Y H:i:s',
            'is_information' => 'nullable|boolean',
            'total'          => 'required|numeric',

            'cube_tags.*'         => 'nullable',
            'cube_tags.*.address' => 'nullable|string|max:255',
            'cube_tags.*.map_lat' => 'nullable|numeric',
            'cube_tags.*.map_lng' => 'nullable|numeric',

            'ads.*'                      => 'nullable',
            'ads.ad_category_id'         => 'nullable|numeric|exists:ad_categories,id',
            'ads.title'                  => 'nullable|string|max:255',
            'ads.max_grab'               => 'nullable|numeric',
            'ads.unlimited_grab'         => 'nullable|boolean',
            'ads.is_daily_grab'          => 'nullable|boolean',
            'ads.status'                 => 'nullable|string',
            'ads.promo_type'             => ['nullable', 'string', Rule::in(['offline', 'online'])],
            'ads.max_production_per_day' => 'nullable|numeric|min:0',
            'ads.sell_per_day'           => 'nullable|numeric|min:0',
            'ads.level_umkm'             => 'nullable|numeric|min:0',
            'ads.image'                  => 'nullable',
        ]);
        if ($validation) return $validation;

        // * Validate Cube Type
        $cubeType = CubeType::where('id', $request->cube_type_id)->first();
        if (!$cubeType) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "cube_type_id" => ["Tipe kubus tidak ditemukan"]
                ]
            ], 422);
        }

        // ? Initial
        DB::beginTransaction();

        $cubeCreated = [];
        if ($request->total && $request->total > 0) {
            for ($i = 0; $i < $request->total; $i++) {
                try {
                    $model = new Cube();

                    // ? Dump data
                    $model = $this->dump_field($request->all(), $model);

                    // * Handle owner_user_id mapping to user_id (hanya jika diisi)
                    if ($request->has('owner_user_id') && $request->owner_user_id) {
                        $model->user_id = $request->owner_user_id;
                        Log::info('CubeController@createGiftCube mapping owner_user_id to user_id', [
                            'iteration' => $i + 1,
                            'owner_user_id' => $request->owner_user_id,
                            'mapped_user_id' => $model->user_id
                        ]);
                    } else {
                        // Jika tidak ada manager tenant, set user_id ke null
                        $model->user_id = null;
                        Log::info('CubeController@createGiftCube no manager tenant provided', [
                            'iteration' => $i + 1,
                            'cube_type_id' => $request->cube_type_id
                        ]);
                    }

                    $model->code   = $model->generateCubeCode($request->cube_type_id);
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

                    $model->save();
                    $cubeCreated[] = $model;

                    // ? Process Cube Tags
                    $preparedCubeTagsData = [];
                    $cubeTags = $request->input('cube_tags', []);
                    if (is_array($cubeTags) && count($cubeTags) > 0) {
                        foreach ($cubeTags as $tag) {
                            if (!is_array($tag)) continue;
                            $preparedCubeTagsData[] = [
                                'cube_id'    => $model->id,
                                'address'    => $tag['address'] ?? null,
                                'map_lat'    => $tag['map_lat'] ?? null,
                                'map_lng'    => $tag['map_lng'] ?? null,
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                        }
                        if (!empty($preparedCubeTagsData)) {
                            CubeTag::insert($preparedCubeTagsData);
                        }
                    }

                    // ? Process Ads
                    $adsPayload = $request->input('ads');
                    if (is_array($adsPayload) && !empty($adsPayload)) {
                        $ad = new Ad();
                        $ad->cube_id                = $model->id;
                        $ad->ad_category_id         = $adsPayload['ad_category_id'] ?? null;
                        $title                      = $adsPayload['title'] ?? null;
                        $ad->title                  = $title;
                        $ad->slug                   = $title ? StringHelper::uniqueSlug($title) : null;
                        $ad->description            = $adsPayload['description'] ?? null;
                        $ad->max_grab               = $adsPayload['max_grab'] ?? null;
                        $ad->unlimited_grab         = ($adsPayload['unlimited_grab'] ?? false) == 1;
                        $ad->is_daily_grab          = $adsPayload['is_daily_grab'] ?? null;
                        $ad->promo_type             = $adsPayload['promo_type'] ?? null;
                        $ad->max_production_per_day = $adsPayload['max_production_per_day'] ?? null;
                        $ad->sell_per_day           = $adsPayload['sell_per_day'] ?? null;
                        $ad->level_umkm             = $adsPayload['level_umkm'] ?? null;

                        // Schedule fields for promo/voucher
                        $ad->jam_mulai              = $adsPayload['jam_mulai'] ?? null;
                        $ad->jam_berakhir           = $adsPayload['jam_berakhir'] ?? null;
                        $ad->day_type               = $adsPayload['day_type'] ?? 'custom';
                        $ad->validation_type        = $adsPayload['validation_type'] ?? 'auto';
                        $ad->code                   = $adsPayload['code'] ?? null;
                        $ad->validation_time_limit  = $adsPayload['validation_time_limit'] ?? null;

                        // Handle custom days
                        $customDays = [];
                        if (!empty($adsPayload['custom_days']) && is_array($adsPayload['custom_days'])) {
                            $customDays = $adsPayload['custom_days'];
                        }
                        $ad->custom_days = !empty($customDays) ? $customDays : null;

                        $ad->status                 = 'active';

                        if ($request->hasFile('ads.image')) {
                            $ad->picture_source = $this->upload_file($request->file('ads.image'), 'ads');
                        }
                        $contentType = $request->input('content_type', 'promo');
                        if ($contentType === 'iklan') {
                            $ad->type = 'iklan';
                        } elseif ($contentType === 'voucher') {
                            $ad->type = 'voucher';
                        } else {
                            $ad->type = 'general'; // untuk promo dan lainnya
                        }

                        $ad->save();
                    }
                } catch (\Throwable $th) {
                    DB::rollBack();
                    Log::error('CubeController@createGiftCube failed', [
                        'error' => $th->getMessage(),
                        'file'  => $th->getFile(),
                        'line'  => $th->getLine(),
                    ]);
                    return response(["message" => "Error: server side having problem!"], 500);
                }
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $cubeCreated
        ], 201);
    }

    /**
     * Send voucher notifications to specific users
     */
    private function sendVoucherNotifications($ad, $userIds)
    {
        try {
            if (empty($userIds) || !is_array($userIds)) {
                return;
            }

            $title = "Voucher Baru Tersedia!";
            $message = "Voucher '{$ad->title}' telah tersedia untuk Anda. Segera gunakan sebelum habis!";

            foreach ($userIds as $userId) {
                Notification::create([
                    'user_id' => $userId,
                    'type' => 'voucher',
                    'title' => $title,
                    'message' => $message,
                    'target_type' => 'voucher',
                    'target_id' => $ad->id,
                    'meta' => json_encode([
                        'cube_id' => $ad->cube_id,
                        'ad_type' => $ad->type,
                        'validation_type' => $ad->validation_type
                    ])
                ]);
            }

            Log::info('Voucher notifications sent', [
                'ad_id' => $ad->id,
                'user_count' => count($userIds)
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send voucher notifications', [
                'ad_id' => $ad->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate ad code (promo/voucher)
     */
    public function validateCode(Request $request, string $adId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'code' => 'required|string',
            'validator_role' => 'sometimes|in:tenant,admin',
        ]);

        try {
            $ad = Ad::with(['cube', 'community', 'target_users'])->findOrFail($adId);

            // Cek apakah ad memiliki validation_type manual
            if ($ad->validation_type !== 'manual') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ads ini tidak menggunakan validasi manual'
                ], 422);
            }

            // Verifikasi kode
            if (!hash_equals((string)$ad->code, (string)$data['code'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode tidak valid'
                ], 422);
            }

            // Cek validitas tanggal
            $now = now();
            if ($ad->start_validate && $now->lt($ad->start_validate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo/voucher belum dimulai'
                ], 422);
            }

            if ($ad->finish_validate && $now->gt($ad->finish_validate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo/voucher sudah berakhir'
                ], 422);
            }

            // Cek jam validasi
            if ($ad->validation_time_limit) {
                $currentTime = $now->format('H:i:s');
                if ($currentTime > $ad->validation_time_limit) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Waktu validasi sudah berakhir untuk hari ini'
                    ], 422);
                }
            }

            // Cek target user untuk voucher
            if ($ad->type === 'voucher' && $ad->target_type === 'user') {
                $targetUserIds = $ad->target_users()->pluck('user_id')->toArray();
                if (!in_array($user->id, $targetUserIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses untuk voucher ini'
                    ], 403);
                }
            }

            // Cek target community untuk voucher
            if ($ad->type === 'voucher' && $ad->target_type === 'community' && $ad->community_id) {
                // Cek apakah user adalah member dari community
                $isMember = \App\Models\CommunityMembership::where('user_id', $user->id)
                    ->where('community_id', $ad->community_id)
                    ->exists();

                if (!$isMember) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda bukan member dari komunitas yang ditargetkan'
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Kode valid',
                'data' => [
                    'ad' => [
                        'id' => $ad->id,
                        'title' => $ad->title,
                        'type' => $ad->type,
                        'validation_type' => $ad->validation_type,
                        'target_type' => $ad->target_type,
                    ],
                    'cube' => [
                        'id' => $ad->cube->id,
                        'code' => $ad->cube->code,
                        'address' => $ad->cube->address,
                    ],
                    'validated_at' => $now->toISOString(),
                    'validator' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => $data['validator_role'] ?? 'user'
                    ]
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Ad code validation failed', [
                'ad_id' => $adId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat validasi'
            ], 500);
        }
    }
}
