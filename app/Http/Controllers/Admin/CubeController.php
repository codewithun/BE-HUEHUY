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
use App\Models\Promo;
use App\Models\PromoValidation;
use App\Models\Voucher;
use App\Models\VoucherItem;
use App\Models\Notification;
use App\Models\User;
use App\Models\CommunityMembership;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

/**
 * Local no-op Log class to neutralize logging calls inside this controller.
 *
 * Instead of removing each Log::... call (many occurrences), we define a small
 * class in this namespace with the same static methods used in the file.
 * This keeps the code unchanged except logs become no-ops.
 */
class Log
{
    public static function info(...$args)
    {
        // intentionally empty (no-op)
    }

    public static function error(...$args)
    {
        // intentionally empty (no-op)
    }

    public static function warning(...$args)
    {
        // intentionally empty (no-op)
    }

    public static function debug(...$args)
    {
        // intentionally empty (no-op)
    }

    public static function critical(...$args)
    {
        // intentionally empty (no-op)
    }

    public static function alert(...$args)
    {
        // intentionally empty (no-op)
    }

    public static function notice(...$args)
    {
        // intentionally empty (no-op)
    }
}

class CubeController extends Controller
{
    /**
     * Helper function to convert empty strings to null
     */
    private function nullIfEmpty($value)
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        return $value;
    }

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
    // ## Helper Methods for Voucher & Promo Sync
    // ========================================>

    /**
     * Handle promo sync untuk create/update ads dari kubus
     */
    private function handlePromoSyncFromCube(Request $request, Ad $ad, $isUpdate = false)
    {
        // Cek apakah ini adalah content promo
        if ($request->input('content_type') !== 'promo') {
            return;
        }



        try {
            if ($isUpdate) {
                // Update promo yang terkait dengan ad
                $this->updatePromoFromAd($ad, $request);
            } else {
                // Create promo baru dari ad
                $this->createPromoFromAd($ad, $request);
            }
        } catch (\Throwable $e) {

            // Don't throw error to prevent cube creation failure
            // Just log the error
        }
    }

    /**
     * Create promo dari ad data
     */
    private function createPromoFromAd(Ad $ad, Request $request)
    {
        // Handle kode promo berdasarkan validation_type
        $validationType = $ad->validation_type ?? 'auto';
        $promoCode = '';

        if ($validationType === 'manual') {
            // Untuk manual, WAJIB gunakan kode yang diinputkan admin
            $promoCode = $ad->code;
        } elseif ($validationType === 'auto') {
            // Untuk auto, gunakan kode admin jika ada, jika tidak baru generate
            if (!empty($ad->code)) {
                $promoCode = $ad->code; // Gunakan kode admin

            } else {
                // Generate kode dengan format yang konsisten
                do {
                    $promoCode = 'PRM-' . strtoupper(bin2hex(random_bytes(3)));
                } while (
                    \App\Models\Promo::where('code', $promoCode)->exists() ||
                    \App\Models\PromoItem::where('code', $promoCode)->exists() ||
                    \App\Models\Ad::where('code', $promoCode)->exists()
                );

                // Update ads dengan kode yang sama
                $ad->update(['code' => $promoCode]);
            }
        }

        // Siapkan data promo dari ad
        $promoData = [
            'title' => $ad->title,
            'description' => $ad->description,
            'detail' => $ad->detail ?? $request->input('promo_detail'), // Prioritas ambil dari ad.detail, fallback ke form
            'code' => $promoCode, // Gunakan kode yang sudah ditentukan di atas
            'promo_type' => $ad->promo_type ?? 'offline',
            'validation_type' => $validationType,
            'start_date' => $ad->start_validate,
            'end_date' => $ad->finish_validate,
            'stock' => $ad->unlimited_grab ? null : ($ad->max_grab ?: 0),
            'always_available' => $request->boolean('promo_always_available', $ad->unlimited_grab ? true : false),
            'location' => $request->input('promo_location') ?: $request->input('address') ?: $request->input('cube_tags.0.address'),
            'promo_distance' => $request->input('promo_distance', 0),
            'online_store_link' => $ad->online_store_link ?? null, // ✅ Sinkronkan online_store_link dari ad
        ];

        // Handle owner info dari cube/ad atau dari form
        $ownerName = $request->input('promo_owner_name');
        $ownerContact = $request->input('promo_owner_contact');

        if (!$ownerName || !$ownerContact) {
            // Fallback ke data cube/user
            if ($ad->cube && $ad->cube->user) {
                $promoData['owner_name'] = $ownerName ?: $ad->cube->user->name;
                $promoData['owner_contact'] = $ownerContact ?: ($ad->cube->user->phone ?? $ad->cube->user->email);
            }
        } else {
            $promoData['owner_name'] = $ownerName;
            $promoData['owner_contact'] = $ownerContact;
        }

        // Handle image dari ad (prioritas: image_1, image_2, image_3, image)
        $imageFields = ['image_1', 'image_2', 'image_3', 'image'];
        foreach ($imageFields as $field) {
            if (!empty($ad->{$field})) {
                $promoData['image'] = $ad->{$field};
                $promoData['image_updated_at'] = now();
                break;
            }
        }

        // Handle community_id jika ada
        if ($ad->community_id) {
            $promoData['community_id'] = $ad->community_id;
        }

        // Filter out null values untuk fields yang tidak wajib
        $promoData = array_filter($promoData, function ($value) {
            return $value !== null && $value !== '';
        });



        // Create promo
        $promo = Promo::create($promoData);



        // Create promo validation entry
        $this->createPromoValidationEntry($promo, $ad);

        return $promo;
    }

    /**
     * Update promo dari ad data
     */
    private function updatePromoFromAd(Ad $ad, Request $request)
    {
        // Cari promo berdasarkan kode atau title (bisa disesuaikan logic pencarian)
        $promo = Promo::where('code', $ad->code)
            ->orWhere(function ($query) use ($ad) {
                $query->where('title', $ad->title)
                    ->where('promo_type', $ad->promo_type);
            })
            ->first();

        if (!$promo) {
            // Jika promo belum ada, buat baru
            return $this->createPromoFromAd($ad, $request);
        }

        // Handle kode promo update berdasarkan validation_type
        $validationType = $ad->validation_type ?? 'auto';

        // Update data promo
        $updateData = [
            'title' => $ad->title,
            'description' => $ad->description,
            'detail' => $ad->detail ?? $request->input('promo_detail'),
            'promo_type' => $ad->promo_type ?? 'offline',
            'validation_type' => $validationType,
            'start_date' => $ad->start_validate,
            'end_date' => $ad->finish_validate,
            'stock' => $ad->unlimited_grab ? null : ($ad->max_grab ?: 0),
            'always_available' => $request->boolean('promo_always_available', $ad->unlimited_grab ? true : false),
            'location' => $request->input('promo_location') ?: $request->input('address') ?: $request->input('cube_tags.0.address'),
            'promo_distance' => $request->input('promo_distance', $promo->promo_distance ?? 0),
            'online_store_link' => $ad->online_store_link ?? null, // ✅ Sinkronkan online_store_link dari ad
        ];

        // Update kode hanya jika ada perubahan dan sesuai validation_type
        if (!empty($ad->code) && $ad->code !== $promo->code) {
            $updateData['code'] = $ad->code;
        }

        // Handle owner info update
        $ownerName = $request->input('promo_owner_name');
        $ownerContact = $request->input('promo_owner_contact');

        if ($ownerName) {
            $updateData['owner_name'] = $ownerName;
        }
        if ($ownerContact) {
            $updateData['owner_contact'] = $ownerContact;
        }

        // Handle image update dari ad
        $imageFields = ['image_1', 'image_2', 'image_3', 'image'];
        foreach ($imageFields as $field) {
            if (!empty($ad->{$field})) {
                $updateData['image'] = $ad->{$field};
                $updateData['image_updated_at'] = now();
                break;
            }
        }

        // Filter out null values untuk fields yang tidak wajib di update
        $updateData = array_filter($updateData, function ($value) {
            return $value !== null && $value !== '';
        });

        $promo->update($updateData);



        // Update atau create promo validation entry jika belum ada
        $this->updateOrCreatePromoValidationEntry($promo, $ad);

        return $promo;
    }

    /**
     * Create promo validation entry untuk tracking validasi promo
     */
    private function createPromoValidationEntry(Promo $promo, Ad $ad)
    {
        try {
            $validationType = $ad->validation_type ?? 'auto';

            // ✅ PERBAIKAN: Gunakan kode master promo untuk semua validasi
            // Untuk konsistensi, semua entry validation menggunakan kode yang sama dengan promo
            $validationCode = $promo->code;



            // Create promo validation entry
            $promoValidation = PromoValidation::create([
                'promo_id' => $promo->id,
                'code' => $validationCode, // Gunakan kode master yang konsisten
                'user_id' => null, // Null karena belum ada yang validasi
                'validated_at' => null, // Null karena belum divalidasi
                'notes' => "Created from cube ad sync - {$validationType} validation - master_code:{$validationCode}"
            ]);



            return $promoValidation;
        } catch (\Throwable $e) {


            // Jangan throw error, karena promo sudah berhasil dibuat
            // Hanya log error untuk debugging
            return null;
        }
    }

    /**
     * Update atau create promo validation entry untuk promo yang di-update
     */
    private function updateOrCreatePromoValidationEntry(Promo $promo, Ad $ad)
    {
        try {
            $validationType = $ad->validation_type ?? 'auto';

            // Cek apakah sudah ada promo validation untuk promo ini
            $existingValidation = PromoValidation::where('promo_id', $promo->id)->first();

            // Generate validation code berdasarkan tipe validasi
            $validationCode = $promo->code; // Gunakan promo code sebagai validation code

            if ($existingValidation) {
                // Update existing validation entry
                $existingValidation->update([
                    'code' => $validationCode,
                    'notes' => "Updated from cube ad sync - {$validationType} validation"
                ]);



                return $existingValidation;
            } else {
                // Create new validation entry jika belum ada
                return $this->createPromoValidationEntry($promo, $ad);
            }
        } catch (\Throwable $e) {


            // Jangan throw error, karena promo sudah berhasil di-update
            return null;
        }
    }

    /**
     * Handle voucher sync untuk create/update ads dari kubus
     */
    private function handleVoucherSyncFromCube(Request $request, Ad $ad, $isUpdate = false)
    {
        // ✅ PERBAIKAN: Cek flag sync voucher dari frontend dengan validasi ketat
        if (
            !$request->input('_sync_to_voucher_management') ||
            !$request->has('_voucher_sync_data') ||
            $request->input('content_type') !== 'voucher'
        ) {
            // Log untuk debugging jika ada attempt sync non-voucher
            if ($request->input('_sync_to_voucher_management') && $request->input('content_type') !== 'voucher') {
            }
            return;
        }

        // ✅ PERBAIKAN: Handle jika _voucher_sync_data adalah JSON string
        $voucherSyncData = $request->input('_voucher_sync_data', []);

        if (is_string($voucherSyncData)) {
            $voucherSyncData = json_decode($voucherSyncData, true) ?? [];
        }



        try {
            if ($isUpdate) {
                // Update voucher yang terkait dengan ad_id
                $voucher = Voucher::where('ad_id', $ad->id)->first();

                if ($voucher) {
                    $this->updateVoucherFromSyncDataCube($voucher, $voucherSyncData);
                } else {
                    // Buat voucher baru jika belum ada
                    $this->createVoucherFromSyncDataCube($ad, $voucherSyncData);
                }
            } else {
                // Create voucher baru
                $this->createVoucherFromSyncDataCube($ad, $voucherSyncData);
            }
        } catch (\Throwable $e) {

            throw $e;
        }
    }

    /**
     * Create voucher dari sync data (dari kubus)
     */
    private function createVoucherFromSyncDataCube(Ad $ad, array $voucherSyncData)
    {
        // Set ad_id setelah ads dibuat
        $voucherSyncData['ad_id'] = $ad->id;

        // Resolve owner info jika perlu
        $voucherSyncData = $this->resolveOwnerInfoCube($voucherSyncData);

        // ✅ PERBAIKAN: Handle kode berdasarkan validation_type
        $validationType = $voucherSyncData['validation_type'] ?? 'auto';

        if ($validationType === 'manual') {
            // Untuk manual, gunakan kode yang sama dengan ads (user input)
            $voucherSyncData['code'] = $ad->code;
        } elseif ($validationType === 'auto' && empty($voucherSyncData['code'])) {
            // Untuk auto, generate kode baru jika belum ada
            $voucherSyncData['code'] = 'VCR-' . strtoupper(Str::random(8));
            Log::info('CubeController voucher sync generating auto code', [
                'generated_code' => $voucherSyncData['code'],
                'validation_type' => $validationType
            ]);
        }

        // ✅ PERBAIKAN: Handle image copy dari ads sebelum exclude fields
        if (
            isset($voucherSyncData['_copy_image_from_ads']) &&
            $voucherSyncData['_copy_image_from_ads'] === true &&
            isset($voucherSyncData['_image_source_field'])
        ) {
            $sourceField = $voucherSyncData['_image_source_field']; // contoh: "image_1"
            $adImagePath = $ad->{$sourceField} ?? null; // ambil dari ad->image_1

            if ($adImagePath) {
                // Copy path image dari ad ke voucher
                $voucherSyncData['image'] = $adImagePath;
                $voucherSyncData['image_updated_at'] = now();

                Log::info('CubeController copying image from ad to voucher', [
                    'source_field' => $sourceField,
                    'ad_image_path' => $adImagePath,
                    'voucher_image_path' => $adImagePath
                ]);
            }
        }

        // ✅ PERBAIKAN: Hapus field yang tidak ada di tabel vouchers dan tidak diperlukan
        $excludedFields = [
            'owner_user_id',
            'cube_id',
            'target_user_ids', // bukan field tabel
            'created_at',
            'updated_at', // sistem field, auto-managed
            '_copy_image_from_ads',
            '_image_source_field',
            '_debug_source',
            '_frontend_version' // frontend debug field
        ];

        foreach ($excludedFields as $field) {
            unset($voucherSyncData[$field]);
        }

        // Pastikan required fields ada
        $voucherSyncData['type'] = $voucherSyncData['type'] ?? 'voucher';
        $voucherSyncData['validation_type'] = $validationType;

        Log::info('CubeController creating voucher with data', [
            'voucher_data' => $voucherSyncData,
            'ad_id' => $ad->id
        ]);

        // Create voucher
        $voucher = Voucher::create($voucherSyncData);

        Log::info('✅ Voucher created successfully', [
            'voucher_id' => $voucher->id,
            'voucher_code' => $voucher->code,
            'ad_id' => $ad->id
        ]);

        return $voucher;
    }

    /**
     * Update voucher dari sync data (dari kubus)
     */
    private function updateVoucherFromSyncDataCube(Voucher $voucher, array $voucherSyncData)
    {
        // Resolve owner info jika perlu
        $voucherSyncData = $this->resolveOwnerInfoCube($voucherSyncData);

        // ✅ PERBAIKAN: Handle code update ketika validation_type berubah
        $validationType = $voucherSyncData['validation_type'] ?? $voucher->validation_type;

        if ($validationType === 'manual' && isset($voucherSyncData['code']) && !empty($voucherSyncData['code'])) {
            // Untuk manual validation, gunakan kode dari input admin
            $voucherSyncData['code'] = $voucherSyncData['code'];

            Log::info('✅ CubeController: Updating voucher with MANUAL code from admin input', [
                'voucher_id' => $voucher->id,
                'old_code' => $voucher->code,
                'new_code' => $voucherSyncData['code'],
                'validation_type' => $validationType
            ]);
        } elseif ($validationType === 'auto' && isset($voucherSyncData['code'])) {
            // Untuk auto validation, tetap gunakan generated code atau yang sudah ada
            Log::info('CubeController: Keeping existing code for AUTO validation', [
                'voucher_id' => $voucher->id,
                'existing_code' => $voucher->code,
                'validation_type' => $validationType
            ]);
            // Hapus code dari update data agar tidak ter-override
            unset($voucherSyncData['code']);
        }

        // Hapus field yang tidak ada di tabel vouchers atau tidak boleh diupdate
        unset($voucherSyncData['owner_user_id'], $voucherSyncData['ad_id'], $voucherSyncData['cube_id']);

        // Update voucher
        $voucher->update($voucherSyncData);

        Log::info('✅ CubeController: Voucher updated successfully', [
            'voucher_id' => $voucher->id,
            'updated_code' => $voucher->fresh()->code
        ]);
    }

    /**
     * Resolve owner info dari owner_user_id jika ada (dari kubus)
     */
    private function resolveOwnerInfoCube(array $voucherSyncData)
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
                // NOTE: Untuk promo harian (is_daily_grab = 1):
                // - Stok di-reset otomatis setiap hari (filter WHERE date = DATE(NOW()))
                // - Admin set max_grab = jumlah stok per hari
                // - Sistem hanya hitung grab untuk hari ini, besok akan reset otomatis
                // - Sampai finish_validate tercapai, setiap hari akan ada max_grab stok baru
                return $query->select([
                    'ads.*',
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
                ])
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
            'content_type' => $request->input('content_type'),
            '_sync_to_voucher_management' => $request->input('_sync_to_voucher_management'),
            'ads_code' => $request->input('ads.code'),
            'root_code' => $request->input('code'),
            'all_request' => $request->all()
        ]);

        // ✨ PERBAIKAN: Handle voucher sync validation BEFORE validation
        if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
            $voucherSyncData = $request->input('_voucher_sync_data', []);

            // Handle jika _voucher_sync_data adalah JSON string
            if (is_string($voucherSyncData)) {
                $voucherSyncData = json_decode($voucherSyncData, true) ?? [];
            }

            $validationType = $voucherSyncData['validation_type'] ?? 'auto';

            Log::info('CubeController@store voucher sync detected', [
                'validation_type' => $validationType,
                'ads_code' => $request->input('ads.code'),
                'voucher_code_from_sync' => $voucherSyncData['code'] ?? null,
                'generated_code' => $request->input('code')
            ]);

            // Hanya remove ads.code jika validation_type adalah auto
            // Untuk manual, preserve kode dari user input
            if ($validationType === 'auto') {
                Log::info('CubeController@store removing ads.code for auto validation type');

                $requestData = $request->all();

                // Handle jika ads adalah JSON string
                if (isset($requestData['ads']) && is_string($requestData['ads'])) {
                    $adsData = json_decode($requestData['ads'], true);
                    if ($adsData && isset($adsData['code'])) {
                        unset($adsData['code']);
                        $requestData['ads'] = json_encode($adsData);
                    }
                }
                // Handle jika ads adalah array
                elseif (isset($requestData['ads']) && is_array($requestData['ads']) && isset($requestData['ads']['code'])) {
                    unset($requestData['ads']['code']);
                }

                // Remove ads.code dari flat structure juga untuk auto
                if (isset($requestData['ads.code'])) {
                    unset($requestData['ads.code']);
                }

                // Remove ads.validation_type untuk auto validation
                if (isset($requestData['ads.validation_type'])) {
                    unset($requestData['ads.validation_type']);
                }

                $request->replace($requestData);

                Log::info('CubeController@store code conflict resolved for auto validation');
            } else {
                Log::info('CubeController@store preserving manual code for manual validation', [
                    'manual_code_from_voucher_sync' => $voucherSyncData['code'] ?? null,
                    'manual_code_from_ads' => $request->input('ads.code'),
                    'validation_type' => $validationType
                ]);

                // Untuk manual validation, pastikan kode dari voucher sync data tersedia di ads
                $requestData = $request->all();

                // Jika ada kode dari voucher sync data, gunakan itu untuk ads
                if (!empty($voucherSyncData['code'])) {
                    $requestData['ads.code'] = $voucherSyncData['code'];
                    $request->replace($requestData);

                    Log::info('CubeController@store using voucher sync manual code', [
                        'voucher_sync_code' => $voucherSyncData['code']
                    ]);
                }
            }
        }

        // ? Validate request
        $request->merge([
            'owner_user_id' => $request->filled('owner_user_id') ? $request->owner_user_id : null,
            'user_id'       => $request->filled('user_id')       ? $request->user_id       : null,
            'corporate_id'  => $request->filled('corporate_id')  ? $request->corporate_id  : null,
        ]);
        // Skip validation untuk manager tenant jika kubus informasi
        $isInformation = $request->boolean('is_information');

        // Base validation rules
        $rules = [
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
            'ads.description'            => 'nullable|string',
            'ads.detail'                 => 'nullable|string',
            'ads.max_grab'               => 'nullable|numeric',
            'ads.unlimited_grab'         => 'nullable|boolean',
            'ads.is_daily_grab'          => 'nullable|boolean',
            'ads.status'                 => 'nullable|string',
            // promo_type: akan diwajibkan jika content_type === 'promo' (iklan/promo biasa),
            // sedangkan untuk voucher boleh kosong dan akan di-default-kan ke 'offline' di bawah.
            'ads.promo_type'             => ['nullable', 'string', Rule::in(['offline', 'online'])],
            'ads.validation_type'        => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'ads.code'                   => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    // Skip unique validation if voucher sync is active (will use generated code)
                    if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
                        return;
                    }

                    // Check uniqueness for manual codes only
                    if ($value && Ad::where('code', $value)->exists()) {
                        $fail('The ads.code has already been taken.');
                    }
                },
                // ✅ PERBAIKAN: Custom required validation yang aware voucher sync
                function ($attribute, $value, $fail) use ($request) {
                    // Skip required validation jika voucher sync aktif
                    if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
                        return;
                    }

                    // Required hanya untuk validation_type manual (non-voucher sync)
                    $validationType = $request->input('ads.validation_type');
                    if ($validationType === 'manual' && empty($value)) {
                        $fail('The ads.code field is required when ads.validation_type is manual.');
                    }
                }
            ],
            'code'                       => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    // Only validate uniqueness for voucher sync codes
                    if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
                        if ($value && Ad::where('code', $value)->exists()) {
                            $fail('The generated voucher code has already been taken.');
                        }
                    }
                }
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
            'ads[image]'                 => 'nullable',
            'ads[image_1]'               => 'nullable',
            'ads[image_2]'               => 'nullable',
            'ads[image_3]'               => 'nullable',
            'ads_image'                  => 'nullable',
            'ads_image_1'                => 'nullable',
            'ads_image_2'                => 'nullable',
            'ads_image_3'                => 'nullable',
            'image_1'                    => 'nullable',
            'image_2'                    => 'nullable',
            'image_3'                    => 'nullable',
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
        ];

        // Conditionally require promo_type only for content_type === 'promo'
        $contentTypeForValidation = $request->input('content_type', 'promo');
        if ($contentTypeForValidation === 'promo') {
            $rules['ads.promo_type'] = ['required', 'string', Rule::in(['offline', 'online'])];
        }

        $validation = $this->validation($request->all(), $rules);
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
            'cube_type_name' => $cubeType->name,
            'content_type' => $request->input('content_type'),
            '_sync_to_voucher_management' => $request->input('_sync_to_voucher_management'),
            'voucher_sync_analysis' => [
                'has_sync_flag' => $request->has('_sync_to_voucher_management'),
                'sync_flag_value' => $request->input('_sync_to_voucher_management'),
                'content_type_value' => $request->input('content_type'),
                'should_sync_voucher' => $request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher',
                'will_trigger_legacy_voucher' => [
                    'by_ad_type_check' => 'will_be_checked_after_ad_creation',
                    'by_promo_type_check' => 'will_be_checked_after_ad_creation'
                ]
            ]
        ]);

        // ? Initial
        DB::beginTransaction();
        $model = new Cube();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        // * Handle link_information field explicitly
        if ($request->has('link_information')) {
            $model->link_information = $request->input('link_information');
        }

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

                    // Simpan link dari cube_tags[0] ke field link_information di Cube
                    $firstTag = $cubeTags[0] ?? null;

                    // 🔍 DEBUG: Log semua cube_tags yang diterima
                    Log::info('🔍 CubeController@store received cube_tags', [
                        'cube_id' => $model->id,
                        'cube_tags_raw' => $request->input('cube_tags'),
                        'first_tag' => $firstTag,
                        'first_tag_link' => $firstTag['link'] ?? 'NOT_SET'
                    ]);

                    if ($firstTag && !empty($firstTag['link'])) {
                        $model->link_information = $firstTag['link'];
                        $model->save();
                        Log::info('✅ CubeController@store saved link_information from cube_tags', [
                            'cube_id' => $model->id,
                            'link_information' => $firstTag['link']
                        ]);
                    } else {
                        Log::warning('⚠️ CubeController@store NO LINK found in cube_tags', [
                            'cube_id' => $model->id,
                            'first_tag' => $firstTag
                        ]);
                    }
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
                $ad->detail                  = $adsPayload['detail'] ?? null;
                $ad->max_grab                = $adsPayload['max_grab'] ?? null;
                $ad->unlimited_grab          = ($adsPayload['unlimited_grab'] ?? $request->input('ads.unlimited_grab') ?? $request->input('unlimited_grab') ?? false) == 1;
                $ad->is_daily_grab           = ($adsPayload['is_daily_grab'] ?? false) == 1;
                $ad->promo_type              = $adsPayload['promo_type'] ?? null;

                // ✅ Handle online_store_link from cube_tags[0][link] or direct input
                if (!empty($adsPayload['online_store_link'])) {
                    $ad->online_store_link = $adsPayload['online_store_link'];
                } elseif (!empty($model->link_information)) {
                    // Fallback: ambil dari link_information yang sudah di-set dari cube_tags
                    $ad->online_store_link = $model->link_information;
                }

                // New validation fields
                $ad->validation_type         = $adsPayload['validation_type'] ?? 'auto';

                // ✅ PERBAIKAN: Handle code berdasarkan validation_type
                if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
                    $voucherSyncData = $request->input('_voucher_sync_data', []);

                    // Handle jika _voucher_sync_data adalah JSON string
                    if (is_string($voucherSyncData)) {
                        $voucherSyncData = json_decode($voucherSyncData, true) ?? [];
                    }
                    $validationType = $voucherSyncData['validation_type'] ?? $ad->validation_type ?? 'auto';

                    if ($validationType === 'manual') {
                        // ✅ PERBAIKAN KRITIS: Untuk manual, ambil kode dari _voucher_sync_data TERLEBIH DAHULU
                        // karena frontend mengirim kode user di sana, bukan di ads.code
                        $candidateCode = $voucherSyncData['code'] ?? $adsPayload['code'] ?? $request->input('code') ?? null;

                        // Log detail untuk debugging frontend
                        Log::info('🔍 CubeController@store validation_type=manual, checking code sources:', [
                            'voucher_sync_data_code' => $voucherSyncData['code'] ?? 'NULL',
                            'ads_payload_code' => $adsPayload['code'] ?? 'NULL',
                            'request_code' => $request->input('code') ?? 'NULL',
                            'final_candidate_code' => $candidateCode,
                            'full_voucher_sync_data' => $voucherSyncData,
                            'validation_type' => $validationType
                        ]);

                        // ✅ VALIDASI EXTRA: Jika kode masih pattern auto-generated (KUBUS-xxxx atau VCR-xxxx dengan timestamp)
                        // maka ini bug frontend, kita REJECT dan throw error agar frontend fix
                        if ($candidateCode && preg_match('/^(KUBUS|VCR)-\d{13,}-\d{1,5}$/', $candidateCode)) {
                            Log::error('⛔ CubeController@store REJECTED auto-generated code for MANUAL validation', [
                                'rejected_code' => $candidateCode,
                                'validation_type' => $validationType,
                                'reason' => 'Manual validation requires user-provided code, not auto-generated pattern',
                                'hint_for_frontend_dev' => 'Frontend is sending auto-generated code for manual validation. Check form.helpers.ts or voucher form component. When validation_type is manual, send user input directly without generating code.'
                            ]);

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

                        $ad->code = $candidateCode;

                        Log::info('✅ CubeController@store using MANUAL code from user input', [
                            'voucher_sync_code' => $voucherSyncData['code'] ?? null,
                            'ads_payload_code' => $adsPayload['code'] ?? null,
                            'request_code' => $request->input('code') ?? null,
                            'final_code' => $ad->code,
                            'validation_type' => $validationType
                        ]);
                    } else {
                        // Untuk auto, pakai generated code dari root level atau generate baru
                        $ad->code = $request->input('code') ?? null;
                        Log::info('CubeController@store using AUTO generated code', [
                            'generated_code' => $request->input('code'),
                            'validation_type' => $validationType
                        ]);
                    }
                } else {
                    // Non-voucher sync, pakai manual code dari ads payload
                    $ad->code = $adsPayload['code'] ?? null;
                    Log::info('CubeController@store using non-sync manual code', ['code' => $ad->code]);
                }

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

                $ad->max_production_per_day  = $this->nullIfEmpty($adsPayload['max_production_per_day'] ?? null);
                $ad->sell_per_day            = $this->nullIfEmpty($adsPayload['sell_per_day'] ?? null);
                $ad->level_umkm              = $this->nullIfEmpty($adsPayload['level_umkm'] ?? null);
                $ad->validation_time_limit   = $adsPayload['validation_time_limit'] ?? null;

                // Schedule fields for promo/voucher  
                $ad->jam_mulai               = $adsPayload['jam_mulai'] ?? $request->input('jam_mulai') ?? null;
                $ad->jam_berakhir            = $adsPayload['jam_berakhir'] ?? $request->input('jam_berakhir') ?? null;
                $ad->day_type                = $adsPayload['day_type'] ?? $request->input('day_type') ?? 'custom';

                // Handle custom days from frontend
                $customDays = [];

                // First try from ads payload
                if (!empty($adsPayload['custom_days']) && is_array($adsPayload['custom_days'])) {
                    // ✅ Filter hanya hari yang bernilai true
                    foreach ($adsPayload['custom_days'] as $day => $value) {
                        if (in_array($value, [true, 1, '1', 'true'], true)) {
                            $customDays[$day] = true;
                        }
                    }
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
                        foreach ($topLevelCustomDays as $day => $value) {
                            if (in_array($value, [true, 1, '1', 'true'], true)) {
                                $customDays[$day] = true;
                            }
                        }
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

                // Hardening: default-kan promo_type untuk voucher jika tidak diisi agar tidak trigger error DB NOT NULL
                if ($ad->type === 'voucher' && empty($ad->promo_type)) {
                    $ad->promo_type = 'offline';
                }

                // * Debug log for image files
                Log::info('CubeController@store checking image files', [
                    'has_ads_image' => $request->hasFile('ads.image'),
                    'has_ads_image_bracket' => $request->hasFile('ads[image]'),
                    'has_ads_image_underscore' => $request->hasFile('ads_image'),
                    'has_ads_image_1' => $request->hasFile('ads.image_1'),
                    'has_ads_image_1_bracket' => $request->hasFile('ads[image_1]'),
                    'has_ads_image_1_underscore' => $request->hasFile('ads_image_1'),
                    'has_image_1' => $request->hasFile('image_1'),
                    'has_ads_image_2' => $request->hasFile('ads.image_2'),
                    'has_ads_image_2_bracket' => $request->hasFile('ads[image_2]'),
                    'has_ads_image_2_underscore' => $request->hasFile('ads_image_2'),
                    'has_image_2' => $request->hasFile('image_2'),
                    'has_ads_image_3' => $request->hasFile('ads.image_3'),
                    'has_ads_image_3_bracket' => $request->hasFile('ads[image_3]'),
                    'has_ads_image_3_underscore' => $request->hasFile('ads_image_3'),
                    'has_image_3' => $request->hasFile('image_3'),
                    'all_files' => array_keys($request->allFiles())
                ]);

                // * ads image (field: ads.image, ads[image], ads_image)
                $mainImageFile = null;
                $mainImageSource = '';

                if ($request->hasFile('ads.image')) {
                    $mainImageFile = $request->file('ads.image');
                    $mainImageSource = 'ads.image';
                } elseif ($request->hasFile('ads[image]')) {
                    $mainImageFile = $request->file('ads[image]');
                    $mainImageSource = 'ads[image]';
                } elseif ($request->hasFile('ads_image')) {
                    $mainImageFile = $request->file('ads_image');
                    $mainImageSource = 'ads_image';
                }

                if ($mainImageFile && $mainImageFile->isValid()) {
                    $uploadPath = $this->upload_file($mainImageFile, 'ads');
                    $ad->picture_source = $uploadPath;
                    Log::info('CubeController@store uploaded ads main image', [
                        'file_name' => $mainImageFile->getClientOriginalName(),
                        'upload_path' => $uploadPath,
                        'source_field' => $mainImageSource
                    ]);
                }

                // * ads additional images (support all formats: ads.image_1, ads[image_1], ads_image_1, image_1)
                $imageFields = ['image_1', 'image_2', 'image_3'];
                foreach ($imageFields as $imageField) {
                    $file = null;
                    $sourceField = '';

                    // Check multiple possible field names
                    $possibleKeys = [
                        "ads.{$imageField}",
                        "ads[{$imageField}]",
                        "ads_{$imageField}",
                        $imageField
                    ];

                    foreach ($possibleKeys as $key) {
                        if ($request->hasFile($key)) {
                            $file = $request->file($key);
                            $sourceField = $key;
                            break;
                        }
                    }

                    if ($file && $file->isValid()) {
                        $uploadPath = $this->upload_file($file, 'ads');
                        $ad->{$imageField} = $uploadPath;
                        Log::info("CubeController@store uploaded ads {$imageField}", [
                            'file_name' => $file->getClientOriginalName(),
                            'upload_path' => $uploadPath,
                            'source_field' => $sourceField
                        ]);
                    }
                }

                // Update image timestamp for cache busting only if we have images
                if ($ad->picture_source || $ad->image_1 || $ad->image_2 || $ad->image_3) {
                    $ad->image_updated_at = now();
                }

                $ad->save();

                // =====================================
                // 🔍 DEBUG: Log ad creation details for voucher tracking
                // =====================================
                Log::info('CubeController@store ad created - voucher creation analysis', [
                    'ad_id' => $ad->id,
                    'ad_type' => $ad->type,
                    'ad_promo_type' => $ad->promo_type,
                    'request_content_type' => $request->input('content_type'),
                    'sync_to_voucher_flag' => $request->input('_sync_to_voucher_management'),
                    'voucher_creation_paths' => [
                        'new_sync_method' => $request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher',
                        'legacy_by_ad_type' => $request->input('content_type') === 'voucher' && $ad->type === 'voucher' && $ad->promo_type === 'offline',
                        'legacy_online' => $request->input('content_type') === 'voucher' && $ad->promo_type === 'online',
                    ]
                ]);

                // =====================================
                // ✅ Handle Voucher Sync (PERBAIKAN)
                // =====================================
                if ($request->input('_sync_to_voucher_management') && $request->input('content_type') === 'voucher') {
                    // Gunakan helper method untuk voucher sync lengkap
                    try {
                        $this->handleVoucherSyncFromCube($request, $ad, false);
                        Log::info('✅ Voucher synced successfully from CubeController', [
                            'ad_id' => $ad->id,
                            'cube_id' => $model->id,
                            'title' => $ad->title,
                        ]);
                    } catch (\Throwable $th) {
                        Log::error('❌ Voucher sync failed from CubeController', [
                            'ad_id' => $ad->id,
                            'cube_id' => $model->id,
                            'error' => $th->getMessage(),
                        ]);
                        // Tidak rollback karena ads sudah berhasil dibuat
                    }
                }
                // =====================================
                // ✅ Handle Promo Sync (BARU)
                // =====================================
                elseif ($request->input('content_type') === 'promo') {
                    // Sync promo ke tabel promos
                    try {
                        $this->handlePromoSyncFromCube($request, $ad, false);
                        Log::info('✅ Promo synced successfully from CubeController', [
                            'ad_id' => $ad->id,
                            'cube_id' => $model->id,
                            'title' => $ad->title,
                        ]);
                    } catch (\Throwable $th) {
                        Log::error('❌ Promo sync failed from CubeController', [
                            'ad_id' => $ad->id,
                            'cube_id' => $model->id,
                            'error' => $th->getMessage(),
                        ]);
                        // Tidak rollback karena ads sudah berhasil dibuat
                    }
                }
                // =====================================
                // ✅ Auto-create Voucher (Legacy) - HANYA untuk content_type voucher
                // =====================================
                elseif ($request->input('content_type') === 'voucher' && $ad->type === 'voucher' && $ad->promo_type === 'offline') {
                    Log::info('🔄 CubeController legacy voucher creation triggered', [
                        'trigger' => 'offline_voucher',
                        'ad_id' => $ad->id,
                        'content_type' => $request->input('content_type'),
                        'ad_type' => $ad->type,
                        'ad_promo_type' => $ad->promo_type,
                    ]);
                    try {
                        Voucher::updateOrCreate(
                            ['ad_id' => $ad->id],
                            [
                                'name' => $ad->title,
                                'code' => (new \App\Models\Voucher())->generateVoucherCode(),
                                'stock' => 0,
                                'validation_type' => $ad->validation_type ?? 'auto',
                                'target_type' => $ad->target_type ?? 'all',
                            ]
                        );

                        Log::info('✅ Voucher auto-created from CubeController (legacy)', [
                            'ad_id' => $ad->id,
                            'title' => $ad->title,
                            'content_type' => $request->input('content_type'),
                        ]);
                    } catch (\Throwable $th) {
                        Log::error('❌ Failed to auto-create voucher from CubeController', [
                            'ad_id' => $ad->id,
                            'error' => $th->getMessage(),
                        ]);
                    }
                }
                // =====================================
                // ✅ Legacy Online Voucher Creation - HANYA untuk content_type voucher
                // =====================================
                elseif ($request->input('content_type') === 'voucher' && $ad->promo_type === 'online') {
                    Log::info('🔄 CubeController legacy online voucher creation triggered', [
                        'trigger' => 'online_voucher',
                        'ad_id' => $ad->id,
                        'content_type' => $request->input('content_type'),
                        'ad_promo_type' => $ad->promo_type,
                    ]);
                    try {
                        Voucher::create([
                            'ad_id' => $ad->id,
                            'name'  => $ad->title ?? ('Voucher-' . $ad->id),
                            'code'  => (new Voucher())->generateVoucherCode(),
                        ]);
                        Log::info('✅ Online voucher auto-created from CubeController (legacy)', [
                            'ad_id' => $ad->id,
                            'title' => $ad->title,
                            'content_type' => $request->input('content_type'),
                        ]);
                    } catch (\Throwable $th) {
                        Log::error('❌ Failed to auto-create online voucher from CubeController', [
                            'ad_id' => $ad->id,
                            'error' => $th->getMessage(),
                        ]);
                    }
                }
                // =====================================
                // 🔍 DEBUG: Log when no voucher creation occurs
                // =====================================
                else {
                    Log::info('✅ CubeController NO voucher creation triggered', [
                        'reason' => 'content_type_not_voucher_or_conditions_not_met',
                        'ad_id' => $ad->id,
                        'content_type' => $request->input('content_type'),
                        'ad_type' => $ad->type,
                        'ad_promo_type' => $ad->promo_type,
                        'sync_flag' => $request->input('_sync_to_voucher_management'),
                        'expected_behavior' => 'voucher should NOT be created for promo/iklan content'
                    ]);
                }


                // Kirim notifikasi voucher sesuai target_type
                if ($ad->type === 'voucher') {
                    if ($ad->target_type === 'user' && !empty($adsPayload['target_user_ids'])) {
                        // Target: user tertentu
                        $ad->target_users()->sync($adsPayload['target_user_ids']);
                        $this->sendVoucherNotifications($ad, $adsPayload['target_user_ids']);
                    } elseif ($ad->target_type === 'community' && !empty($ad->community_id)) {
                        // Target: member komunitas tertentu
                        CommunityMembership::where('community_id', $ad->community_id)
                            ->select('user_id')
                            ->chunkById(1000, function ($chunk) use ($ad) {
                                $this->sendVoucherNotifications($ad, $chunk->pluck('user_id')->all());
                            });
                    } elseif ($ad->target_type === 'all') {
                        // Target: semua user (hanya role user).
                        // Catatan: jika aplikasi memiliki mapping role lain, sesuaikan filter ini.
                        User::query()
                            // ->where('role_id', 3) // contoh jika role user = 3
                            // Jika ingin mengecualikan admin saja:
                            ->where(function ($q) {
                                $q->whereNull('role_id')->orWhere('role_id', '!=', 1);
                            })
                            ->select('id')
                            ->chunkById(1000, function ($users) use ($ad) {
                                $this->sendVoucherNotifications($ad, $users->pluck('id')->all());
                            });
                    }
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
        // Tentukan flag berdasarkan request apabila ada, jika tidak gunakan nilai di DB
        $isInformationFlag = $request->has('is_information')
            ? $request->boolean('is_information')
            : (bool) $model->is_information;

        // Cari iklan terbaru untuk fallback promo_type bila tidak dikirim oleh FE
        $latestAd = Ad::where('cube_id', $model->id)->latest()->first();

        // Ambil promo_type (prioritas: ads.promo_type -> promo_type -> dari DB)
        $promoTypeFromRequest = $request->input('ads.promo_type')
            ?? $request->input('promo_type');
        $promoType = $promoTypeFromRequest ?? ($latestAd->promo_type ?? null);

        // Hormati flag update_location (default false = tidak mengubah lokasi)
        $updateLocation = $request->boolean('update_location', false);

        // Longgarkan kewajiban lokasi apabila:
        // - kubus informasi, atau
        // - promo/iklan/voucher online
        $relaxLocation = $isInformationFlag || ($promoType === 'online');

        // Wajibkan lokasi hanya jika tidak relax dan memang ingin update lokasi,
        // atau jika data lokasi di DB masih kosong (untuk menjaga konsistensi data)
        $dbLocationEmpty = (is_null($model->map_lat) || is_null($model->map_lng) || empty($model->address));
        $requireLocation = !$relaxLocation && ($updateLocation || $dbLocationEmpty);

        $addressRule = $requireLocation ? 'required|string|max:255' : 'nullable|string|max:255';
        $mapLatRule  = $requireLocation ? 'required|numeric'       : 'nullable|numeric';
        $mapLngRule  = $requireLocation ? 'required|numeric'       : 'nullable|numeric';

        $validation = $this->validation($request->all(), [
            'cube_type_id' => 'nullable|numeric|exists:cube_types,id',
            'parent_id'    => 'nullable|numeric|exists:cubes,id',
            'user_id'      => 'nullable|numeric|exists:users,id',
            'owner_user_id' => 'nullable|numeric|exists:users,id',
            'corporate_id' => 'nullable|numeric|exists:corporates,id',
            'world_id'     => 'nullable|numeric|exists:worlds,id',
            'color'        => 'nullable|string|max:255',
            'address'      => $addressRule,
            'map_lat'      => $mapLatRule,
            'map_lng'      => $mapLngRule,
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
            'ads.description'            => 'nullable|string',
            'ads.detail'                 => 'nullable|string',
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
            'ads[image]'                 => 'nullable',
            'ads[image_1]'               => 'nullable',
            'ads[image_2]'               => 'nullable',
            'ads[image_3]'               => 'nullable',
            'ads_image'                  => 'nullable',
            'ads_image_1'                => 'nullable',
            'ads_image_2'                => 'nullable',
            'ads_image_3'                => 'nullable',
            'image_1'                    => 'nullable',
            'image_2'                    => 'nullable',
            'image_3'                    => 'nullable',
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

        // * Handle link_information field explicitly
        if ($request->has('link_information')) {
            $model->link_information = $request->input('link_information');
        }

        // * Handle owner_user_id mapping to user_id (hanya jika diisi)
        if ($request->has('owner_user_id')) {
            $model->user_id = $request->owner_user_id ?: null;
            Log::info('CubeController@update owner_user_id processed', [
                'cube_id' => $model->id,
                'owner_user_id' => $request->owner_user_id,
                'mapped_user_id' => $model->user_id
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

                    // Simpan link dari cube_tags[0] ke field link_information di Cube
                    // HANYA update jika cube_tags memiliki link, jangan hapus jika frontend sudah set link_information langsung
                    $firstTag = $cubeTags[0] ?? null;

                    // 🔍 DEBUG: Log semua cube_tags yang diterima saat update
                    Log::info('🔍 CubeController@update received cube_tags', [
                        'cube_id' => $model->id,
                        'cube_tags_raw' => $request->input('cube_tags'),
                        'first_tag' => $firstTag,
                        'first_tag_link' => $firstTag['link'] ?? 'NOT_SET',
                        'current_link_information' => $model->link_information
                    ]);

                    if ($firstTag && isset($firstTag['link'])) {
                        if (!empty($firstTag['link'])) {
                            $model->link_information = $firstTag['link'];
                            $model->save();
                            Log::info('✅ CubeController@update saved link_information from cube_tags', [
                                'cube_id' => $model->id,
                                'link_information' => $firstTag['link']
                            ]);

                            // ✅ UPDATE: Sinkronisasi online_store_link ke semua ads yang terkait dengan cube ini
                            try {
                                $relatedAds = Ad::where('cube_id', $model->id)->get();
                                foreach ($relatedAds as $ad) {
                                    $ad->online_store_link = $firstTag['link'];
                                    $ad->save();

                                    // ✅ Sinkronisasi juga ke promo jika ada
                                    if (!empty($ad->code)) {
                                        Promo::where('code', $ad->code)->update(['online_store_link' => $firstTag['link']]);
                                    }
                                }
                                Log::info('CubeController@update synced online_store_link to related ads & promos', [
                                    'cube_id' => $model->id,
                                    'ads_count' => $relatedAds->count(),
                                    'online_store_link' => $firstTag['link']
                                ]);
                            } catch (\Throwable $e) {
                                Log::error('CubeController@update failed to sync online_store_link to ads', [
                                    'cube_id' => $model->id,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        } elseif ($request->has('cube_tags.0.link')) {
                            // Jika cube_tags[0]['link'] ada tapi kosong (user sengaja menghapus), hapus link_information
                            $model->link_information = null;
                            $model->save();
                            Log::info('CubeController@update cleared link_information from cube_tags', [
                                'cube_id' => $model->id
                            ]);

                            // ✅ UPDATE: Hapus juga online_store_link dari ads terkait
                            try {
                                $relatedAds = Ad::where('cube_id', $model->id)->get();
                                foreach ($relatedAds as $ad) {
                                    $ad->online_store_link = null;
                                    $ad->save();

                                    // ✅ Hapus juga dari promo jika ada
                                    if (!empty($ad->code)) {
                                        Promo::where('code', $ad->code)->update(['online_store_link' => null]);
                                    }
                                }
                                Log::info('CubeController@update cleared online_store_link from related ads & promos', [
                                    'cube_id' => $model->id
                                ]);
                            } catch (\Throwable $e) {
                                Log::error('CubeController@update failed to clear online_store_link from ads', [
                                    'cube_id' => $model->id,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }
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

        $model->load([
            'ads' => function ($q) {
                $q->select('ads.*')->orderByDesc('created_at');
            },
            'ads.ad_category:id,name',
            'tags:id,cube_id,address,map_lat,map_lng,link',
            'user:id,name,phone,picture_source',
            'corporate:id,name,phone,picture_source',
        ]);

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

        // * Remove related vouchers if exist (PERBAIKAN)
        try {
            $relatedAds = Ad::where('cube_id', $id)->get();
            foreach ($relatedAds as $ad) {
                $relatedVoucher = Voucher::where('ad_id', $ad->id)->first();
                if ($relatedVoucher) {
                    // Hapus voucher items dulu
                    VoucherItem::where('voucher_id', $relatedVoucher->id)->delete();
                    // Hapus voucher
                    $relatedVoucher->delete();
                    Log::info('✅ Related voucher deleted successfully from CubeController', [
                        'cube_id' => $id,
                        'ad_id' => $ad->id,
                        'voucher_id' => $relatedVoucher->id
                    ]);
                }
            }
        } catch (\Throwable $th) {
            Log::error('❌ Failed to delete related vouchers from CubeController', [
                'cube_id' => $id,
                'error' => $th->getMessage()
            ]);
            // Lanjutkan proses delete cube meskipun voucher gagal dihapus
        }

        // * Remove related ads and linked promos/promo validations/promo items (fix orphaned promos)
        try {
            $relatedAds = Ad::where('cube_id', $id)->get();
            foreach ($relatedAds as $ad) {
                // Delete promo(s) that were created from this ad by matching on code or title
                try {
                    if (!empty($ad->code)) {
                        $promos = Promo::where('code', $ad->code)->get();
                    } else {
                        $promos = Promo::where('title', $ad->title)
                            ->when($ad->community_id, function ($q) use ($ad) {
                                return $q->where('community_id', $ad->community_id);
                            })
                            ->get();
                    }

                    foreach ($promos as $promo) {
                        // Delete promo items and validations referencing this promo
                        try {
                            \App\Models\PromoItem::where('promo_id', $promo->id)->delete();
                        } catch (\Throwable $e) {
                            Log::warning('Failed to delete PromoItems for promo during cube destroy', ['promo_id' => $promo->id, 'error' => $e->getMessage()]);
                        }

                        try {
                            PromoValidation::where('promo_id', $promo->id)->delete();
                        } catch (\Throwable $e) {
                            Log::warning('Failed to delete PromoValidations for promo during cube destroy', ['promo_id' => $promo->id, 'error' => $e->getMessage()]);
                        }

                        try {
                            $promo->delete();
                            Log::info('✅ Related promo deleted successfully from CubeController', ['cube_id' => $id, 'ad_id' => $ad->id, 'promo_id' => $promo->id]);
                        } catch (\Throwable $e) {
                            Log::warning('Failed to delete promo during cube destroy', ['promo_id' => $promo->id, 'error' => $e->getMessage()]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Error while searching/deleting promos for ad during cube destroy', ['ad_id' => $ad->id, 'error' => $e->getMessage()]);
                }

                // Finally delete the ad itself
                try {
                    // Delete any ad-specific summary or related models if necessary (safely)
                    Ad::where('id', $ad->id)->delete();
                    Log::info('✅ Related ad deleted successfully from CubeController', ['cube_id' => $id, 'ad_id' => $ad->id]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete ad during cube destroy', ['ad_id' => $ad->id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $th) {
            Log::error('❌ Failed to delete related ads/promos from CubeController', [
                'cube_id' => $id,
                'error' => $th->getMessage()
            ]);
            // Lanjutkan proses delete cube meskipun beberapa bagian gagal dihapus
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
                        $ad->detail                 = $adsPayload['detail'] ?? null;
                        $ad->max_grab               = $adsPayload['max_grab'] ?? null;
                        $ad->unlimited_grab         = ($adsPayload['unlimited_grab'] ?? false) == 1;
                        $ad->is_daily_grab          = $adsPayload['is_daily_grab'] ?? null;
                        $ad->promo_type             = $adsPayload['promo_type'] ?? null;
                        $ad->max_production_per_day = $this->nullIfEmpty($adsPayload['max_production_per_day'] ?? null);
                        $ad->sell_per_day           = $this->nullIfEmpty($adsPayload['sell_per_day'] ?? null);
                        $ad->level_umkm             = $this->nullIfEmpty($adsPayload['level_umkm'] ?? null);

                        // ✅ Handle online_store_link from cube_tags[0][link] or direct input
                        if (!empty($adsPayload['online_store_link'])) {
                            $ad->online_store_link = $adsPayload['online_store_link'];
                        } elseif (!empty($model->link_information)) {
                            // Fallback: ambil dari link_information yang sudah di-set dari cube_tags
                            $ad->online_store_link = $model->link_information;
                        }

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
                            // ✅ Filter hanya hari yang bernilai true
                            foreach ($adsPayload['custom_days'] as $day => $value) {
                                if (in_array($value, [true, 1, '1', 'true'], true)) {
                                    $customDays[$day] = true;
                                }
                            }
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
                    'target_type' => 'ad',
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

            // Jika tipe validasi manual, pastikan kode cocok
            if ($ad->validation_type === 'manual') {
                if (!hash_equals((string)($ad->code ?? ''), (string)$data['code'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Kode tidak valid'
                    ], 422);
                }
            }

            // Cek validitas tanggal
            $now = now();
            if ($ad->start_validate && $now->lt(Carbon::parse($ad->start_validate))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo/voucher belum dimulai'
                ], 422);
            }

            if ($ad->finish_validate && $now->gt(Carbon::parse($ad->finish_validate))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo/voucher sudah berakhir'
                ], 422);
            }

            // Cek jam validasi (batas waktu per hari)
            if ($ad->validation_time_limit) {
                $currentTime = $now->format('H:i:s');
                $limit = strlen($ad->validation_time_limit) === 5
                    ? $ad->validation_time_limit . ':00'
                    : $ad->validation_time_limit;
                if ($currentTime > $limit) {
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

    // GET /api/admin/cubes/{id}
    public function show(string $id)
    {
        try {
            $cube = \App\Models\Cube::with([
                'user:id,name,phone',
                'corporate:id,name,phone',
                'tags:id,cube_id,address,map_lat,map_lng,link',
                'ads' => function ($q) {
                    $q->select('ads.*')->orderByDesc('created_at');
                },
                'ads.ad_category:id,name',
            ])->find($id);

            if (!$cube) {
                return response(['message' => 'not found'], 404);
            }

            return response(['message' => 'success', 'data' => $cube], 200);
        } catch (\Throwable $e) {
            Log::error('CubeController@show failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response(['message' => 'Error: server side having problem!'], 500);
        }
    }

    // GET /api/admin/communities/{communityId}/cubes
    public function cubesByCommunity($communityId)
    {
        try {
            $cubes = Cube::whereHas('ads', function ($q) use ($communityId) {
                $q->where('community_id', $communityId);
            })
                ->with([
                    'cube_type:id,name',
                    'user:id,name',
                    'corporate:id,name',
                    'ads' => function ($q) use ($communityId) {
                        $q->where('community_id', $communityId)
                            ->select('id', 'cube_id', 'title', 'community_id', 'promo_type', 'type', 'status');
                    },
                ])
                ->select('id', 'code', 'color', 'status', 'created_at', 'user_id', 'corporate_id')
                ->get();

            if ($cubes->isEmpty()) {
                return response()->json([
                    'message' => 'empty data',
                    'data' => [],
                ]);
            }

            return response()->json([
                'message' => 'success',
                'data' => $cubes,
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal mengambil kubus komunitas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'server error'], 500);
        }
    }
}
