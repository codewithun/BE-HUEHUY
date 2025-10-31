<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\PromoItem;
use App\Models\PromoValidation;
use App\Models\Community;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class PromoController extends Controller
{

    private function normalizePhone(?string $raw): ?string
    {
        if (!$raw) return null;
        $d = preg_replace('/\D+/', '', $raw);
        if ($d === '') return null;

        $d = preg_replace('/^(?:0|62)/', '', $d);
        return $d;
    }

    private function resolveTenantUserIdByPromo(Promo $promo): ?int
    {
        try {
            if (!empty($promo->owner_user_id)) {
                return (int) $promo->owner_user_id;
            }

            $ownerPhone = $this->normalizePhone($promo->owner_contact ?? null);
            $allUserCols = \Illuminate\Support\Facades\Schema::getColumnListing('users') ?? [];

            $phoneCols = array_values(array_intersect(
                $allUserCols,
                ['phone', 'telp', 'telpon', 'mobile', 'contact', 'whatsapp', 'wa']
            ));

            if ($ownerPhone && !empty($phoneCols)) {
                $u = \App\Models\User::query()
                    ->where(function ($q) use ($phoneCols, $ownerPhone) {
                        foreach ($phoneCols as $col) {
                            $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(IFNULL($col,''),'+',''),' ',''),'-',''),'.','') LIKE ?", ["%$ownerPhone"]);
                        }
                    })
                    ->first();

                if ($u) {
                    $phones = [];
                    foreach ($phoneCols as $col) {
                        $v = $u->$col ?? null;
                        if ($v) $phones[] = preg_replace('/\D+/', '', $v);
                    }
                    $phones = array_filter($phones);
                    foreach ($phones as $digits) {

                        $d = preg_replace('/^(?:0|62)/', '', $digits);
                        if ($d === $ownerPhone) return $u->id;
                    }
                }
            }

            $ownerName = trim((string)($promo->owner_name ?? ''));
            $nameCols = array_values(array_intersect(
                $allUserCols,
                ['name', 'full_name', 'username', 'display_name', 'company_name']
            ));
            if ($ownerName !== '' && !empty($nameCols)) {
                $u = \App\Models\User::query()
                    ->where(function ($q) use ($ownerName, $nameCols) {
                        foreach ($nameCols as $col) {
                            $q->orWhere($col, $ownerName);
                        }
                    })
                    ->first();
                if ($u) return $u->id;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('resolveTenantUserIdByPromo failed: ' . $e->getMessage(), [
                'promo_id' => $promo->id ?? null,
            ]);
            return null;
        }
    }

    /**
     * Transform promo image URLs
     */
    private function transformPromoImageUrls($promo)
    {

        $promo->append(['image_url', 'image_url_versioned']);
        return $promo;
    }

    /** Helper pilih nilai pertama yang tidak kosong */
    private function pick(...$candidates)
    {
        foreach ($candidates as $v) {
            if (isset($v) && trim((string)$v) !== '') return $v;
        }
        return null;
    }

    /**
     * Generate consistent promo code yang digunakan di semua tabel terkait
     * Format: PRM-XXXXXX untuk auto validation, atau kode manual yang diinput admin
     */
    private function generateConsistentPromoCode(?string $validationType = 'auto', ?string $manualCode = null): string
    {
        $validationType = strtolower($validationType ?? 'auto');

        if ($validationType === 'manual' && !empty($manualCode)) {

            return $manualCode;
        }


        do {
            $code = 'PRM-' . strtoupper(bin2hex(random_bytes(3)));
        } while (
            Promo::where('code', $code)->exists() ||
            \App\Models\PromoItem::where('code', $code)->exists() ||
            \App\Models\Ad::where('code', $code)->exists()
        );

        return $code;
    }

    /**
     * Sync code ke semua tabel terkait (ads, promos, promo_items, promo_validations)
     * untuk memastikan konsistensi
     */
    private function syncCodeToRelatedTables(Promo $promo, string $code): void
    {
        try {

            if (isset($promo->cube_id)) {
                \App\Models\Ad::where('cube_id', $promo->cube_id)
                    ->where(function ($q) use ($promo) {
                        $q->where('title', $promo->title)
                            ->orWhere('id', $promo->ad_id ?? 0);
                    })
                    ->update(['code' => $code]);
            }


            \App\Models\PromoItem::where('promo_id', $promo->id)
                ->update(['code' => $code]);

            Log::info('Synced promo code to related tables', [
                'promo_id' => $promo->id,
                'code' => $code
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync code to related tables', [
                'promo_id' => $promo->id,
                'code' => $code,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Ambil nama & telepon dari berbagai kemungkinan kolom user dengan AMAN.
     * Tidak pernah akses $user->profile / $user->meta sebagai properti langsung.
     */
    private function extractUserNamePhone(?User $user): array
    {
        if (!$user) return [null, null];

        $name = $this->pick(
            $user->name ?? null,
            $user->full_name ?? null,
            $user->username ?? null,
            $user->display_name ?? null,
            $user->company_name ?? null
        );

        $phone = $this->pick(
            $user->phone ?? null,
            $user->phone_number ?? null,
            $user->telp ?? null,
            $user->telpon ?? null,
            $user->mobile ?? null,
            $user->contact ?? null,
            $user->whatsapp ?? null,
            $user->wa ?? null
        );


        if (method_exists($user, 'profile')) {
            $profile = $user->profile()->first();
            if ($profile) {
                $name = $name ?: $this->pick(
                    $profile->name ?? null,
                    $profile->full_name ?? null,
                    $profile->display_name ?? null
                );
                $phone = $phone ?: $this->pick(
                    $profile->phone ?? null,
                    $profile->phone_number ?? null,
                    $profile->telp ?? null,
                    $profile->telpon ?? null,
                    $profile->mobile ?? null,
                    $profile->whatsapp ?? null
                );
            }
        }


        if (method_exists($user, 'meta')) {
            $meta = $user->meta()->first();
            if ($meta) {
                $phone = $phone ?: $this->pick(
                    $meta->phone ?? null,
                    $meta->phone_number ?? null,
                    $meta->contact ?? null,
                    $meta->whatsapp ?? null
                );
            }
        }

        return [$name, $phone ? trim((string)$phone) : null];
    }

    /**
     * Parse a human-friendly time limit string into seconds.
     * Examples supported: "01:30" (HH:mm), "2:30:00" (HH:mm:ss), "90m", "2h", "3600s", or plain number = minutes.
     */
    private function parseTimeLimitToSeconds($raw): int
    {
        if ($raw === null) return 0;
        $s = strtolower(trim((string)$raw));
        if ($s === '') return 0;

        // HH:mm[:ss]
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            $sec = isset($m[3]) ? (int)$m[3] : 0;
            return max(0, $h * 3600 + $min * 60 + $sec);
        }

        // 90m, 2h, 3600s
        if (preg_match('/^(\d+(?:\.\d+)?)([smh])$/', $s, $m)) {
            $v = (float)$m[1];
            $u = $m[2];
            if ($u === 's') return (int)round($v);
            if ($u === 'm') return (int)round($v * 60);
            if ($u === 'h') return (int)round($v * 3600);
        }

        // plain number -> minutes
        if (is_numeric($s)) return max(0, (int)$s * 60);

        return 0;
    }

    /**
     * Safely read promo time limit (seconds) from promo model or fallback config.
     */
    private function getPromoTimeLimitSeconds(Promo $promo): int
    {
        // Try reading column 'validation_time_limit' if available
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('promos', 'validation_time_limit')) {
                $val = $promo->getAttribute('validation_time_limit');
                $sec = $this->parseTimeLimitToSeconds($val);
                if ($sec > 0) return $sec;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }
        $fallback = config('app.promo_validation_default', null);
        return $this->parseTimeLimitToSeconds($fallback);
    }


    public function index(Request $request)
    {
        try {
            $adminId = Auth::id();
            $sortDirection = $request->get('sortDirection', 'DESC');
            $sortby        = $request->get('sortBy', 'created_at');
            $paginate      = (int) $request->get('paginate', 10);
            $filter        = $request->get('filter', null);

            $query = Promo::with(['community'])

                ->when($request->filled('code'), function ($q) use ($request) {
                    $q->where('code', $request->get('code'));
                })

                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('title', 'like', "%{$s}%")
                            ->orWhere('code', 'like', "%{$s}%")
                            ->orWhere('description', 'like', "%{$s}%");
                    });
                });



            if ($filter) {
                $filters = is_string($filter) ? json_decode($filter, true) : (array) $filter;
                foreach ($filters as $column => $value) {
                    if ($value === null || $value === '') continue;

                    if ($column === 'community_id') {
                        $filterVal = is_string($value) && str_contains($value, ':')
                            ? explode(':', $value)[1]
                            : $value;
                        $query->where('community_id', $filterVal);
                    } elseif ($column === 'promo_type') {
                        $query->where('promo_type', $value);
                    } elseif ($column === 'status') {
                        $query->where('status', $value);
                    } else {
                        $query->where($column, 'like', '%' . $value . '%');
                    }
                }
            }

            if ($paginate <= 0 || $request->boolean('all')) {
                $items = $query->orderBy($sortby, $sortDirection)->get();

                $transformedItems = $items->map(function ($p) {
                    $p->validation_type = $p->validation_type ?? 'auto';
                    return $this->transformPromoImageUrls($p);
                });

                return response([
                    'message'   => 'success',
                    'data'      => $transformedItems,
                    'total_row' => $transformedItems->count(),
                ]);
            }

            $data = $query->orderBy($sortby, $sortDirection)->paginate($paginate);
            $items = collect($data->items())->map(function ($p) {
                $p->validation_type = $p->validation_type ?? 'auto';
                return $this->transformPromoImageUrls($p);
            })->values();

            if ($items->isEmpty()) {
                return response([
                    'message' => 'empty data',
                    'data'    => [],
                ], 200);
            }

            return response([
                'message'   => 'success',
                'data'      => $items,
                'total_row' => $data->total(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching promos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: server side having problem!'
            ], 500);
        }
    }

    /** For dropdown usage */
    public function forDropdown(Request $request)
    {
        try {
            $query = Promo::select('id', 'title', 'code', 'community_id')
                ->when($request->filled('community_id'), function ($q) use ($request) {
                    $q->where('community_id', $request->get('community_id'));
                })
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('title', 'like', "%{$s}%")
                            ->orWhere('code', 'like', "%{$s}%");
                    });
                });

            $promos = $query->orderBy('title', 'ASC')->get();

            $formattedPromos = $promos->map(function ($promo) {
                return [
                    'value' => $promo->id,
                    'label' => $promo->title,
                    'code' => $promo->code,
                    'community_id' => $promo->community_id,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'success',
                'data' => $formattedPromos,
                'count' => $formattedPromos->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching promos for dropdown: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: server side having problem!'
            ], 500);
        }
    }

    public function show($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'message' => 'Data not found'
            ], 404);
        }

        $promo->validation_type = $promo->validation_type ?? 'auto';
        $promo = $this->transformPromoImageUrls($promo);

        return response([
            'message' => 'Success',
            'data'    => $promo
        ]);
    }

    /** Public (QR) */
    public function showPublic($id)
    {
        try {
            Log::info('showPublic called', ['promo_id' => $id]);

            $query = Promo::where('id', $id);

            try {
                $promo = $query->with(['community'])->first();
            } catch (\Exception $e) {
                Log::warning('Relations not available for Promo model, loading without them');
                $promo = $query->first();
            }

            if (!$promo) {
                Log::warning('Promo not found', ['promo_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan'
                ], 404);
            }

            if (isset($promo->status) && $promo->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak aktif'
                ], 404);
            }

            $promo = $this->transformPromoImageUrls($promo);

            $responseData = [
                'id' => $promo->id,
                'title' => $promo->title ?? '',
                'description' => $promo->description ?? '',
                'detail' => $promo->detail ?? '',
                'owner_name' => $promo->owner_name ?? '',
                'owner_contact' => $promo->owner_contact ?? '',
                'location' => $promo->location ?? '',
                'promo_type' => $promo->promo_type ?? '',
                'always_available' => $promo->always_available ?? true,
                'start_date' => $promo->start_date ?? null,
                'end_date' => $promo->end_date ?? null,
                'community_id' => $promo->community_id ?? null,
                'stock' => $promo->stock ?? null,
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
                'image_url_versioned' => $promo->image_url_versioned ?? $promo->image_url,
                'image' => $promo->image,
                'validation_type' => $promo->validation_type ?? 'auto',
            ];

            if (isset($promo->community)) {
                $responseData['community'] = $promo->community;
            }

            if (isset($promo->original_price)) {
                $responseData['original_price'] = $promo->original_price;
            }
            if (isset($promo->discount_price)) {
                $responseData['discount_price'] = $promo->discount_price;
            }
            if (isset($promo->discount_percentage)) {
                $responseData['discount_percentage'] = $promo->discount_percentage;
            }

            try {
                $claimedCount = method_exists($promo, 'validations')
                    ? $promo->validations()->count()
                    : PromoValidation::where('promo_id', $promo->id)->count();
                $responseData['claimed_count'] = $claimedCount;
            } catch (\Throwable $e) {
                $responseData['claimed_count'] = 0;
            }

            Log::info('Returning promo data', ['response' => $responseData]);

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            Log::error('Error showing public promo:', [
                'promo_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data promo'
            ], 500);
        }
    }

    public function store(Request $request)
    {

        if (!$request->filled('validation_type')) {
            $request->merge(['validation_type' => 'auto']);
        }

        $request->merge([
            'community_id'     => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'always_available' => in_array($request->input('always_available'), [1, '1', true, 'true'], true) ? '1' : '0',
        ]);


        if ($request->filled('owner_manager_id') && !$request->filled('owner_user_id')) {
            $request->merge(['owner_user_id' => $request->input('owner_manager_id')]);
        }


        if ($request->filled('owner_user_id')) {
            $user = User::find($request->input('owner_user_id'));
            [$uname, $uphone] = $this->extractUserNamePhone($user);
            if ($uname && !$request->filled('owner_name')) {
                $request->merge(['owner_name'    => $uname]);
            }
            if ($uphone && !$request->filled('owner_contact')) {
                $request->merge(['owner_contact' => $uphone]);
            }
        }

        $validator = Validator::make($request->all(), [
            'owner_user_id'   => 'nullable|integer|exists:users,id',

            'title'           => 'required|string|max:255',
            'description'     => 'required|string',
            'detail'          => 'nullable|string',
            'promo_distance'  => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'always_available' => 'required|in:0,1,true,false',
            'stock'           => 'required|integer|min:0',
            'promo_type'      => 'required|string|in:offline,online',
            'validation_type' => 'required|string|in:auto,manual',
            'location'        => 'nullable|string',
            'owner_name'      => 'required|string|max:255',
            'owner_contact'   => 'required|string|max:255',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'community_id'    => 'required|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code|required_if:validation_type,manual',
        ], [
            'validation_type.required' => 'Tipe validasi wajib diisi.',
            'validation_type.in'       => 'Tipe validasi harus auto atau manual.',
            'code.required_if'         => 'Kode wajib diisi untuk tipe validasi manual.',
        ]);


        $validator->after(function ($v) use ($request) {
            if ($request->filled('owner_user_id') && !$request->filled('owner_contact')) {
                $v->errors()->add('owner_contact', 'Nomor telepon manager pada data user belum diisi.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();

            $data['always_available'] = in_array($data['always_available'] ?? '0', [1, '1', true, 'true'], true);
            $data['promo_distance']   = isset($data['promo_distance']) ? (float) $data['promo_distance'] : 0;
            $data['stock']            = isset($data['stock']) ? (int) $data['stock'] : 0;

            $data['validation_type'] = strtolower($data['validation_type'] ?? 'auto');
            if (!in_array($data['validation_type'], ['auto', 'manual'], true)) {
                $data['validation_type'] = 'auto';
            }


            $data['code'] = $this->generateConsistentPromoCode(
                $data['validation_type'],
                $data['code'] ?? null
            );

            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('promos', 'public');
                $data['image_updated_at'] = now();
            }

            $promo = Promo::create($data);


            $this->syncCodeToRelatedTables($promo, $data['code']);

            $promo = $this->transformPromoImageUrls($promo);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Promo berhasil dibuat',
                'data'    => $promo
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error creating promo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat promo: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo tidak ditemukan'
            ], 404);
        }

        Log::info('Promo update request data:', $request->all());

        $request->merge([
            'community_id'     => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'always_available' => in_array($request->input('always_available'), [1, '1', true, 'true'], true) ? '1' : (in_array($request->input('always_available'), [0, '0', false, 'false'], true) ? '0' : null),
        ]);


        if ($request->filled('owner_manager_id') && !$request->filled('owner_user_id')) {
            $request->merge(['owner_user_id' => $request->input('owner_manager_id')]);
        }


        if ($request->filled('owner_user_id')) {
            $user = User::find($request->input('owner_user_id'));
            [$uname, $uphone] = $this->extractUserNamePhone($user);
            if ($uname && !$request->filled('owner_name')) {
                $request->merge(['owner_name'    => $uname]);
            }
            if ($uphone && !$request->filled('owner_contact')) {
                $request->merge(['owner_contact' => $uphone]);
            }
        }


        $validationRules = [
            'owner_user_id'   => 'nullable|integer|exists:users,id',

            'title'           => 'sometimes|required|string|max:255',
            'description'     => 'sometimes|required|string',
            'detail'          => 'nullable|string',
            'promo_distance'  => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'always_available' => 'nullable|in:0,1,true,false',
            'stock'           => 'nullable|integer|min:0',
            'promo_type'      => 'sometimes|required|string|in:offline,online',
            'validation_type' => 'nullable|string|in:auto,manual',
            'location'        => 'nullable|string',
            'owner_name'      => 'sometimes|string|max:255',
            'owner_contact'   => 'sometimes|string|max:255',
            'community_id'    => 'sometimes|nullable|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code,' . $id . '|required_if:validation_type,manual',
            'image'           => 'sometimes|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
        ];

        if (!$request->hasFile('image')) {
            $request->request->remove('image');
        }

        $validator = Validator::make($request->all(), $validationRules, [
            'validation_type.in' => 'Tipe validasi harus auto atau manual.',
            'code.required_if'   => 'Kode wajib diisi untuk tipe validasi manual.',
        ]);


        $validator->after(function ($v) use ($request) {
            if ($request->filled('owner_user_id')) {
                if ($request->has('owner_contact') && ($request->input('owner_contact') === null || $request->input('owner_contact') === '')) {
                    $v->errors()->add('owner_contact', 'Nomor telepon manager pada data user belum diisi.');
                }
            }
        });

        if ($validator->fails()) {
            Log::error('Validation failed on promo update', [
                'errors'       => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();

            if (isset($data['image']) && is_string($data['image']) && !$request->hasFile('image')) {
                unset($data['image']);
            }

            if (!array_key_exists('community_id', $data) || $data['community_id'] === null) {
                $data['community_id'] = $promo->community_id;
            }

            if (array_key_exists('always_available', $data) && $data['always_available'] !== null) {
                $data['always_available'] = in_array($data['always_available'], [1, '1', true, 'true'], true);
            }
            if (isset($data['promo_distance']) && $data['promo_distance'] !== null) {
                $data['promo_distance'] = (float) $data['promo_distance'];
            }
            if (isset($data['stock']) && $data['stock'] !== null) {
                $data['stock'] = (int) $data['stock'];
            }

            if (!array_key_exists('validation_type', $data) || empty($data['validation_type'])) {
                $data['validation_type'] = $promo->validation_type ?? 'auto';
            } else {
                $data['validation_type'] = strtolower($data['validation_type']);
                if (!in_array($data['validation_type'], ['auto', 'manual'], true)) {
                    $data['validation_type'] = $promo->validation_type ?? 'auto';
                }
            }


            if (isset($data['code']) || isset($data['validation_type'])) {
                $newValidationType = $data['validation_type'];
                $newCode = $data['code'] ?? null;


                if (
                    $newValidationType !== $promo->validation_type ||
                    ($newValidationType === 'manual' && !empty($newCode))
                ) {

                    $data['code'] = $this->generateConsistentPromoCode($newValidationType, $newCode);

                    Log::info('Updated promo code for consistency', [
                        'promo_id' => $promo->id,
                        'old_code' => $promo->code,
                        'new_code' => $data['code'],
                        'validation_type' => $newValidationType
                    ]);
                }
            }

            if ($request->hasFile('image')) {
                if (!empty($promo->image)) {
                    Storage::disk('public')->delete($promo->image);
                }
                $data['image'] = $request->file('image')->store('promos', 'public');
                $data['image_updated_at'] = now();
            } else if (!$request->hasFile('image')) {
                $significantFields = ['title', 'description', 'detail', 'stock', 'start_date', 'end_date', 'location', 'owner_name', 'owner_contact'];
                $hasSignificantChange = collect($significantFields)->some(function ($field) use ($data, $promo) {
                    return isset($data[$field]) && $data[$field] != $promo->$field;
                });

                if ($hasSignificantChange) {
                    $data['image_updated_at'] = now();
                    Log::info('🔄 Force cache bust for non-image changes');
                }
            }

            $promo->update($data);


            if (isset($data['code'])) {
                $this->syncCodeToRelatedTables($promo, $data['code']);
            }

            $promo = $this->transformPromoImageUrls($promo->fresh());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Promo berhasil diperbarui',
                'data'    => $promo
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error updating promo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui promo: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo tidak ditemukan'
            ], 404);
        }

        try {
            DB::beginTransaction();


            PromoItem::where('promo_id', $id)->delete();


            PromoValidation::where('promo_id', $id)->delete();


            if (!empty($promo->image)) {
                Storage::disk('public')->delete($promo->image);
            }


            $promo->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Promo beserta data terkait berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error deleting promo with cascade: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus promo: ' . $e->getMessage()
            ], 500);
        }
    }


    public function indexByCommunity($communityId)
    {
        $promos = Promo::where('community_id', $communityId)->get();
        $promos = $promos->map(function ($promo) {
            $promo->validation_type = $promo->validation_type ?? 'auto';
            return $promo;
        });

        return response()->json([
            'success' => true,
            'data' => $promos
        ]);
    }


    public function storeForCommunity(Request $request, $communityId)
    {
        if ($request->boolean('attach_existing') || ($request->has('promo_id') && !$request->has('title'))) {
            return $this->assignToCommunity($request, $communityId);
        }

        $request->merge(['community_id' => $communityId]);

        return $this->store($request);
    }

    /** Returns promos available to be assigned to the community. */
    public function availableForCommunity($communityId)
    {
        $promos = Promo::whereNull('community_id')
            ->orWhere('community_id', $communityId)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $promos
        ]);
    }

    public function assignToCommunity(Request $request, $communityId)
    {
        $validator = Validator::make($request->all(), [
            'promo_id' => 'required|exists:promos,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        $promo = Promo::findOrFail($request->input('promo_id'));

        if ($promo->community_id && $promo->community_id != $communityId) {
            return response()->json([
                'success' => false,
                'message' => 'Promo sudah ditugaskan ke komunitas lain'
            ], 409);
        }

        $promo->community_id = $communityId;
        $promo->save();

        return response()->json([
            'success' => true,
            'message' => 'Promo berhasil ditugaskan ke komunitas',
            'data' => $promo
        ]);
    }

    public function detachFromCommunity($communityId, $promoId)
    {
        $promo = Promo::where('id', $promoId)->where('community_id', $communityId)->first();

        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo tidak ditemukan atau tidak ditugaskan ke komunitas ini'
            ], 404);
        }

        $promo->community_id = null;
        $promo->save();

        return response()->json([
            'success' => true,
            'message' => 'Promo berhasil dilepas dari komunitas',
            'data' => $promo
        ]);
    }

    public function showForCommunity($communityId, $promoId)
    {
        try {
            Log::info('showForCommunity called', [
                'community_id' => $communityId,
                'promo_id' => $promoId
            ]);

            $community = Community::find($communityId);
            if (!$community) {
                return response()->json([
                    'success' => false,
                    'message' => 'Komunitas tidak ditemukan'
                ], 404);
            }

            $promo = Promo::where('id', $promoId)
                ->where('community_id', $communityId)
                ->first();

            if (!$promo) {
                $promo = Promo::find($promoId);
                if (!$promo) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Promo tidak ditemukan'
                    ], 404);
                }
            }

            if (isset($promo->status) && $promo->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak aktif'
                ], 404);
            }



            $responseData = [
                'id' => $promo->id,
                'title' => $promo->title ?? '',
                'description' => $promo->description ?? '',
                'detail' => $promo->detail ?? '',
                'owner_name' => $promo->owner_name ?? '',
                'owner_contact' => $promo->owner_contact ?? '',
                'location' => $promo->location ?? '',
                'promo_type' => $promo->promo_type ?? 'offline',
                'promo_distance' => $promo->promo_distance ?? 0,
                'always_available' => $promo->always_available ?? true,
                'start_date' => $promo->start_date ?? null,
                'end_date' => $promo->end_date ?? null,
                'community_id' => $promo->community_id ?? $communityId,
                'stock' => $promo->stock ?? null,
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
                'image_url_versioned' => $promo->image_url_versioned ?? $promo->image_url,
                'image' => $promo->image,
                'validation_type' => $promo->validation_type ?? 'auto',
            ];

            if (isset($promo->original_price)) {
                $responseData['original_price'] = $promo->original_price;
            }
            if (isset($promo->discount_price)) {
                $responseData['discount_price'] = $promo->discount_price;
            }
            if (isset($promo->discount_percentage)) {
                $responseData['discount_percentage'] = $promo->discount_percentage;
            }

            $responseData['community'] = [
                'id' => $community->id,
                'name' => $community->name,
                'location' => $community->location ?? null
            ];

            try {
                $claimedCount = method_exists($promo, 'validations')
                    ? $promo->validations()->count()
                    : PromoValidation::where('promo_id', $promo->id)->count();
                $responseData['claimed_count'] = $claimedCount;
            } catch (\Exception $e) {
                $responseData['claimed_count'] = 0;
            }

            Log::info('Returning community promo data', ['response' => $responseData]);

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            Log::error('Error showing community promo:', [
                'community_id' => $communityId,
                'promo_id' => $promoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data promo'
            ], 500);
        }
    }

    /**
     * Idempotent validation dengan logika pencarian yang diperbaiki:
     * - Jika ada item_id, validasi item spesifik (untuk QR code)
     * - Jika tidak ada item_id, cari berdasarkan kode dengan urutan FIFO
     * - Pastikan setiap user mendapat giliran validasi yang tepat
     */
    public function validateCode(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'code'               => 'required|string',
            'item_id'            => 'sometimes|integer',
            'item_owner_id'      => 'sometimes|integer',
            'expected_type'      => 'sometimes|in:promo',
            'validator_role'     => 'sometimes|in:tenant',
            'validation_purpose' => 'sometimes|string',
            'qr_timestamp'       => 'sometimes',
        ]);


        $raw  = (string)($data['code'] ?? '');
        $code = trim($raw);
        $code = preg_replace('/[\x00-\x1F\x7F\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', '', $code);

        Log::info('🧪 CODE DEBUG', [

            'raw'  => $data['code'] ?? null,
            'trim' => $code,
            'len'  => strlen($code),
            'hex'  => bin2hex($code),
        ]);
        $itemId     = $data['item_id'] ?? null;
        $ownerHint  = $data['item_owner_id'] ?? null;
        $item       = null;
        $promo      = null;


        Log::info('🎯 Promo validateCode started', [
            'user_id' => $user->id,
            'code' => $code,
            'item_id' => $itemId,
            'owner_hint' => $ownerHint,
            'validator_role' => $data['validator_role'] ?? null
        ]);




        if ($itemId) {
            $item = \App\Models\PromoItem::with(['promo', 'user'])
                ->where('id', $itemId)
                ->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Promo item tidak ditemukan'], 404);
            }


            if ($ownerHint && (int)$item->user_id !== (int)$ownerHint) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo item ini bukan milik user yang dimaksud'
                ], 422);
            }


            $promo = $item->promo;
            $isValidCode = false;


            if ($promo && $promo->validation_type === 'manual') {

                $isValidCode = hash_equals((string)$promo->code, $code);
            } else {

                $isValidCode = hash_equals((string)$item->code, $code);
            }

            if (!$isValidCode) {
                return response()->json(['success' => false, 'message' => 'Kode unik tidak valid.'], 422);
            }
        } else {

            $promo = \App\Models\Promo::where('code', $code)->first();
            Log::info('🔍 Search master promo by code', ['code' => $code, 'found' => !!$promo, 'promo_id' => $promo->id ?? null]);

            if (!$promo) {


                $promoItem = \App\Models\PromoItem::with(['promo', 'user'])
                    ->where('code', $code)
                    ->first();


                if ($promoItem && !$promoItem->promo) {
                    $promo = \App\Models\Promo::find($promoItem->promo_id)
                        ?: \App\Models\Promo::where('code', $promoItem->code)->first();

                    if (!$promo) {
                        Log::warning('Orphan promo_item detected', [
                            'item_id'  => $promoItem->id,
                            'promo_id' => $promoItem->promo_id,
                            'code'     => $promoItem->code,
                        ]);
                        return response()->json(['success' => false, 'message' => 'Promo tidak ditemukan'], 404);
                    }
                }

                Log::info('🔍 Search promo_item by code', ['code' => $code, 'found' => !!$promoItem, 'item_owner' => $promoItem->user_id ?? null]);

                if ($promoItem) {

                    if ($promoItem->user_id == $user->id) {
                        $item  = $promoItem;
                        $promo = $item->promo;
                        Log::info('🎯 User owns the promo_item, using existing item', ['item_id' => $item->id]);
                    } else {

                        $promo = $promoItem->promo;
                        $item = null;
                        $ownerHint = $user->id;
                        Log::info('🎯 User does not own the item, will create new item for this user', ['promo_id' => $promo->id]);
                    }
                } else {
                    Log::warning('❌ Code not found in both promos and promo_items', ['code' => $code]);
                    return response()->json(['success' => false, 'message' => 'Promo dengan kode tersebut tidak ditemukan'], 404);
                }
            } else {

                $existingItem = \App\Models\PromoItem::with(['promo', 'user'])
                    ->where('promo_id', $promo->id)
                    ->where('user_id', $user->id)
                    ->first();

                Log::info('🔍 Search existing promo_item for user', ['promo_id' => $promo->id, 'user_id' => $user->id, 'found' => !!$existingItem]);

                if ($existingItem) {
                    $item = $existingItem;
                    Log::info('🎯 Found existing item for user', ['item_id' => $item->id]);
                } else {

                    $ownerHint = $user->id;
                    $item = null;
                    Log::info('🎯 No existing item, will create new one', ['promo_id' => $promo->id, 'user_id' => $user->id]);
                }
            }
        }

        if (!$promo) {
            return response()->json(['success' => false, 'message' => 'Promo tidak ditemukan'], 404);
        }


        if (!empty($promo->end_date) && now()->greaterThan($promo->end_date)) {
            return response()->json(['success' => false, 'message' => 'Promo kedaluwarsa'], 422);
        }


        $tenantId = $this->resolveTenantUserIdByPromo($promo);
        $validatorRole = $data['validator_role'] ?? null;

        if ($validatorRole === 'tenant') {
            if (!$tenantId || $user->id !== $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR ini hanya dapat divalidasi oleh tenant pemilik promo.'
                ], 403);
            }
            $validatorId = $user->id;
        } else {

            $validatorId = $tenantId ?: $user->id;
        }




        if ($item) {
            if (!is_null($item->redeemed_at) || in_array($item->status, ['redeemed', 'used'], true)) {
                $item->setAttribute('display_code', ($promo->code ?? $code));
                return response()->json([
                    'success' => true,
                    'message' => 'Promo sudah divalidasi sebelumnya',
                    'data'    => [
                        'promo_item_id' => $item->id,
                        'promo_item'    => $item,
                        'promo'         => $this->transformPromoImageUrls($promo),
                    ],
                ], 200);
            }


            return $this->processExistingItemValidation($item, $promo, $validatorId, $code);
        } else {
            return $this->createAndValidateNewItem($promo, $ownerHint, $validatorId, $code);
        }
    }

    /**
     * Proses validasi untuk item yang sudah ada
     */
    private function processExistingItemValidation($item, $promo, $validatorId, $code)
    {
        DB::beginTransaction();
        try {
            $locked = \App\Models\PromoItem::with(['promo'])
                ->where('id', $item->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Promo item tidak ditemukan'], 404);
            }


            if (!is_null($locked->redeemed_at) || in_array($locked->status, ['redeemed', 'used'], true)) {
                DB::commit();
                $lockedFresh = $locked->fresh();
                $lockedFresh->setAttribute('display_code', ($promo->code ?? $code));
                return response()->json([
                    'success' => true,
                    'message' => 'Promo sudah divalidasi sebelumnya',
                    'data'    => [
                        'promo_item_id' => $locked->id,
                        'promo_item'    => $lockedFresh,
                        'promo'         => $locked->promo,
                    ],
                ], 200);
            }


            $updates = ['redeemed_at' => now()];
            // Ensure expires_at exists and reflects promo's validation_time_limit when possible
            try {
                if (Schema::hasColumn('promo_items', 'expires_at') && empty($locked->expires_at)) {
                    $limitSec = $this->getPromoTimeLimitSeconds($promo);
                    if ($limitSec > 0) {
                        $expiresAt = now()->addSeconds($limitSec);
                    } else {
                        $expiresAt = $promo->end_date ?? null;
                    }
                    if ($expiresAt) $updates['expires_at'] = $expiresAt;
                }
            } catch (\Throwable $e) {
                // ignore and continue
            }
            if (Schema::hasColumn('promo_items', 'status')) $updates['status'] = 'redeemed';

            $affected = \App\Models\PromoItem::where('id', $locked->id)
                ->whereNull('redeemed_at')
                ->update($updates);

            if ($affected === 0) {
                DB::commit();
                $lockedFresh = $locked->fresh();
                $lockedFresh->setAttribute('display_code', ($promo->code ?? $code));
                return response()->json([
                    'success' => true,
                    'message' => 'Promo sudah divalidasi sebelumnya',
                    'data'    => [
                        'promo_item_id' => $locked->id,
                        'promo_item'    => $lockedFresh,
                        'promo'         => $this->transformPromoImageUrls($locked->promo),
                    ],
                ], 200);
            }


            $this->savePromoValidation($locked, $validatorId, $promo->code ?? $code);

            DB::commit();

            $lockedFresh = $locked->fresh();
            $lockedFresh->setAttribute('display_code', ($promo->code ?? $code));

            return response()->json([
                'success' => true,
                'message' => 'Validasi berhasil',
                'data'    => [
                    'promo_item_id' => $locked->id,
                    'promo_item'    => $lockedFresh,
                    'promo'         => $this->transformPromoImageUrls($locked->promo),
                ]
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('processExistingItemValidation error: ' . $e->getMessage(), ['code' => $code, 'item_id' => $item->id]);
            return response()->json(['success' => false, 'message' => 'Gagal memproses validasi'], 500);
        }
    }

    /**
     * Buat item baru dan lakukan validasi
     */
    private function createAndValidateNewItem($promo, $ownerHint, $validatorId, $enteredCode)
    {
        DB::beginTransaction();
        try {

            $item = \App\Models\PromoItem::where('promo_id', $promo->id)
                ->where('user_id', $ownerHint)
                ->lockForUpdate()
                ->first();

            if (!$item) {

                if (!is_null($promo->stock)) {
                    $affected = \App\Models\Promo::where('id', $promo->id)
                        ->where('stock', '>', 0)
                        ->decrement('stock');
                    if ($affected === 0) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => 'Stok promo habis'], 409);
                    }
                }



                $masterCode = $promo->code ?? $enteredCode;

                $limitSec = $this->getPromoTimeLimitSeconds($promo);
                if ($limitSec > 0) {
                    $expiresAt = now()->addSeconds($limitSec);
                } else {
                    $expiresAt = $promo->end_date ?? null;
                }

                $itemData = [
                    'promo_id'    => $promo->id,
                    'user_id'     => $ownerHint,
                    'code'        => $masterCode,
                    'status'      => 'redeemed',
                    'redeemed_at' => now(),
                ];
                if ($expiresAt) $itemData['expires_at'] = $expiresAt;

                $item = \App\Models\PromoItem::create($itemData);
            } else {

                if (is_null($item->redeemed_at)) {
                    $item->update(['status' => 'redeemed', 'redeemed_at' => now()]);
                }
            }


            $this->savePromoValidation($item, $validatorId, $promo->code ?? $enteredCode);


            $item->setAttribute('display_code', $promo->code ?? $enteredCode);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validasi berhasil',
                'data'    => [
                    'promo_item_id' => $item->id,
                    'promo_item'    => $item->load(['promo', 'user']),
                    'promo'         => $this->transformPromoImageUrls($promo),
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('createAndValidateNewItem error: ' . $e->getMessage(), ['promo_id' => $promo->id, 'owner_hint' => $ownerHint]);
            return response()->json(['success' => false, 'message' => 'Gagal membuat dan memvalidasi item promo'], 500);
        }
    }

    /**
     * Helper untuk menyimpan validasi promo dengan kode yang konsisten
     */
    private function savePromoValidation($item, $validatorId, $code)
    {

        $masterCode = $item->promo->code ?? $code;

        $validationData = [
            'user_id'      => $validatorId,
            'promo_id'     => $item->promo_id,
            'validated_at' => now(),
            'code'         => $masterCode,
            'notes'        => null,
        ];

        $notes = 'item_id:' . $item->id . '|owner_id:' . $item->user_id . '|validator_id:' . $validatorId . '|entered_code:' . $code . '|master_code:' . $masterCode;

        if (Schema::hasColumn('promo_validations', 'promo_item_id')) {
            $validationData['promo_item_id'] = $item->id;
        }

        $validationData['notes'] = $notes;


        $existingValidation = null;
        if (Schema::hasColumn('promo_validations', 'promo_item_id')) {
            $existingValidation = \App\Models\PromoValidation::where('promo_item_id', $item->id)->first();
        }

        if (!$existingValidation) {
            \App\Models\PromoValidation::create($validationData);
        } else {
            $existingValidation->update([
                'validated_at' => now(),
                'code' => $masterCode,
                'notes'        => $validationData['notes']
            ]);
        }
    }

    public function history($promoId)
    {
        Log::info("Fetching history for promo ID: " . $promoId);

        try {
            $promo = Promo::find($promoId);
            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan'
                ], 404);
            }


            if (Schema::hasColumn('promo_validations', 'promo_item_id')) {

                $rows = PromoValidation::query()
                    ->with(['promo', 'user'])
                    ->leftJoin('promo_items', 'promo_items.id', '=', 'promo_validations.promo_item_id')
                    ->select('promo_validations.*', 'promo_items.user_id as owner_id')
                    ->where('promo_validations.promo_id', $promoId)
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();
            } else {

                $allRows = PromoValidation::query()
                    ->with(['promo', 'user'])
                    ->where('promo_validations.promo_id', $promoId)
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();



                $seen = [];
                $rows = collect();

                foreach ($allRows as $row) {
                    $itemId = null;
                    if (preg_match('/item_id:(\d+)/', $row->notes, $matches)) {
                        $itemId = $matches[1];
                    }


                    if ($itemId) {
                        if (!isset($seen[$itemId])) {
                            $seen[$itemId] = true;
                            $rows->push($row);
                        }
                    } else {

                        $key = $row->code . '|' . $row->validated_at;
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $rows->push($row);
                        }
                    }
                }


                $rows = $rows->map(function ($row) {
                    if (preg_match('/owner_id:(\d+)/', $row->notes, $matches)) {
                        $row->owner_id = (int)$matches[1];
                    } else {

                        $item = \App\Models\PromoItem::where('code', $row->code)
                            ->where('promo_id', $row->promo_id)
                            ->first();
                        $row->owner_id = $item ? $item->user_id : null;
                    }
                    return $row;
                });
            }

            $ownerIds = $rows->pluck('owner_id')->filter()->unique()->values();
            $owners = $ownerIds->isNotEmpty()
                ? User::whereIn('id', $ownerIds)->get(['id', 'name'])->keyBy('id')
                : collect();

            $data = $rows->map(function ($r) use ($owners) {
                $owner = null;
                if ($r->owner_id && isset($owners[$r->owner_id])) {
                    $owner = ['id' => $r->owner_id, 'name' => $owners[$r->owner_id]->name];
                }
                return [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'validated_at' => $r->validated_at,
                    'notes'        => $r->notes,
                    'promo'        => $r->promo,
                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    'owner'        => $owner,
                    'itemType'     => 'promo',
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error("Error in history method: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()
            ], 500);
        }
    }

    public function validationsIndex(Request $req)
    {
        try {

            $promoId = $req->input('promo_id');
            $promoCode = trim((string)$req->input('promo_code', ''));

            $promo = null;


            if ($promoId) {
                $promo = Promo::find($promoId);
            } elseif ($promoCode !== '') {
                $promo = Promo::where('code', $promoCode)->first();
            }

            if (!$promo) {
                return response()->json(['data' => [], 'total_row' => 0]);
            }



            if ($req->filled('validation_type_filter')) {
                $filterType = $req->input('validation_type_filter');
                if ($filterType === 'qr_only' && $promo->validation_type !== 'auto') {
                    return response()->json(['data' => [], 'total_row' => 0]);
                }
                if ($filterType === 'manual_only' && $promo->validation_type !== 'manual') {
                    return response()->json(['data' => [], 'total_row' => 0]);
                }
            }

            $page        = max((int)$req->input('page', 1), 1);
            $paginate    = max((int)$req->input('paginate', 10), 1);
            $search      = trim((string)$req->input('search', ''));
            $sortBy      = $req->input('sortBy', 'validated_at');
            $sortDirRaw  = strtolower($req->input('sortDirection', 'desc'));
            $sortDir     = $sortDirRaw === 'asc' ? 'asc' : 'desc';


            $base = PromoValidation::query()
                ->with(['user:id,name'])
                ->where('promo_id', $promo->id);

            if ($search !== '') {
                $base->where(function ($w) use ($search) {
                    $w->where('code', 'like', "%{$search}%")
                        ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%"));
                });
            }

            if (!in_array($sortBy, ['validated_at', 'code', 'user_id', 'id'], true)) {
                $sortBy = 'validated_at';
            }
            $base->orderBy($sortBy, $sortDir);


            $total = (clone $base)->count();


            if (Schema::hasColumn('promo_validations', 'promo_item_id')) {
                $rows = (clone $base)
                    ->leftJoin('promo_items', 'promo_items.id', '=', 'promo_validations.promo_item_id')
                    ->select('promo_validations.*', 'promo_items.user_id as owner_id')
                    ->skip(($page - 1) * $paginate)
                    ->take($paginate)
                    ->get();
            } else {

                $rows = (clone $base)
                    ->skip(($page - 1) * $paginate)
                    ->take($paginate)
                    ->get();

                $rows = $rows->map(function ($r) {
                    $ownerId = null;
                    if ($r->notes && preg_match('/owner_id:(\d+)/', $r->notes, $m)) {
                        $ownerId = (int)$m[1];
                    } else {

                        $pi = \App\Models\PromoItem::where('promo_id', $r->promo_id)
                            ->where('code', $r->code)
                            ->latest('id')
                            ->first();
                        $ownerId = $pi?->user_id;
                    }
                    $r->owner_id = $ownerId;
                    return $r;
                });
            }


            $ownerIds = $rows->pluck('owner_id')->filter()->unique()->values();
            $owners   = $ownerIds->isNotEmpty()
                ? \App\Models\User::whereIn('id', $ownerIds)->get(['id', 'name'])->keyBy('id')
                : collect();

            $data = $rows->map(function ($r) use ($owners) {
                $owner = null;
                if ($r->owner_id && isset($owners[$r->owner_id])) {
                    $owner = ['id' => $r->owner_id, 'name' => $owners[$r->owner_id]->name];
                }
                return [
                    'code'          => $r->code,
                    'validated_at'  => $r->validated_at,
                    'user'          => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    'owner'         => $owner,
                ];
            });

            return response()->json([
                'data'      => $data,
                'total_row' => $total,
            ]);
        } catch (\Throwable $e) {
            Log::error('validationsIndex error: ' . $e->getMessage());
            return response()->json(['data' => [], 'total_row' => 0], 200);
        }
    }

    public function userValidationHistory(Request $request)
    {
        try {
            $userId = $request->user()?->id ?? auth()->id();
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'User tidak terautentikasi'], 401);
            }


            if (Schema::hasColumn('promo_validations', 'promo_item_id')) {

                $rows = PromoValidation::query()
                    ->with(['promo', 'user'])
                    ->leftJoin('promo_items', 'promo_items.id', '=', 'promo_validations.promo_item_id')
                    ->select('promo_validations.*', 'promo_items.user_id as owner_id')
                    ->where(function ($q) use ($userId) {
                        $q->where('promo_items.user_id', $userId)
                            ->orWhere('promo_validations.user_id', $userId);
                    })
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();
            } else {

                $allRows = PromoValidation::query()
                    ->with(['promo', 'user'])
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();


                $userRows = $allRows->filter(function ($r) use ($userId) {

                    $isOwner = preg_match('/owner_id:' . $userId . '/', $r->notes ?? '');

                    $isValidator = $r->user_id == $userId;

                    return $isOwner || $isValidator;
                });


                $seen = [];
                $rows = collect();

                foreach ($userRows as $row) {
                    $itemId = null;
                    if (preg_match('/item_id:(\d+)/', $row->notes, $matches)) {
                        $itemId = $matches[1];
                    }


                    if ($itemId) {
                        if (!isset($seen[$itemId])) {
                            $seen[$itemId] = true;
                            $rows->push($row);
                        }
                    } else {

                        $key = $row->code . '|' . $row->validated_at . '|' . $row->user_id;
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $rows->push($row);
                        }
                    }
                }


                $rows = $rows->map(function ($row) {
                    if (preg_match('/owner_id:(\d+)/', $row->notes, $matches)) {
                        $row->owner_id = (int)$matches[1];
                    } else {

                        $item = \App\Models\PromoItem::where('code', $row->code)
                            ->where('promo_id', $row->promo_id)
                            ->first();
                        $row->owner_id = $item ? $item->user_id : null;
                    }
                    return $row;
                });
            }


            $ownerIds = $rows->pluck('owner_id')->filter()->unique()->values();
            $owners = $ownerIds->isNotEmpty()
                ? User::whereIn('id', $ownerIds)->get(['id', 'name'])->keyBy('id')
                : collect();

            $data = $rows->map(function ($r) use ($owners, $userId) {
                $promo = $r->promo;
                if ($promo) {
                    $promo->title = $promo->title ?? 'Promo';
                }


                $owner = null;
                if ($r->owner_id && isset($owners[$r->owner_id])) {
                    $owner = [
                        'id'   => $r->owner_id,
                        'name' => $owners[$r->owner_id]->name,
                    ];
                }


                $isOwner = $r->owner_id && (int)$r->owner_id === (int)$userId;
                $isValidator = $r->user_id && (int)$r->user_id === (int)$userId;

                return [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'validated_at' => $r->validated_at,
                    'notes'        => $r->notes,
                    'promo'        => $promo,

                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,

                    'owner'        => $owner,
                    'itemType'     => 'promo',

                    'user_relationship' => $isOwner ? 'owner' : ($isValidator ? 'validator' : 'unknown'),
                    'show_owner_info' => $isValidator,
                    'show_validator_info' => $isOwner,
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error("Error in userValidationHistory (promo): " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()], 500);
        }
    }
}
