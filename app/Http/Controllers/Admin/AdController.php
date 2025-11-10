<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\StringHelper;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Voucher;
use App\Models\VoucherItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            return null;
        }
    }

    // ========================================>
    // ## Helper Methods for Voucher Sync
    // ========================================>

    /**
     * Generate consistent code yang digunakan di semua tabel terkait
     * Format yang konsisten dengan PromoController
     */
    private function generateConsistentCode(?string $validationType = 'auto', ?string $manualCode = null, string $prefix = 'VCR'): string
    {
        $validationType = strtolower($validationType ?? 'auto');

        if ($validationType === 'manual' && !empty($manualCode)) {
            // Untuk manual validation, gunakan kode yang diinput admin
            return $manualCode;
        }

        // Untuk auto validation atau manual tanpa kode, generate code baru
        do {
            $code = $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while (
            \App\Models\Voucher::where('code', $code)->exists() ||
            \App\Models\VoucherItem::where('code', $code)->exists() ||
            \App\Models\Ad::where('code', $code)->exists()
        );

        return $code;
    }

    /**
     * Handle voucher sync untuk create/update ads
     */
    private function handleVoucherSync(Request $request, Ad $ad, $isUpdate = false)
    {
        // Cek flag sync voucher dari frontend
        if (
            !$request->input('_sync_to_voucher_management') ||
            !$request->has('_voucher_sync_data') ||
            $request->input('content_type') !== 'voucher'
        ) {
            return;
        }

        $voucherSyncData = $request->input('_voucher_sync_data', []);



        try {
            if ($isUpdate) {
                // Update voucher yang terkait dengan ad_id
                $voucher = Voucher::where('ad_id', $ad->id)->first();

                if ($voucher) {
                    $this->updateVoucherFromSyncData($voucher, $voucherSyncData);
                } else {
                    // Buat voucher baru jika belum ada
                    $this->createVoucherFromSyncData($ad, $voucherSyncData);
                }
            } else {
                // Create voucher baru
                $this->createVoucherFromSyncData($ad, $voucherSyncData);
            }
        } catch (\Throwable $e) {

            throw $e;
        }
    }

    /**
     * Create voucher dari sync data
     */
    private function createVoucherFromSyncData(Ad $ad, array $voucherSyncData)
    {
        // Set ad_id setelah ads dibuat
        $voucherSyncData['ad_id'] = $ad->id;

        // Resolve owner info jika perlu
        $voucherSyncData = $this->resolveOwnerInfo($voucherSyncData);

        // ✅ PERBAIKAN: Generate code yang konsisten
        $validationType = $voucherSyncData['validation_type'] ?? 'auto';
        $manualCode = $voucherSyncData['code'] ?? null;

        $consistentCode = $this->generateConsistentCode($validationType, $manualCode, 'VCR');
        $voucherSyncData['code'] = $consistentCode;

        // Update ads dengan kode yang sama untuk konsistensi
        $ad->update(['code' => $consistentCode]);

        // Hapus field yang tidak ada di tabel vouchers
        unset($voucherSyncData['owner_user_id'], $voucherSyncData['cube_id']);

        // Pastikan required fields ada
        $voucherSyncData['type'] = $voucherSyncData['type'] ?? 'voucher';
        $voucherSyncData['validation_type'] = $validationType;

        // Create voucher
        $voucher = Voucher::create($voucherSyncData);



        return $voucher;
    }

    /**
     * Update voucher dari sync data
     */
    private function updateVoucherFromSyncData(Voucher $voucher, array $voucherSyncData)
    {
        // Resolve owner info jika perlu
        $voucherSyncData = $this->resolveOwnerInfo($voucherSyncData);

        // ✅ PERBAIKAN: Handle code update ketika validation_type berubah
        $validationType = $voucherSyncData['validation_type'] ?? $voucher->validation_type;

        if ($validationType === 'manual' && isset($voucherSyncData['code']) && !empty($voucherSyncData['code'])) {
            // Untuk manual validation, gunakan kode dari input admin
            $voucherSyncData['code'] = $voucherSyncData['code'];
        } elseif ($validationType === 'auto' && isset($voucherSyncData['code'])) {
            // Untuk auto validation, tetap gunakan generated code atau yang sudah ada

            // Hapus code dari update data agar tidak ter-override
            unset($voucherSyncData['code']);
        }

        // Hapus field yang tidak ada di tabel vouchers atau tidak boleh diupdate
        unset($voucherSyncData['owner_user_id'], $voucherSyncData['ad_id'], $voucherSyncData['cube_id']);

        // Update voucher
        $voucher->update($voucherSyncData);
    }

    /**
     * Resolve owner info dari owner_user_id jika ada
     */
    private function resolveOwnerInfo(array $voucherSyncData)
    {
        $ownerUserId = $voucherSyncData['owner_user_id'] ?? null;

        if (
            $ownerUserId &&
            (($voucherSyncData['owner_name'] ?? '') === 'TO_BE_RESOLVED' ||
                ($voucherSyncData['owner_phone'] ?? '') === 'TO_BE_RESOLVED')
        ) {

            $user = User::find($ownerUserId);
            if ($user) {
                if (($voucherSyncData['owner_name'] ?? '') === 'TO_BE_RESOLVED') {
                    $voucherSyncData['owner_name'] = $user->name ?? $user->email ?? 'User #' . $user->id;
                }
                if (($voucherSyncData['owner_phone'] ?? '') === 'TO_BE_RESOLVED') {
                    $voucherSyncData['owner_phone'] = $user->phone ?? null;
                }
            }
        }

        return $voucherSyncData;
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
        // NOTE: Untuk promo harian (is_daily_grab = 1):
        // - Stok di-reset otomatis setiap hari (filter WHERE date = DATE(NOW()))
        // - Admin set max_grab = jumlah stok per hari
        // - Sistem hanya hitung grab untuk hari ini, besok akan reset otomatis
        // - Sampai finish_validate tercapai, setiap hari akan ada max_grab stok baru
        $query = $query->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
            ->groupBy('ads.id')
            ->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->select([
                ...$model->selectable,
                // ✅ PERBAIKAN: Hitung total_grab yang lebih akurat
                DB::raw('CAST(IF(ads.is_daily_grab = 1,
                    COALESCE((SELECT total_grab FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id LIMIT 1), 0),
                    COALESCE((SELECT COUNT(*) FROM promo_items pi 
                              JOIN promos p ON p.id = pi.promo_id 
                              WHERE p.code = ads.code 
                              AND pi.status IN ("reserved", "redeemed")), 0)
                ) AS SIGNED) AS total_grab'),
                // ✅ PERBAIKAN: Hitung total_remaining dengan prioritas promo.stock, pastikan tidak negatif
                DB::raw('CAST(GREATEST(0, IF(ads.unlimited_grab = 1,
                    9999999,
                    IF(ads.is_daily_grab = 1,
                        ads.max_grab - COALESCE((SELECT total_grab FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id LIMIT 1), 0),
                        COALESCE((SELECT stock FROM promos WHERE code = ads.code LIMIT 1), ads.max_grab) - 
                        COALESCE((SELECT COUNT(*) FROM promo_items pi 
                                  JOIN promos p ON p.id = pi.promo_id 
                                  WHERE p.code = ads.code 
                                  AND pi.status IN ("reserved", "redeemed")), 0)
                    )
                )) AS SIGNED) AS total_remaining'),
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
            'detail' => 'nullable|string',
            'max_grab' => 'nullable|numeric',
            'unlimited_grab' => 'nullable',
            'is_daily_grab' => 'nullable',
            'status' => 'nullable|string',
            'type' => ['required', Rule::in(['general', 'voucher', 'huehuy', 'iklan'])],
            'promo_type' => ['required', Rule::in(['offline', 'online'])],
            'online_store_link' => 'nullable|string|max:500',
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

                // Don't rollback for this, just log the error
            }
        }

        DB::commit();

        /**
         * * Handle Voucher Sync (PERBAIKAN)
         */
        if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
            // Gunakan helper method untuk voucher sync lengkap
            try {
                $this->handleVoucherSync($request, $model, false);
            } catch (\Throwable $th) {

                // Tidak rollback karena ads sudah berhasil dibuat
            }
        }
        /**
         * * Create Voucher (Legacy - HANYA untuk content_type voucher)
         */
        elseif ($request->input('content_type') === 'voucher' && $model->type === 'voucher') {
            try {
                // Gunakan updateOrCreate agar aman dari duplikasi ad_id
                Voucher::updateOrCreate(
                    ['ad_id' => $model->id],
                    [
                        'name' => $model->title,
                        'code' => (new \App\Models\Voucher())->generateVoucherCode(),
                        'stock' => 0,
                        'validation_type' => 'auto',
                        'target_type' => 'all',
                    ]
                );
            } catch (\Throwable $th) {
            }
        }


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

        // ✅ MAP: cube_tags[0][link] -> online_store_link (untuk konsistensi frontend)
        if (!$request->filled('online_store_link')) {
            $cubeTags = $request->input('cube_tags', []);
            if (is_array($cubeTags) && isset($cubeTags[0]['link']) && !empty($cubeTags[0]['link'])) {
                $request->merge(['online_store_link' => $cubeTags[0]['link']]);
                Log::info('✅ AdController@update mapped cube_tags[0][link] to online_store_link', [
                    'ad_id' => $id,
                    'link' => $cubeTags[0]['link']
                ]);
            }
        }

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
            'detail' => 'nullable|string',
            'max_grab' => 'nullable|numeric',
            'unlimited_grab' => 'nullable',
            'is_daily_grab' => 'nullable',
            'type' => ['nullable', Rule::in(['general', 'voucher', 'huehuy', 'iklan'])],
            'promo_type' => ['nullable', Rule::in(['offline', 'online'])],
            'online_store_link' => 'nullable|string|max:500',
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

            return $validation;
        }

        // Log request data untuk debugging


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
        if ($request->has('detail')) {
            $model->detail = $request->input('detail');
        }
        if ($request->has('ad_category_id')) {
            $adCategoryId = $request->input('ad_category_id');
            // Handle empty string or null
            $model->ad_category_id = ($adCategoryId === '' || $adCategoryId === 'null') ? null : $adCategoryId;
        }
        if ($request->has('promo_type')) {
            $model->promo_type = $request->input('promo_type');
        }
        if ($request->has('online_store_link')) {
            $model->online_store_link = $request->input('online_store_link');
        }
        if ($request->has('validation_type')) {
            $model->validation_type = $request->input('validation_type', 'auto');
        }
        if ($request->has('code')) {
            $candidateCode = $request->input('code');

            // ✅ VALIDASI EXTRA: Jika validation_type adalah manual dan kode masih pattern auto-generated
            // maka ini bug frontend, kita REJECT dan throw error agar frontend fix
            if ($model->validation_type === 'manual' && $candidateCode && preg_match('/^(KUBUS|VCR)-\d{13,}-\d{1,5}$/', $candidateCode)) {


                DB::rollBack();
                return response([
                    "message" => "Error: Kode untuk validasi manual tidak boleh menggunakan pattern auto-generated. Harap masukkan kode unik manual.",
                    "error_code" => "INVALID_MANUAL_CODE",
                    "rejected_code" => $candidateCode,
                    "errors" => [
                        "code" => ["Kode untuk validasi manual tidak boleh menggunakan pattern auto-generated (KUBUS-xxx atau VCR-xxx). Harap masukkan kode unik manual seperti: MYCODE123, VOUCHER-001, atau PROMO2025."]
                    ]
                ], 422);
            }

            $model->code = $candidateCode;
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
        // ✅ PERBAIKAN: Selalu replace custom_days jika ada di request untuk menghapus hari yang di-uncheck
        if ($request->has('custom_days')) {
            $customDays = $request->input('custom_days', []);
            if (is_array($customDays)) {
                // Filter untuk hanya menyimpan hari yang bernilai true/1
                $filteredDays = [];
                foreach ($customDays as $day => $value) {
                    // Hanya simpan hari yang benar-benar dipilih (true, 1, "1", "true")
                    if (in_array($value, [true, 1, '1', 'true'], true)) {
                        $filteredDays[$day] = true;
                    }
                }
                // Simpan hasil filter (empty array akan jadi null)
                $model->custom_days = !empty($filteredDays) ? $filteredDays : null;
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

        // Log data untuk debugging


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

            // 🔍 DEBUG: Check kondisi sync
            Log::info('🔍 AdController@update checking sync conditions', [
                'ad_id' => $model->id,
                'has_online_store_link' => $request->has('online_store_link'),
                'has_cube_tags' => $request->has('cube_tags'),
                'online_store_link_value' => $model->online_store_link,
                'cube_id' => $model->cube_id
            ]);

            // ✅ Sinkronisasi online_store_link ke cube, cube_tags, dan promo
            // PERBAIKAN: Cek jika model->online_store_link tidak null, bukan hanya request
            if ($model->online_store_link !== null && $model->cube_id) {
                try {
                    $linkToSync = $model->online_store_link;

                    // Update cube.link_information
                    $cube = \App\Models\Cube::find($model->cube_id);
                    if ($cube) {
                        $cube->link_information = $linkToSync;
                        $cube->save();
                        Log::info('✅ AdController@update synced online_store_link to cube.link_information', [
                            'ad_id' => $model->id,
                            'cube_id' => $cube->id,
                            'link' => $linkToSync
                        ]);

                        // ✅ IMPORTANT: Update cube_tags.link juga agar frontend bisa baca saat edit
                        $affectedRows = \App\Models\CubeTag::where('cube_id', $cube->id)->update(['link' => $linkToSync]);
                        Log::info('✅ AdController@update synced online_store_link to cube_tags', [
                            'ad_id' => $model->id,
                            'cube_id' => $cube->id,
                            'link' => $linkToSync,
                            'affected_rows' => $affectedRows
                        ]);
                    }                    // Update promo.online_store_link jika ada promo dengan code yang sama
                    if ($model->code) {
                        $promo = \App\Models\Promo::where('code', $model->code)->first();
                        if ($promo) {
                            $promo->online_store_link = $linkToSync;
                            $promo->save();
                            Log::info('✅ AdController@update synced online_store_link to promo', [
                                'ad_id' => $model->id,
                                'promo_id' => $promo->id,
                                'link' => $linkToSync
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('❌ AdController@update failed to sync online_store_link', [
                        'ad_id' => $model->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // ✅ Sinkronisasi stok promo ke tabel promos jika ada relasi
            try {
                $promo = \App\Models\Promo::where('code', $model->code)->first();

                if ($promo) {
                    // Jika admin ubah max_grab, sinkronkan ke promos.stock
                    if ($request->has('max_grab') && !$model->unlimited_grab) {
                        $promo->stock = $request->input('max_grab');
                        $promo->save();
                    }
                }
            } catch (\Throwable $th) {
            }
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

                // Don't rollback for this, just log the error
            }
        }

        /**
         * * Handle Voucher Sync (PERBAIKAN)
         */
        if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
            // Gunakan helper method untuk voucher sync lengkap
            try {
                $this->handleVoucherSync($request, $model, true);
            } catch (\Throwable $th) {

                // Tidak rollback karena ads sudah berhasil diupdate
            }
        }
        /**
         * * Update/Create Voucher (Legacy - HANYA untuk content_type voucher)
         */
        elseif ($request->input('content_type') === 'voucher' && $model->type === 'voucher') {
            $voucher = Voucher::where('ad_id', $model->id)->first();

            if (!$voucher) {
                try {
                    $voucher = new Voucher();
                    $voucher->ad_id = $model->id;
                    $voucher->name = $model->title;
                    $voucher->code = $voucher->generateVoucherCode();

                    $voucher->save();
                } catch (\Throwable $th) {

                    // Tidak rollback karena ads sudah berhasil diupdate
                }
            } else {
                // ✅ PERBAIKAN: Update voucher name, code, dan validation_type jika ada perubahan
                try {
                    $updateData = [];

                    // Update name jika berubah
                    if ($voucher->name !== $model->title) {
                        $updateData['name'] = $model->title;
                    }

                    // Update validation_type jika berubah
                    if ($request->has('validation_type') && $voucher->validation_type !== $model->validation_type) {
                        $updateData['validation_type'] = $model->validation_type;
                    }

                    // Update code jika validation_type adalah manual dan code berubah
                    if ($model->validation_type === 'manual' && $request->has('code') && $voucher->code !== $model->code) {
                        $updateData['code'] = $model->code;
                    }

                    // Lakukan update jika ada perubahan
                    if (!empty($updateData)) {
                        $voucher->update($updateData);
                    }
                } catch (\Throwable $th) {
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

        // * Remove related voucher if exists (PERBAIKAN)
        try {
            $relatedVoucher = Voucher::where('ad_id', $id)->first();
            if ($relatedVoucher) {
                // Hapus voucher items dulu
                VoucherItem::where('voucher_id', $relatedVoucher->id)->delete();
                // Hapus voucher
                $relatedVoucher->delete();
            }
        } catch (\Throwable $th) {

            // Lanjutkan proses delete ad meskipun voucher gagal dihapus
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
                            COALESCE((SELECT stock FROM promos WHERE code = ads.code LIMIT 1), ads.max_grab) - 
                            COALESCE((SELECT COUNT(*) FROM promo_items pi 
                                      JOIN promos p ON p.id = pi.promo_id 
                                      WHERE p.code = ads.code 
                                      AND pi.status IN ("reserved", "redeemed")), 0)
                        )
                    )) AS SIGNED) AS total_remaining'),
            ])
                ->with(['cube.tags', 'cube.user', 'cube.corporate', 'ad_category'])
                ->where('ads.id', $id)
                ->first();

            if (!$ad) {
                return response(['message' => 'not found'], 404);
            }

            // ✅ Ambil stok promo (kalau ada di tabel promos)
            $promo = \App\Models\Promo::where('code', $ad->code)->first();
            $ad->remaining_stock = $promo ? $promo->stock : $ad->max_grab;
            $ad->stock_source = $promo ? 'promo' : 'ad';

            // Pastikan waktu dikirim ke frontend
            $ad->jam_mulai = $ad->jam_mulai ?? '00:00:00';
            $ad->jam_berakhir = $ad->jam_berakhir ?? '23:59:59';

            return response([
                'message' => 'success',
                'data' => $ad
            ], 200);
        } catch (\Throwable $e) {
            return response(['message' => 'server error'], 500);
        }
    }

    public function claim(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Diagnostic: log which claim path is used by frontend
        try {
            $ad = \App\Models\Ad::find($id);
            if (!$ad) {
                return response()->json(['success' => false, 'message' => 'Promo/Voucher tidak ditemukan'], 404);
            }

            // Tentukan apakah ini promo atau voucher
            $itemType = ($ad->type === 'voucher') ? 'voucher' : 'promo';

            // 🗓️ Validasi masa berlaku (perbaikan)
            $now = now('Asia/Jakarta');

            // Hitung batas waktu mulai & berakhir gabungan (tanggal + jam)
            // 🕒 Zona waktu lokal
            $tz = 'Asia/Jakarta';
            $now = Carbon::now($tz);

            // 🔹 Pastikan start & end date benar-benar lokal (bukan UTC mentah)
            $startDateOnly = $ad->start_validate
                ? Carbon::parse($ad->start_validate, $tz)->toDateString()
                : null;

            $endDateOnly = $ad->finish_validate
                ? Carbon::parse($ad->finish_validate, $tz)->toDateString()
                : null;

            // 🔹 Gabungkan dengan jam harian
            $startTime = $ad->jam_mulai ?: '00:00:00';
            $endTime   = $ad->jam_berakhir ?: '23:59:59';

            $startAt = $startDateOnly
                ? Carbon::parse("{$startDateOnly} {$startTime}", $tz)
                : null;

            $endAt = $endDateOnly
                ? Carbon::parse("{$endDateOnly} {$endTime}", $tz)
                : null;

            // 🧠 Log debug


            // 🔒 Validasi tanggal promo
            if ($startAt && $now->lt($startAt)) {
                return response()->json([
                    'success' => false,
                    'message' => ucfirst($itemType) . ' belum dimulai (tanggal mulai)',
                ], 422);
            }

            // 🕐 Cek jam berlaku harian (gabungkan dengan tanggal hari ini)
            $currentLocal = Carbon::now($tz);
            $todayStr = $currentLocal->toDateString();

            // normalisasi "HH:mm" -> "HH:mm:ss"
            $norm = function ($t) {
                if (!$t) return '00:00:00';
                if (preg_match('/^\d{1,2}:\d{2}$/', $t)) return $t . ':00';
                if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $t)) return $t;
                return '00:00:00';
            };

            $startTime = $norm($ad->jam_mulai ?? '00:00:00');
            $endTime   = $norm($ad->jam_berakhir ?? '23:59:59');

            // 🔥 Tambahkan deteksi lintas hari (misal 22:00 - 03:00)
            $startOfDay = Carbon::parse("{$todayStr} {$startTime}", $tz);
            $endOfDay   = Carbon::parse("{$todayStr} {$endTime}", $tz);
            if ($endOfDay->lt($startOfDay)) {
                // berarti jam_berakhir lewat tengah malam
                $endOfDay->addDay();
            }



            if ($currentLocal->lt($startOfDay)) {
                return response()->json([
                    'success' => false,
                    'message' => ucfirst($itemType) . ' belum dimulai (belum jam berlaku)',
                ], 422);
            }

            if ($currentLocal->gt($endOfDay)) {
                return response()->json([
                    'success' => false,
                    'message' => ucfirst($itemType) . ' sudah berakhir untuk hari ini',
                ], 422);
            }

            // 👥 Validasi target user
            if ($ad->target_type === 'user') {
                $allowedIds = $ad->target_users()->pluck('user_id')->toArray();
                if (!in_array($user->id, $allowedIds)) {
                    return response()->json(['success' => false, 'message' => 'Anda tidak memiliki akses ke ' . $itemType . ' ini'], 403);
                }
            }

            // 👥 Validasi komunitas
            if ($ad->target_type === 'community' && $ad->community_id) {
                $isMember = \App\Models\CommunityMembership::where('user_id', $user->id)
                    ->where('community_id', $ad->community_id)
                    ->exists();
                if (!$isMember) {
                    return response()->json(['success' => false, 'message' => 'Anda bukan member komunitas target'], 403);
                }
            }

            // 📦 Cek stok
            $totalGrab = DB::table('summary_grabs')
                ->where('ad_id', $ad->id)
                ->when($ad->is_daily_grab, fn($q) => $q->whereDate('date', now('Asia/Jakarta')->toDateString()))
                ->sum('total_grab');

            $remaining = $ad->unlimited_grab ? null : ($ad->max_grab - $totalGrab);
            if (!$ad->unlimited_grab && $remaining <= 0) {
                return response()->json(['success' => false, 'message' => ucfirst($itemType) . ' sudah habis'], 422);
            }

            // 📝 Cegah double claim
            $alreadyClaimed = DB::table('summary_grabs')
                ->where('ad_id', $ad->id)
                ->where('user_id', $user->id)
                ->when($ad->is_daily_grab, fn($q) => $q->whereDate('date', now('Asia/Jakarta')->toDateString()))
                ->exists();

            if ($alreadyClaimed) {
                return response()->json(['success' => false, 'message' => 'Anda sudah klaim ' . $itemType . ' ini sebelumnya'], 409);
            }

            // Perform claim inside a DB transaction with locks to avoid races
            DB::beginTransaction();

            // Lock the ad row for update
            $lockedAd = \App\Models\Ad::where('id', $ad->id)->lockForUpdate()->first();

            // Lock related voucher (if any)
            $voucher = null;
            if ($lockedAd && $lockedAd->type === 'voucher') {
                $voucher = Voucher::where('ad_id', $lockedAd->id)->lockForUpdate()->first();
            }

            // Recompute remaining using latest summary_grabs under lock
            $totalGrab = DB::table('summary_grabs')
                ->where('ad_id', $lockedAd->id)
                ->when($lockedAd->is_daily_grab, fn($q) => $q->whereDate('date', now('Asia/Jakarta')->toDateString()))
                ->sum('total_grab');

            $remaining = $lockedAd->unlimited_grab ? null : ($lockedAd->max_grab - $totalGrab);
            if (!$lockedAd->unlimited_grab && $remaining <= 0) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => ucfirst($itemType) . ' sudah habis'], 422);
            }

            // Re-check double-claim under lock
            $alreadyClaimed = DB::table('summary_grabs')
                ->where('ad_id', $lockedAd->id)
                ->where('user_id', $user->id)
                ->when($lockedAd->is_daily_grab, fn($q) => $q->whereDate('date', now('Asia/Jakarta')->toDateString()))
                ->exists();

            if ($alreadyClaimed) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Anda sudah klaim ' . $itemType . ' ini sebelumnya'], 409);
            }

            // Insert summary_grabs now that checks passed
            DB::table('summary_grabs')->insert([
                'ad_id' => $lockedAd->id,
                'user_id' => $user->id,
                'date' => now('Asia/Jakarta')->toDateString(),
                'total_grab' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Handle voucher or promo specifics
            if ($lockedAd->type === 'voucher') {
                if (!$voucher) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Voucher terkait tidak ditemukan'], 422);
                }

                if ($voucher->stock <= 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Voucher sudah habis'], 422);
                }

                // Create voucher item
                $voucherItem = new VoucherItem();
                $voucherItem->user_id = $user->id;
                $voucherItem->voucher_id = $voucher->id;
                $voucherItem->code = $voucherItem->generateCode();
                $voucherItem->used_at = null;
                $voucherItem->save();

                // Decrement voucher stock safely
                $voucher->decrement('stock', 1);


                // Decrement ads.max_grab if applicable (not null and > 0)
                if (!is_null($lockedAd->max_grab)) {
                    $affected = DB::table('ads')
                        ->where('id', $lockedAd->id)
                        ->whereNotNull('max_grab')
                        ->where('max_grab', '>', 0)
                        ->decrement('max_grab', 1);

                    if ($affected) {
                        DB::table('ads')->where('id', $lockedAd->id)->update(['updated_at' => now()]);
                    } else {
                    }
                }
            } else {
                // Promo flow (unchanged) - create promo item via controller
                $promoItemController = new \App\Http\Controllers\Admin\PromoItemController();
                $claimRequest = new \Illuminate\Http\Request([
                    'promo_id' => $lockedAd->id,
                    'user_id' => $user->id,
                    'claim' => true,
                    'expires_at' => $lockedAd->finish_validate,
                ]);
                $claimRequest->setUserResolver(function () use ($user) {
                    return $user;
                });

                $promoResult = $promoItemController->store($claimRequest);
                $promoData = $promoResult->getData(true);

                if (!$promoData['success']) {
                    DB::rollBack();
                    return response()->json($promoData, 422);
                }
            }

            // Mark notification as read if provided
            if ($request->has('_notification_id')) {
                DB::table('notifications')->where('id', $request->input('_notification_id'))->update(['read_at' => now()]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => ucfirst($itemType) . ' berhasil diklaim!',
                'data' => [
                    'ad_id' => $lockedAd->id,
                    'title' => $lockedAd->title,
                    'type' => $itemType,
                    'remaining' => $remaining !== null ? max(0, $remaining - 1) : null,
                    'expired_at' => $lockedAd->finish_validate,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server'], 500);
        }
    }
}
