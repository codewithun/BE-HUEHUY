<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherItem;
use App\Models\VoucherValidation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class VoucherController extends Controller
{

    /** Normalisasi nomor HP: ambil digit, buang 0/62 di depan */
    private function normalizePhone(?string $raw): ?string
    {
        if (!$raw) return null;
        $d = preg_replace('/\D+/', '', $raw);
        if ($d === '') return null;
        $d = preg_replace('/^(?:0|62)/', '', $d);
        return $d;
    }

    /** Temukan user (tenant pemilik voucher) dari owner_phone / owner_name */
    private function resolveTenantUserIdByVoucher(Voucher $voucher): ?int
    {
        try {
            $ownerPhone = $this->normalizePhone($voucher->owner_phone ?? null);

            $allUserCols = \Illuminate\Support\Facades\Schema::getColumnListing('users');
            $phoneCols = array_values(array_intersect(
                $allUserCols,
                ['phone', 'phone_number', 'telp', 'telpon', 'mobile', 'contact', 'whatsapp', 'wa']
            ));


            if ($ownerPhone && !empty($phoneCols)) {
                $u = \App\Models\User::query()
                    ->where(function ($q) use ($ownerPhone, $phoneCols) {
                        foreach ($phoneCols as $col) {
                            $q->orWhereRaw(
                                "REGEXP_REPLACE(COALESCE($col,''),'[^0-9]','') REGEXP ?",
                                ["^(0|62)?$ownerPhone$"]
                            );
                        }
                    })
                    ->first();
                if ($u) return $u->id;
            }


            $nameCols = array_values(array_intersect(
                $allUserCols,
                ['name', 'full_name', 'username', 'display_name', 'company_name']
            ));
            $ownerName = trim((string)($voucher->owner_name ?? ''));
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
            Log::error('resolveTenantUserIdByVoucher failed: ' . $e->getMessage(), [
                'voucher_id'   => $voucher->id ?? null,
                'owner_name'   => $voucher->owner_name ?? null,
                'owner_phone'  => $voucher->owner_phone ?? null,
            ]);
            return null;
        }
    }


    private function normalizeUserIds($raw): array
    {
        if (is_null($raw) || $raw === '') return [];
        $arr = is_array($raw) ? $raw : preg_split('/[,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        return collect($arr)->map(fn($v) => (int) $v)->filter(fn($v) => $v > 0)->unique()->values()->all();
    }

    private function generateCode(): string
    {
        do {
            $code = 'VCR-' . strtoupper(Str::random(8));
        } while (Voucher::where('code', $code)->exists());
        return $code;
    }


    private function pick(...$candidates)
    {
        foreach ($candidates as $v) {
            if (isset($v) && trim((string)$v) !== '') return $v;
        }
        return null;
    }


    private function extractUserNamePhone(?User $user): array
    {
        if (!$user) return [null, null];


        $name = null;
        if (isset($user->name)) $name = $user->name;
        else if (Schema::hasColumn('users', 'full_name') && isset($user->full_name)) $name = $user->full_name;
        else if (Schema::hasColumn('users', 'username') && isset($user->username)) $name = $user->username;
        else if (Schema::hasColumn('users', 'display_name') && isset($user->display_name)) $name = $user->display_name;
        else if (isset($user->email)) $name = $user->email;

        $phone = null;
        if (isset($user->phone)) $phone = $user->phone;
        else if (Schema::hasColumn('users', 'phone_number') && isset($user->phone_number)) $phone = $user->phone_number;
        else if (Schema::hasColumn('users', 'mobile') && isset($user->mobile)) $phone = $user->mobile;
        else if (Schema::hasColumn('users', 'telp') && isset($user->telp)) $phone = $user->telp;


        if (is_string($phone)) {
            $digits = preg_replace('/[^\d+]/', '', $phone);
            if (preg_match('/^0\d+$/', $digits)) {
                $digits = preg_replace('/^0/', '+62', $digits);
            }
            $phone = $digits;
        }

        return [$name, $phone];
    }

    private function applyRegularUserFilter(\Illuminate\Database\Eloquent\Builder $builder): \Illuminate\Database\Eloquent\Builder
    {
        $deny = ['admin', 'superadmin', 'manager', 'tenant', 'tenant_manager', 'manager_tenant', 'staff', 'owner', 'operator', 'moderator'];

        if (Schema::hasTable('roles') && method_exists(\App\Models\User::class, 'role')) {
            $builder->whereHas('role', function ($q) {
                $q->where(DB::raw('LOWER(name)'), 'user');
            });
        } elseif (Schema::hasColumn('users', 'role')) {
            $builder->whereRaw('LOWER(role) = ?', ['user']);
        } elseif (Schema::hasColumn('users', 'level')) {
            $builder->whereRaw('LOWER(level) = ?', ['user']);
        } elseif (Schema::hasColumn('users', 'type')) {
            $builder->whereRaw('LOWER(type) = ?', ['user']);
        } elseif (Schema::hasColumn('users', 'roles')) {
            $builder->where(function ($q) {
                $q->where('roles', 'like', '%"user"%')
                    ->orWhere('roles', 'like', '%user%');
            });
            foreach ($deny as $ban) {
                $builder->where(function ($q) use ($ban) {
                    $q->whereNull('roles')->orWhere('roles', 'not like', "%{$ban}%");
                });
            }
        }

        $boolFalse = function ($q, $col) {
            $q->where(function ($qq) use ($col) {
                $qq->whereNull($col)->orWhere($col, false)->orWhere($col, 0)->orWhere($col, '0');
            });
        };

        if (Schema::hasColumn('users', 'is_admin')) $boolFalse($builder, 'is_admin');
        if (Schema::hasColumn('users', 'is_superadmin')) $boolFalse($builder, 'is_superadmin');
        if (Schema::hasColumn('users', 'is_staff')) $boolFalse($builder, 'is_staff');
        if (Schema::hasColumn('users', 'is_manager')) $boolFalse($builder, 'is_manager');
        if (Schema::hasColumn('users', 'is_tenant_manager')) $boolFalse($builder, 'is_tenant_manager');
        if (Schema::hasColumn('users', 'tenant_manager')) $boolFalse($builder, 'tenant_manager');

        return $builder;
    }

    private function filterRegularUserIds(array $ids): array
    {
        if (empty($ids)) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $q = \App\Models\User::query()->whereIn('id', $ids);
        $q = $this->applyRegularUserFilter($q);

        return $q->pluck('id')->map(fn($v) => (int) $v)->all();
    }

    private function hydrateAudience(Collection $vouchers): Collection
    {
        if ($vouchers->isEmpty()) return $vouchers;

        $userTargetVoucherIds = $vouchers
            ->filter(fn($v) => ($v->target_type ?? null) === 'user')
            ->pluck('id')
            ->values();

        if ($userTargetVoucherIds->isEmpty()) return $vouchers;

        $notifRows = Notification::where('type', 'voucher')
            ->whereIn('target_id', $userTargetVoucherIds)
            ->select('target_id', 'user_id')
            ->get()
            ->groupBy('target_id');

        $allUserIds = $notifRows->flatMap(fn($rows) => $rows->pluck('user_id'))->unique()->values();
        $userNames = User::whereIn('id', $allUserIds)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn($u) => [$u->id => ($u->name ?: $u->email ?: ('#' . $u->id))]);

        return $vouchers->map(function ($v) use ($notifRows, $userNames) {
            if (($v->target_type ?? null) !== 'user') return $v;

            $rows    = $notifRows->get($v->id) ?? collect();
            $userIds = $rows->pluck('user_id')->unique()->values();

            $namesPreview = $userIds->take(5)->map(fn($uid) => $userNames->get($uid, '#' . $uid))->values();

            $v->setAttribute('target_user_ids', $userIds);
            $v->setAttribute('target_user_names', $namesPreview);
            $v->setAttribute('target_user_total', $userIds->count());

            return $v;
        });
    }


    private function addImageVersioning($voucher)
    {

        if (property_exists($voucher, 'image_url') || method_exists($voucher, 'getImageUrlAttribute')) {
            if ($voucher->image_url_versioned ?? null) {
                $voucher->setAttribute('image_url_versioned', $voucher->image_url_versioned);
            }
            if ($voucher->image_url ?? null) {
                $voucher->setAttribute('image_url', $voucher->image_url);
            }
            return $voucher;
        }

        if ($voucher->image) {
            $version = $voucher->image_updated_at ?? $voucher->updated_at ?? now();
            $versionParam = '?k=' . strtotime($version);
            $voucher->setAttribute('image_url_versioned', 'storage/' . ltrim($voucher->image, '/') . $versionParam);
            $voucher->setAttribute('image_url', 'storage/' . ltrim($voucher->image, '/'));
        }
        return $voucher;
    }


    private function resolveOwnerUserId($voucher)
    {
        if (isset($voucher->owner_user_id) && $voucher->owner_user_id) {
            return $voucher;
        }

        if (!($voucher->owner_name || $voucher->owner_phone)) {
            return $voucher;
        }

        try {
            $ownerQuery = User::query();


            if ($voucher->owner_name) {
                $ownerQuery->where(function ($q) use ($voucher) {
                    $q->where('name', 'like', '%' . $voucher->owner_name . '%');


                    if (Schema::hasColumn('users', 'full_name')) {
                        $q->orWhere('full_name', 'like', '%' . $voucher->owner_name . '%');
                    }
                    if (Schema::hasColumn('users', 'username')) {
                        $q->orWhere('username', 'like', '%' . $voucher->owner_name . '%');
                    }
                    if (Schema::hasColumn('users', 'display_name')) {
                        $q->orWhere('display_name', 'like', '%' . $voucher->owner_name . '%');
                    }
                });
            }

            if ($voucher->owner_phone) {
                $phone = preg_replace('/[^\d+]/', '', $voucher->owner_phone);
                $ownerQuery->where(function ($q) use ($phone) {
                    $q->where('phone', 'like', '%' . $phone . '%');


                    if (Schema::hasColumn('users', 'phone_number')) {
                        $q->orWhere('phone_number', 'like', '%' . $phone . '%');
                    }
                    if (Schema::hasColumn('users', 'mobile')) {
                        $q->orWhere('mobile', 'like', '%' . $phone . '%');
                    }
                    if (Schema::hasColumn('users', 'telp')) {
                        $q->orWhere('telp', 'like', '%' . $phone . '%');
                    }
                });
            }

            $owner = $ownerQuery->first();
            if ($owner) {
                $voucher->setAttribute('owner_user_id', $owner->id);
            }
        } catch (\Throwable $e) {

            Log::warning('Error resolving owner_user_id: ' . $e->getMessage(), [
                'voucher_id' => $voucher->id ?? 'unknown',
                'owner_name' => $voucher->owner_name ?? null,
                'owner_phone' => $voucher->owner_phone ?? null,
            ]);
        }

        return $voucher;
    }


    public function index(Request $request)
    {
        $adminId = Auth::id();
        $sortDirection = $request->get('sortDirection', 'DESC');
        $sortby        = $request->get('sortBy', 'created_at');
        $paginate      = (int) $request->get('paginate', 10);
        $filter        = $request->get('filter', null);

        $query = Voucher::with(['community'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->get('search');
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', "%{$s}%")
                        ->orWhere('code', 'like', "%{$s}%")
                        ->orWhere('owner_name', 'like', "%{$s}%")
                        ->orWhere('owner_phone', 'like', "%{$s}%");
                });
            })
            ->when($request->filled('owner_like'), function ($q) use ($request) {
                $s = $request->get('owner_like');
                $q->where(function ($qq) use ($s) {
                    $qq->where('owner_name', 'like', "%{$s}%")
                        ->orWhere('owner_phone', 'like', "%{$s}%");
                });
            });



        if ($filter) {
            $filters = is_string($filter) ? json_decode($filter, true) : (array) $filter;
            foreach ($filters as $column => $value) {
                if ($value === null || $value === '') continue;

                if ($column === 'community_id') {
                    $filterVal = is_string($value) && str_contains($value, ':') ? explode(':', $value)[1] : $value;
                    $query->where('community_id', $filterVal);
                } elseif ($column === 'target_type') {
                    $query->where('target_type', $value);
                } elseif ($column === 'target_user_id') {
                    $query->where('target_user_id', $value);
                } elseif (in_array($column, ['owner_name', 'owner_phone'])) {
                    $query->where($column, 'like', '%' . $value . '%');
                } else {
                    $query->where($column, 'like', '%' . $value . '%');
                }
            }
        }

        if ($paginate <= 0 || $request->boolean('all')) {
            $items = $query->orderBy($sortby, $sortDirection)->get();
            $items = $this->hydrateAudience($items);


            $items = $items->map(function ($item) {
                $item = $this->addImageVersioning($item);
                $item = $this->resolveOwnerUserId($item);
                return $item;
            });

            return response()->json([
                'message'   => 'success',
                'data'      => $items->map->toArray()->values(),
                'total_row' => $items->count(),
            ])->header('Cache-Control', 'no-store');
        }

        $page = $query->orderBy($sortby, $sortDirection)->paginate($paginate);

        if (empty($page->items())) {
            return response([
                'message' => 'empty data',
                'data'    => [],
            ], 200)->header('Cache-Control', 'no-store');
        }

        $items = collect($page->items());
        $items = $this->hydrateAudience($items);


        $items = $items->map(function ($item) {
            $item = $this->addImageVersioning($item);
            $item = $this->resolveOwnerUserId($item);
            return $item;
        });

        return response([
            'message'   => 'success',
            'data'      => $items->map->toArray()->values(),
            'total_row' => $page->total(),
        ])->header('Cache-Control', 'no-store');
    }

    public function forDropdown(Request $request)
    {
        try {
            $query = Voucher::select('id', 'name', 'code', 'community_id', 'target_type')
                ->when($request->filled('community_id'), fn($q) => $q->where('community_id', $request->get('community_id')))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('name', 'like', "%{$s}%")
                            ->orWhere('code', 'like', "%{$s}%");
                    });
                });

            $vouchers = $query->orderBy('name', 'ASC')->get();

            $formattedVouchers = $vouchers->map(fn($v) => [
                'value' => $v->id,
                'label' => $v->name,
                'code'  => $v->code,
                'community_id' => $v->community_id,
                'target_type'  => $v->target_type,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'success',
                'data'    => $formattedVouchers,
                'count'   => $formattedVouchers->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching vouchers for dropdown: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: server side having problem!'
            ], 500);
        }
    }

    public function show(string $id)
    {
        $model = Voucher::with([
            'community',
            'voucher_items',
            'voucher_items.user',
        ])->where('id', $id)->first();

        if (!$model) {
            return response(['messaege' => 'Data not found'], 404)->header('Cache-Control', 'no-store');
        }


        $model = $this->resolveOwnerUserId($model);

        return response(['message' => 'Success', 'data' => $model])->header('Cache-Control', 'no-store');
    }

    /**
     * Get detailed stock information for voucher (especially for daily grab vouchers)
     * GET /api/admin/vouchers/{id}/stock
     */
    public function getStockInfo(string $id)
    {
        $voucher = Voucher::find($id);

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher tidak ditemukan'
            ], 404);
        }

        $today = now()->toDateString();

        if ($voucher->is_daily_grab) {
            // Hitung grab hari ini
            $totalGrabToday = DB::table('voucher_grabs')
                ->where('voucher_id', $voucher->id)
                ->where('date', $today)
                ->sum('total_grab');

            $remainingToday = $voucher->unlimited_grab
                ? null
                : max(0, (int)$voucher->stock - (int)$totalGrabToday);

            // Get list users yang sudah grab hari ini
            $usersGrabbedToday = DB::table('voucher_grabs')
                ->join('users', 'users.id', '=', 'voucher_grabs.user_id')
                ->where('voucher_grabs.voucher_id', $voucher->id)
                ->where('voucher_grabs.date', $today)
                ->select('users.id', 'users.name', 'users.email', 'voucher_grabs.created_at')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'voucher_id' => $voucher->id,
                    'name' => $voucher->name,
                    'is_daily_grab' => true,
                    'unlimited_grab' => $voucher->unlimited_grab,
                    'stock_per_day' => $voucher->stock, // Stok per hari (asli)
                    'total_grabbed_today' => (int)$totalGrabToday,
                    'remaining_today' => $remainingToday,
                    'date' => $today,
                    'start_validate' => $voucher->start_validate,
                    'finish_validate' => $voucher->finish_validate,
                    'is_active' => $voucher->start_validate && $voucher->finish_validate
                        ? now()->between($voucher->start_validate, $voucher->finish_validate->endOfDay())
                        : true,
                    'users_grabbed_today' => $usersGrabbedToday,
                ]
            ]);
        } else {
            // Voucher normal
            $totalItems = VoucherItem::where('voucher_id', $voucher->id)->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'voucher_id' => $voucher->id,
                    'name' => $voucher->name,
                    'is_daily_grab' => false,
                    'unlimited_grab' => $voucher->unlimited_grab,
                    'total_stock' => $voucher->stock, // Stok total (berkurang permanen)
                    'total_claimed' => $totalItems,
                    'remaining' => $voucher->unlimited_grab ? null : max(0, (int)$voucher->stock),
                ]
            ]);
        }
    }

    public function showPublic(string $id)
    {
        try {
            $model = Voucher::with(['community'])
                ->where('id', $id)
                ->first();

            if (!$model) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher tidak ditemukan'
                ], 404);
            }


            $model = $this->addImageVersioning($model);
            $model = $this->resolveOwnerUserId($model);


            $publicData = [
                'id' => $model->id,
                'name' => $model->name,
                'description' => $model->description,


                'image' => $model->image,
                'image_url' => $model->image_url,
                'image_url_versioned' => $model->image_url_versioned,

                'type' => $model->type,
                'valid_until' => $model->valid_until,
                'is_valid' => $model->is_valid ?? true,
                'status' => $model->status ?? 'active',
                'target_type' => $model->target_type,
                'stock' => $model->stock ?? 0,


                'owner_name' => $model->owner_name,
                'owner_phone' => $model->owner_phone,
                'tenant_location' => $model->tenant_location,


                'community' => $model->community ? [
                    'id' => $model->community->id,
                    'name' => $model->community->name,
                    'description' => $model->community->description ?? null,
                ] : null,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $publicData
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            Log::error('Error in showPublic: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data voucher'
            ], 500);
        }
    }


    public function store(Request $request)
    {
        Log::info('Store voucher request data:', $request->all());


        if ($request->has('image') && empty($request->input('image')) && !$request->hasFile('image')) {
            $request->request->remove('image');
        }

        $request->merge([
            'target_type' => $request->input('target_type', 'all'),
        ]);

        if ($request->has('valid_until') && $request->input('valid_until') === '') {
            $request->merge(['valid_until' => null]);
        }

        if ($request->has('target_user_ids')) {
            $tu = $request->input('target_user_ids');
            if (is_string($tu)) {
                if (str_starts_with($tu, '[')) $tu = json_decode($tu, true) ?: [];
                else $tu = explode(',', $tu);
            }
            $request->merge(['target_user_ids' => $this->normalizeUserIds($tu)]);
        }

        $request->merge([
            'community_id'   => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'target_user_id' => in_array($request->input('target_user_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('target_user_id'),
        ]);

        if ($request->input('target_type') !== 'user') {
            $request->request->remove('target_user_ids');
            $request->request->remove('target_user_id');
        }
        if ($request->input('target_type') !== 'community') {
            $request->merge(['community_id' => null]);
        }

        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'type'            => 'nullable|string',
            'valid_until'     => 'nullable|date',
            'tenant_location' => 'nullable|string|max:255',
            'stock'           => 'required|integer|min:0',
            'validation_type' => ['nullable', Rule::in(['auto', 'manual'])],
            'code'            => [
                Rule::requiredIf(fn() => ($request->input('validation_type') ?: 'auto') === 'manual'),
                'string',
                'max:255',
                Rule::unique('vouchers', 'code')
            ],
            'target_type'     => ['required', Rule::in(['all', 'user', 'community'])],
            'target_user_id'  => 'nullable|integer|exists:users,id',
            'target_user_ids' => ['exclude_unless:target_type,user', 'required_if:target_type,user', 'array', 'min:1'],
            'target_user_ids.*' => 'integer|exists:users,id',
            'community_id'    => 'nullable|required_if:target_type,community|exists:communities,id',
            'owner_name'      => 'nullable|string|max:255',
            'owner_phone'     => 'nullable|string|max:32',

            // ✨ Voucher Harian fields
            'is_daily_grab'   => 'nullable|boolean',
            'unlimited_grab'  => 'nullable|boolean',
            'start_validate'  => 'nullable|date',
            'finish_validate' => 'nullable|date|after_or_equal:start_validate',
        ], [], [
            'target_user_id'  => 'user',
            'target_user_ids' => 'daftar user',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();


            $ownerUserId = $request->input('owner_user_id');
            if ($ownerUserId) {
                $owner = User::find($ownerUserId);
                if ($owner) {
                    [$nm, $ph] = $this->extractUserNamePhone($owner);

                    if ($nm) $data['owner_name'] = $nm;
                    if ($ph) $data['owner_phone'] = $ph;
                }
            }

            unset($data['owner_user_id']);

            $explicitUserIds = $request->input('target_user_ids', []);
            if (($data['target_type'] ?? 'all') !== 'user') {
                $data['target_user_id'] = null;
            } else {
                if (is_array($explicitUserIds) && count($explicitUserIds) === 1) {
                    $data['target_user_id'] = $explicitUserIds[0];
                } elseif (empty($data['target_user_id'])) {
                    $data['target_user_id'] = null;
                }
            }

            $data['validation_type'] = $data['validation_type'] ?? 'auto';

            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('vouchers', 'public');
                $data['image_updated_at'] = now();
            }

            if ($data['validation_type'] === 'auto' && empty($data['code'])) {
                $data['code'] = $this->generateCode();
            }

            unset($data['target_user_ids']);

            $model = Voucher::create($data);
            $model->refresh();

            $notificationCount = $this->sendVoucherNotifications($model, $explicitUserIds);

            DB::commit();


            $model->refresh();
            $model = $this->addImageVersioning($model);

            return response()->json([
                'success' => true,
                'message' => "Voucher berhasil dibuat dan {$notificationCount} notifikasi terkirim",
                'data'    => $model
            ], 201)->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error creating voucher: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal membuat voucher: ' . $e->getMessage()], 500);
        }
    }


    public function validationsIndex(Request $req)
    {
        try {

            $voucherId = $req->input('voucher_id');
            $voucherCode = trim((string)$req->input('voucher_code', ''));

            $voucher = null;


            if ($voucherId) {
                $voucher = Voucher::find($voucherId);
            } elseif ($voucherCode !== '') {
                $voucher = Voucher::where('code', $voucherCode)->first();
            }

            if (!$voucher) {
                return response()->json(['data' => [], 'total_row' => 0]);
            }

            if ($req->filled('validation_type_filter')) {
                $filterType = $req->input('validation_type_filter');
                if ($filterType === 'qr_only' && $voucher->validation_type !== 'auto') {
                    return response()->json(['data' => [], 'total_row' => 0]);
                }
                if ($filterType === 'manual_only' && $voucher->validation_type !== 'manual') {
                    return response()->json(['data' => [], 'total_row' => 0]);
                }
            }

            $page        = max((int)$req->input('page', 1), 1);
            $paginate    = max((int)$req->input('paginate', 10), 1);
            $search      = trim((string)$req->input('search', ''));
            $sortBy      = $req->input('sortBy', 'validated_at');
            $sortDirRaw  = strtolower($req->input('sortDirection', 'desc'));
            $sortDir     = $sortDirRaw === 'asc' ? 'asc' : 'desc';

            $base = VoucherValidation::query()
                ->with(['user:id,name'])
                ->where('voucher_id', $voucher->id);

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

            if (Schema::hasColumn('voucher_validations', 'voucher_item_id')) {
                $rows = (clone $base)
                    ->leftJoin('voucher_items', 'voucher_items.id', '=', 'voucher_validations.voucher_item_id')
                    ->select('voucher_validations.*', 'voucher_items.user_id as owner_id', 'voucher_items.code as item_code')
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
                    $itemCode = null;
                    if ($r->notes) {
                        if (preg_match('/owner_id:(\d+)/', $r->notes, $m)) $ownerId = (int)$m[1];
                        if (preg_match('/item_code:([A-Za-z0-9\-]+)/', $r->notes, $m2)) $itemCode = $m2[1];
                    }
                    if (!$ownerId || !$itemCode) {
                        $pi = \App\Models\VoucherItem::where('voucher_id', $r->voucher_id)
                            ->where('code', $r->code)->latest('id')->first();
                        $ownerId  = $ownerId ?: ($pi?->user_id);
                        $itemCode = $itemCode ?: ($pi?->code);
                    }
                    $r->owner_id  = $ownerId;
                    $r->item_code = $itemCode;
                    return $r;
                });
            }


            $ownerIds = $rows->pluck('owner_id')->filter()->unique()->values();
            $owners   = $ownerIds->isNotEmpty()
                ? \App\Models\User::whereIn('id', $ownerIds)->get(['id', 'name'])->keyBy('id')
                : collect();

            $data = $rows->map(function ($r) use ($owners, $voucher) {
                $owner = null;
                if ($r->owner_id && isset($owners[$r->owner_id])) {
                    $owner = ['id' => $r->owner_id, 'name' => $owners[$r->owner_id]->name];
                }
                return [
                    'validated_at' => $r->validated_at,
                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    'owner'        => $owner,
                    'code'         => $voucher->code,
                    'item_code'    => $r->item_code ?? $r->code
                ];
            });

            return response()->json([
                'data'      => $data,
                'total_row' => $total,
            ]);
        } catch (\Throwable $e) {
            Log::error('voucher validationsIndex error: ' . $e->getMessage());
            return response()->json(['data' => [], 'total_row' => 0], 200);
        }
    }


    public function update(Request $request, string $id)
    {
        Log::info('📝 Update request detailed:', [
            'voucher_id' => $id,
            'has_file' => $request->hasFile('image'),
            'image_field_present' => $request->has('image'),
            'image_field_type' => gettype($request->input('image')),
            'files_count' => count($request->allFiles()),
            'all_files' => array_keys($request->allFiles()),
            'content_type' => $request->header('Content-Type'),
        ]);


        if ($request->has('image') && empty($request->input('image')) && !$request->hasFile('image')) {
            $request->request->remove('image');
        }

        $request->merge([
            'target_type' => $request->input('target_type', 'all'),
        ]);

        if ($request->has('valid_until') && $request->input('valid_until') === '') {
            $request->merge(['valid_until' => null]);
        }

        if ($request->has('target_user_ids')) {
            $tu = $request->input('target_user_ids');
            if (is_string($tu)) {
                if (str_starts_with($tu, '[')) $tu = json_decode($tu, true) ?: [];
                else $tu = explode(',', $tu);
            }
            $request->merge(['target_user_ids' => $this->normalizeUserIds($tu)]);
        }

        $request->merge([
            'community_id'   => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'target_user_id' => in_array($request->input('target_user_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('target_user_id'),
        ]);

        if ($request->input('target_type') !== 'user') {
            $request->request->remove('target_user_ids');
            $request->request->remove('target_user_id');
        }
        if ($request->input('target_type') !== 'community') {
            $request->merge(['community_id' => null]);
        }

        $validationRules = [
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'type'            => 'nullable|string',
            'valid_until'     => 'nullable|date',
            'tenant_location' => 'nullable|string|max:255',
            'stock'           => 'required|integer|min:0',
            'validation_type' => ['nullable', Rule::in(['auto', 'manual'])],
            'code'            => ['nullable', 'string', 'max:255', Rule::unique('vouchers', 'code')->ignore($id)],
            'target_type'     => ['required', Rule::in(['all', 'user', 'community'])],
            'target_user_id'  => 'nullable|integer|exists:users,id',
            'target_user_ids' => ['exclude_unless:target_type,user', 'required_if:target_type,user', 'array', 'min:1'],
            'target_user_ids.*' => 'integer|exists:users,id',
            'community_id'    => 'nullable|required_if:target_type,community|exists:communities,id',
            'owner_name'      => 'nullable|string|max:255',
            'owner_phone'     => 'nullable|string|max:32',

            // ✨ Voucher Harian fields
            'is_daily_grab'   => 'nullable|boolean',
            'unlimited_grab'  => 'nullable|boolean',
            'start_validate'  => 'nullable|date',
            'finish_validate' => 'nullable|date|after_or_equal:start_validate',
        ];

        if ($request->hasFile('image')) {
            $validationRules['image'] = 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240';
        } else {
            $validationRules['image'] = 'nullable|string';
        }

        $validator = Validator::make($request->all(), $validationRules, [], [
            'target_user_id'  => 'user',
            'target_user_ids' => 'daftar user',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed on voucher update', [
                'errors'       => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $model = Voucher::findOrFail($id);
            $data  = $validator->validated();


            $explicitUserIds = $request->input('target_user_ids', []);


            Log::info('📝 Processing image field:', [
                'has_data_image' => isset($data['image']),
                'data_image_type' => isset($data['image']) ? gettype($data['image']) : 'not_set',
                'request_has_file' => $request->hasFile('image'),
            ]);


            if (isset($data['image']) && is_string($data['image']) && !$request->hasFile('image')) {
                Log::info('🗑️ Removing string image value:', ['value' => $data['image']]);
                unset($data['image']);
            }


            if ($request->hasFile('image')) {
                Log::info('📁 Processing new image file');
                if ($model->image && Storage::disk('public')->exists($model->image)) {
                    Storage::disk('public')->delete($model->image);
                    Log::info('🗑️ Deleted old image file');
                }
                $data['image'] = $request->file('image')->store('vouchers', 'public');
                $data['image_updated_at'] = now();
                Log::info('✅ New image saved:', ['path' => $data['image']]);
            } else if (!$request->hasFile('image')) {
                $significantFields = ['name', 'description', 'stock', 'valid_until', 'target_type'];
                $hasSignificantChange = collect($significantFields)->some(function ($field) use ($data, $model) {
                    return isset($data[$field]) && $data[$field] != $model->$field;
                });

                if ($hasSignificantChange) {
                    $data['image_updated_at'] = now();
                    Log::info('🔄 Force cache bust for non-image changes');
                }
            }


            $ownerUserId = $request->input('owner_user_id');
            if ($ownerUserId) {
                $owner = User::find($ownerUserId);
                if ($owner) {
                    [$nm, $ph] = $this->extractUserNamePhone($owner);

                    if ($nm) $data['owner_name'] = $nm;
                    if ($ph) $data['owner_phone'] = $ph;

                    Log::info('✅ Manager tenant updated:', [
                        'owner_user_id' => $ownerUserId,
                        'owner_name' => $nm,
                        'owner_phone' => $ph
                    ]);
                } else {
                    Log::warning('⚠️ Manager tenant not found:', ['owner_user_id' => $ownerUserId]);
                }
            }

            unset($data['owner_user_id']);

            if ($data['validation_type'] === 'manual') {
                $finalCode = $data['code'] ?? $model->code;
                if (empty($finalCode)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Kode wajib diisi saat tipe validasi "manual".'
                    ], 422);
                }
                $data['code'] = $finalCode;
            } else {
                if (!array_key_exists('code', $data) || empty($data['code'])) {
                    $data['code'] = $model->code ?: $this->generateCode();
                }
            }

            unset($data['target_user_ids']);

            $model->fill($data)->save();
            $model->refresh();


            $notificationCount = $this->sendVoucherNotificationsToNewUsers($model, $explicitUserIds);

            DB::commit();


            $model = $this->addImageVersioning($model);

            Log::info('✅ Voucher updated successfully:', [
                'id' => $model->id,
                'new_notifications_sent' => $notificationCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => $notificationCount > 0
                    ? "Voucher berhasil diupdate dan {$notificationCount} notifikasi terkirim ke pengguna baru"
                    : "Voucher berhasil diupdate",
                'data' => $model
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('❌ Error updating voucher', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate voucher: ' . $e->getMessage()
            ], 500);
        }
    }


    public function destroy(string $id)
    {
        try {
            DB::beginTransaction();

            $model = Voucher::findOrFail($id);


            VoucherItem::where('voucher_id', $id)->delete();


            VoucherValidation::where('voucher_id', $id)->delete();


            // delete notifications that point to this voucher (both type and target_type usage)
            try {
                if (class_exists(\App\Models\Notification::class)) {
                    \App\Models\Notification::where('target_type', 'voucher')->where('target_id', $model->id)->delete();
                    \App\Models\Notification::where('type', 'voucher')->where('target_id', $model->id)->delete();
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete notifications for voucher', ['voucher_id' => $model->id, 'error' => $e->getMessage()]);
            }

            // delete qrcodes referencing this voucher
            try {
                if (class_exists(\App\Models\Qrcode::class)) {
                    \App\Models\Qrcode::where('voucher_id', $model->id)->delete();
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete qrcodes for voucher', ['voucher_id' => $model->id, 'error' => $e->getMessage()]);
            }

            if ($model->image && Storage::disk('public')->exists($model->image)) {
                Storage::disk('public')->delete($model->image);
            }

            $model->delete();

            DB::commit();

            return response(['message' => 'Voucher beserta data terkait berhasil dihapus', 'data' => $model])->header('Cache-Control', 'no-store');
        } catch (\Throwable $th) {
            DB::rollback();
            Log::error('Error deleting voucher with cascade: ' . $th->getMessage());
            return response(['message' => 'Error: gagal menghapus voucher - ' . $th->getMessage()], 500)->header('Cache-Control', 'no-store');
        }
    }


    private function sendVoucherNotifications(Voucher $voucher, array $explicitUserIds = [])
    {
        try {
            $now = now();


            $imageUrl = $voucher->image_url_versioned ?: $voucher->image_url;

            $builder = User::query();


            if (Schema::hasColumn('users', 'email_verified_at')) {
                $builder->whereNotNull('email_verified_at');
            } else {

                if (Schema::hasColumn('users', 'email_verified')) {
                    $builder->where('email_verified', true);
                } else if (Schema::hasColumn('users', 'is_verified')) {
                    $builder->where('is_verified', true);
                } else if (Schema::hasColumn('users', 'status')) {
                    $builder->where('status', 'active');
                } else {

                    Log::info('No email verification column found, skipping verification check');
                }
            }

            if ($voucher->target_type === 'user') {
                if (!empty($explicitUserIds)) {
                    $safeIds = $this->filterRegularUserIds($explicitUserIds);
                    if (empty($safeIds)) {
                        Log::info('No regular-user recipients after filtering explicit ids.');
                        return 0;
                    }
                    $builder->whereIn('id', $safeIds);
                } elseif ($voucher->target_user_id) {
                    $safeIds = $this->filterRegularUserIds([$voucher->target_user_id]);
                    if (empty($safeIds)) {
                        Log::info('Single target_user_id filtered out (not a regular user).');
                        return 0;
                    }
                    $builder->whereIn('id', $safeIds);
                } else {
                    $builder->whereRaw('1=0');
                }

                $builder = $this->applyRegularUserFilter($builder);
            } elseif ($voucher->target_type === 'community' && $voucher->community_id) {

                if (method_exists(User::class, 'communityMemberships')) {
                    $builder->whereHas('communityMemberships', function ($q) use ($voucher) {
                        $q->where('community_id', $voucher->community_id);


                        if (Schema::hasColumn('community_memberships', 'status')) {
                            $q->where('status', 'active');
                        }
                    });
                } else {
                    Log::warning('Community memberships relation not found, targeting all users instead');
                }

                $builder = $this->applyRegularUserFilter($builder);
            } else {
                $builder = $this->applyRegularUserFilter($builder);
            }

            $sent = 0;
            $builder->select('id')->chunkById(500, function ($users) use ($voucher, $now, $imageUrl, &$sent) {
                $batch = [];
                foreach ($users as $user) {
                    $batch[] = [
                        'user_id'     => $user->id,
                        'type'        => 'voucher',
                        'title'       => 'Voucher Baru Tersedia!',
                        'message'     => "Voucher '{$voucher->name}' tersedia untuk Anda.",
                        'image_url'   => $imageUrl,
                        'target_type' => 'voucher',
                        'target_id'   => $voucher->id,
                        'action_url'  => "/vouchers/{$voucher->id}",
                        'meta'        => json_encode([
                            'voucher_code' => $voucher->code,
                            'valid_until'  => $voucher->valid_until,
                            'community_id' => $voucher->community_id,
                            'target_type'  => $voucher->target_type,
                            'is_new_voucher' => true
                        ]),
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }

                if (!empty($batch)) {
                    try {
                        foreach (array_chunk($batch, 100) as $chunk) {
                            Notification::insert($chunk);
                        }
                        $sent += count($batch);
                    } catch (\Throwable $e) {
                        Log::error('Failed to insert notification batch: ' . $e->getMessage());
                    }
                }
            });

            Log::info("Voucher notifications sent (CREATE)", [
                'voucher_id' => $voucher->id,
                'voucher_name' => $voucher->name,
                'target_type' => $voucher->target_type,
                'notifications_sent' => $sent
            ]);

            return $sent;
        } catch (\Throwable $e) {
            Log::error('Error sending voucher notifications: ' . $e->getMessage(), [
                'voucher_id' => $voucher->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }


    private function sendVoucherNotificationsToNewUsers(Voucher $voucher, array $explicitUserIds = [])
    {
        try {
            $now = now();


            $imageUrl = $voucher->image_url_versioned ?: $voucher->image_url;


            $existingRecipients = Notification::where('type', 'voucher')
                ->where('target_id', $voucher->id)
                ->pluck('user_id')
                ->unique()
                ->toArray();

            Log::info('📨 Existing notification recipients:', [
                'voucher_id' => $voucher->id,
                'existing_count' => count($existingRecipients),
                'existing_users' => $existingRecipients
            ]);


            $targetUserIds = [];

            if ($voucher->target_type === 'user') {
                if (!empty($explicitUserIds)) {
                    $safeIds = $this->filterRegularUserIds($explicitUserIds);
                    $targetUserIds = $safeIds;
                } elseif ($voucher->target_user_id) {
                    $safeIds = $this->filterRegularUserIds([$voucher->target_user_id]);
                    $targetUserIds = $safeIds;
                }
            } elseif ($voucher->target_type === 'community' && $voucher->community_id) {

                $builder = User::query();


                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $builder->whereNotNull('email_verified_at');
                } else {

                    if (Schema::hasColumn('users', 'email_verified')) {
                        $builder->where('email_verified', true);
                    } else if (Schema::hasColumn('users', 'is_verified')) {
                        $builder->where('is_verified', true);
                    } else if (Schema::hasColumn('users', 'status')) {
                        $builder->where('status', 'active');
                    }
                }


                if (method_exists(User::class, 'communityMemberships')) {
                    $builder->whereHas('communityMemberships', function ($q) use ($voucher) {
                        $q->where('community_id', $voucher->community_id);
                        if (Schema::hasColumn('community_memberships', 'status')) {
                            $q->where('status', 'active');
                        }
                    });
                }

                $builder = $this->applyRegularUserFilter($builder);
                $targetUserIds = $builder->pluck('id')->toArray();
            } else {

                $builder = User::query();

                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $builder->whereNotNull('email_verified_at');
                } else {
                    if (Schema::hasColumn('users', 'email_verified')) {
                        $builder->where('email_verified', true);
                    } else if (Schema::hasColumn('users', 'is_verified')) {
                        $builder->where('is_verified', true);
                    } else if (Schema::hasColumn('users', 'status')) {
                        $builder->where('status', 'active');
                    }
                }

                $builder = $this->applyRegularUserFilter($builder);
                $targetUserIds = $builder->pluck('id')->toArray();
            }


            $newUserIds = array_diff($targetUserIds, $existingRecipients);

            if (empty($newUserIds)) {
                Log::info('📨 No new users to notify for voucher update', [
                    'voucher_id' => $voucher->id,
                    'total_targets' => count($targetUserIds),
                    'existing_recipients' => count($existingRecipients)
                ]);
                return 0;
            }

            Log::info('📨 Sending notifications to NEW users only:', [
                'voucher_id' => $voucher->id,
                'total_targets' => count($targetUserIds),
                'existing_recipients' => count($existingRecipients),
                'new_users' => count($newUserIds),
                'new_user_ids' => array_values($newUserIds)
            ]);


            $sent = 0;
            $newUsers = User::whereIn('id', $newUserIds)->select('id', 'name')->get();

            $batch = [];
            foreach ($newUsers as $user) {
                $batch[] = [
                    'user_id'     => $user->id,
                    'type'        => 'voucher',
                    'title'       => 'Voucher Baru Tersedia!',
                    'message'     => "Voucher '{$voucher->name}' tersedia untuk Anda.",
                    'image_url'   => $imageUrl,
                    'target_type' => 'voucher',
                    'target_id'   => $voucher->id,
                    'action_url'  => "/vouchers/{$voucher->id}",
                    'meta'        => json_encode([
                        'voucher_code' => $voucher->code,
                        'valid_until'  => $voucher->valid_until,
                        'community_id' => $voucher->community_id,
                        'target_type'  => $voucher->target_type,
                        'is_new_recipient' => true
                    ]),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            if (!empty($batch)) {
                try {
                    foreach (array_chunk($batch, 100) as $chunk) {
                        Notification::insert($chunk);
                    }
                    $sent = count($batch);
                } catch (\Throwable $e) {
                    Log::error('Failed to insert notification batch: ' . $e->getMessage());
                }
            }

            Log::info("Voucher update notifications sent (NEW users only)", [
                'voucher_id' => $voucher->id,
                'voucher_name' => $voucher->name,
                'target_type' => $voucher->target_type,
                'new_notifications_sent' => $sent,
                'skipped_existing' => count($existingRecipients)
            ]);

            return $sent;
        } catch (\Throwable $e) {
            Log::error('Error sending voucher notifications to new users: ' . $e->getMessage(), [
                'voucher_id' => $voucher->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }


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
            'expected_type'      => 'sometimes|in:voucher',
            'validator_role'     => 'sometimes|in:tenant',
            'validation_purpose' => 'sometimes|string',
            'qr_timestamp'       => 'sometimes',
        ]);


        $code       = (string)$data['code'];
        $itemId     = $data['item_id'] ?? null;
        $ownerHint  = $data['item_owner_id'] ?? null;


        if ($itemId) {

            $item = VoucherItem::with(['voucher', 'voucher.community', 'user'])
                ->where('id', $itemId)
                ->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Voucher item tidak ditemukan'], 404);
            }


            $codeMatches = hash_equals((string)$item->code, $code) ||
                hash_equals((string)($item->voucher->code ?? ''), $code);

            if (!$codeMatches) {
                return response()->json(['success' => false, 'message' => 'Kode unik tidak valid.'], 422);
            }
        } else {

            $voucher = Voucher::with(['community'])
                ->where('code', $code)
                ->first();

            if (!$voucher) {
                return response()->json(['success' => false, 'message' => 'Voucher dengan kode tersebut tidak ditemukan'], 404);
            }


            $item = VoucherItem::with(['voucher', 'voucher.community', 'user'])
                ->where('voucher_id', $voucher->id)
                ->where('user_id', $ownerHint ?? $user->id)
                ->whereNull('used_at')
                ->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Tidak ada voucher item yang tersedia atau sudah digunakan'], 404);
            }
        }


        if ($ownerHint && (int)$item->user_id !== (int)$ownerHint) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher item ini bukan milik user yang dimaksud'
            ], 422);
        }




        if (!empty($item->voucher?->valid_until) && now()->greaterThan($item->voucher->valid_until)) {
            return response()->json(['success' => false, 'message' => 'Voucher kedaluwarsa'], 422);
        }


        $voucher   = $item->voucher;
        $tenantId  = $this->resolveTenantUserIdByVoucher($voucher);
        $vRole     = $data['validator_role'] ?? null;

        if ($vRole === 'tenant') {
            if (!$tenantId || $user->id !== $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR ini hanya dapat divalidasi oleh tenant pemilik voucher.'
                ], 403);
            }
            $validatorId = $user->id;
        } else {

            $validatorId = $tenantId ?: $user->id;
        }


        if (!empty($item->used_at) || (Schema::hasColumn('voucher_items', 'status') && $item->status === 'used')) {
            return response()->json([
                'success' => true,
                'message' => 'Voucher sudah divalidasi sebelumnya',
                'data'    => [
                    'voucher_item_id' => $item->id,
                    'voucher_item'    => $item,
                    'voucher'         => $item->voucher,
                ],
            ], 200);
        }


        DB::beginTransaction();
        try {
            $locked = VoucherItem::with(['voucher'])
                ->where('id', $item->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Voucher item tidak ditemukan'], 404);
            }


            if (!empty($locked->used_at) || (Schema::hasColumn('voucher_items', 'status') && $locked->status === 'used')) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Voucher sudah divalidasi sebelumnya',
                    'data'    => [
                        'voucher_item_id' => $locked->id,
                        'voucher_item'    => $locked,
                        'voucher'         => $locked->voucher,
                    ],
                ], 200);
            }


            $updates = ['used_at' => now()];
            if (Schema::hasColumn('voucher_items', 'status')) $updates['status'] = 'used';

            $affected = VoucherItem::where('id', $locked->id)
                ->whereNull('used_at')
                ->update($updates);

            if ($affected === 0) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Voucher sudah divalidasi sebelumnya',
                    'data'    => [
                        'voucher_item_id' => $locked->id,
                        'voucher_item'    => $locked->fresh(),
                        'voucher'         => $locked->voucher,
                    ],
                ], 200);
            }




            $validationData = [
                'user_id'      => $validatorId,
                'voucher_id'   => $locked->voucher_id,
                'validated_at' => now(),
                'code'         => $locked->code,
                'notes'        => 'Validated item_id=' . $locked->id . ' owner=' . $locked->user_id,
            ];


            if (Schema::hasColumn('voucher_validations', 'voucher_item_id')) {
                $validationData['voucher_item_id'] = $locked->id;
            } else {

                $validationData['notes'] = 'item_id:' . $locked->id . '|owner_id:' . $locked->user_id . '|validator_id:' . $validatorId;
            }



            $existingValidation = null;
            if (Schema::hasColumn('voucher_validations', 'voucher_item_id')) {
                $existingValidation = VoucherValidation::where('voucher_item_id', $locked->id)->first();
            }

            if (!$existingValidation) {
                VoucherValidation::create($validationData);
            } else {

                $existingValidation->update([
                    'validated_at' => now(),
                    'notes' => $validationData['notes']
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validasi berhasil',
                'data'    => [
                    'voucher_item_id' => $locked->id,
                    'voucher_item'    => $locked->fresh(),
                    'voucher'         => $locked->voucher,
                ]
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('validateCode txn error: ' . $e->getMessage(), ['code' => $item->code, 'item_id' => $item->id]);
            return response()->json(['success' => false, 'message' => 'Gagal memproses validasi'], 500);
        }
    }

    public function history($voucherId)
    {
        Log::info('Fetching history for voucher ID: ' . $voucherId);

        try {
            $voucher = Voucher::find($voucherId);
            if (!$voucher) {
                return response()->json(['success' => false, 'message' => 'Voucher tidak ditemukan'], 404);
            }


            if (Schema::hasColumn('voucher_validations', 'voucher_item_id')) {

                $rows = VoucherValidation::query()
                    ->with(['voucher', 'user'])
                    ->leftJoin('voucher_items', 'voucher_items.id', '=', 'voucher_validations.voucher_item_id')
                    ->select('voucher_validations.*', 'voucher_items.user_id as owner_id')
                    ->where('voucher_validations.voucher_id', $voucherId)
                    ->orderBy('voucher_validations.validated_at', 'desc')
                    ->get();
            } else {

                $allRows = VoucherValidation::query()
                    ->with(['voucher', 'user'])
                    ->where('voucher_validations.voucher_id', $voucherId)
                    ->orderBy('voucher_validations.validated_at', 'desc')
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

                        $item = \App\Models\VoucherItem::where('code', $row->code)
                            ->where('voucher_id', $row->voucher_id)
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
                $voucher = $r->voucher;
                if ($voucher) {
                    $voucher->title = $voucher->title ?? $voucher->name ?? 'Voucher';
                }

                return [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'validated_at' => $r->validated_at,
                    'notes'        => $r->notes,
                    'voucher'      => $voucher,
                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    'owner'        => $owner,
                    'itemType'     => 'voucher',

                    'user_relationship' => 'unknown',
                    'show_owner_info' => true,
                    'show_validator_info' => false,
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error('Error in history (voucher): ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()], 500);
        }
    }

    public function voucherItems(Request $request)
    {
        // === CABANG 1: berdasarkan voucher_code (untuk tabel admin/detail) ===
        if ($request->filled('voucher_code')) {
            $code     = trim((string)$request->input('voucher_code'));
            $page     = max((int)$request->input('page', 1), 1);
            $paginate = max((int)$request->input('paginate', 10), 1);
            $sortBy   = $request->input('sortBy', 'created_at');
            $sortDir  = strtolower($request->input('sortDirection', 'desc')) === 'asc' ? 'asc' : 'desc';

            $voucher = Voucher::where('code', $code)->first();
            if (!$voucher) {
                return response()->json(['success' => true, 'data' => [], 'total_row' => 0]);
            }

            // >>> TAMBAHAN: siapkan ad_id & ad_limit dari ads
            $adId = $voucher->ad_id ?? null;
            $adLimit = $adId
                ? DB::table('ads')->where('id', $adId)->value('validation_time_limit')
                : null;

            $base = VoucherItem::with(['user:id,name', 'voucher:id,code,ad_id'])
                ->where('voucher_id', $voucher->id);

            if ($s = trim((string)$request->input('search', ''))) {
                $base->where(function ($q) use ($s) {
                    $q->where('code', 'like', "%{$s}%")
                        ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$s}%"));
                });
            }

            $total = (clone $base)->count();

            $rows = (clone $base)
                ->orderBy($sortBy, $sortDir)
                ->skip(($page - 1) * $paginate)
                ->take($paginate)
                ->get();

            $data = $rows->map(function ($r) use ($adId, $adLimit) {
                return [
                    'id'         => $r->id,
                    'user_id'    => $r->user_id,
                    'user'       => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    'code'       => $r->code,
                    'voucher_id' => $r->voucher_id,
                    'used_at'    => $r->used_at,
                    'created_at' => $r->created_at,

                    // >>> TAMBAHAN: field flat untuk FE
                    'ad_id'      => $adId,
                    'ad_limit'   => $adLimit, // format "HH:MM:SS" bila ada
                ];
            });

            return response()->json([
                'success'   => true,
                'data'      => $data,
                'total_row' => $total,
            ]);
        }

        // === CABANG 2: daftar voucher item milik user login (profil user) ===
        try {
            $userId = $request->user()?->id ?? auth()->id();
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'User tidak terautentikasi'], 401);
            }

            $voucherItems = VoucherItem::with([
                'voucher' => function ($q) {
                    // Kolom aman yang FE butuh + hindari error accessor
                    $q->select(
                        'id',
                        'code',
                        'ad_id',
                        'valid_until',
                        'name',
                        'description',
                        'image',
                        'type',
                        'tenant_location',
                        'stock',
                        'validation_type'
                    );

                    // Tambah kolom opsional kalau ada di DB (hindari error "either does not exist")
                    if (\Illuminate\Support\Facades\Schema::hasColumn('vouchers', 'image_updated_at')) {
                        $q->addSelect('image_updated_at');
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('vouchers', 'community_id')) {
                        $q->addSelect('community_id');
                    }
                },
                // load community minimal id+name (hindari select *)
                'voucher.community:id,name,description'
            ])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();
            // Matikan appends/accessor pada relasi voucher agar tidak menyentuh atribut non-selected
            $voucherItems->each(function ($vi) {
                if ($vi->relationLoaded('voucher') && $vi->voucher) {
                    $vi->voucher->setAppends([]); // cegah getImageUrl* yang butuh image_updated_at/community_id
                }
            });

            // >>> TAMBAHAN: sisipkan ad_id & ad_limit ke setiap item
            $adLimitCache = [];
            $voucherItems = $voucherItems->map(function ($it) use (&$adLimitCache) {
                $adId = $it->voucher->ad_id ?? null;
                $adLimit = null;
                if ($adId) {
                    if (!array_key_exists($adId, $adLimitCache)) {
                        $adLimitCache[$adId] = DB::table('ads')
                            ->where('id', $adId)
                            ->value('validation_time_limit');
                    }
                    $adLimit = $adLimitCache[$adId];
                }
                // set attribute agar ikut terserialisasi
                $it->setAttribute('ad_id', $adId);
                $it->setAttribute('ad_limit', $adLimit);
                return $it;
            });

            return response()->json(['success' => true, 'data' => $voucherItems]);
        } catch (\Throwable $e) {
            Log::error('Error in voucherItems: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil voucher items: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Lookup voucher berdasarkan code untuk mendapatkan item_id
     */
    public function lookupByCode(string $code)
    {
        try {

            $voucher = Voucher::where('code', $code)->first();

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher dengan kode tersebut tidak ditemukan'
                ], 404);
            }


            $voucherItems = VoucherItem::with(['voucher', 'user'])
                ->where('voucher_id', $voucher->id)
                ->where('code', $code)
                ->get();

            if ($voucherItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada voucher item aktif dengan kode tersebut'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'voucher' => $voucher,
                    'voucher_items' => $voucherItems,
                    'total_items' => $voucherItems->count()
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in lookupByCode: ' . $e->getMessage(), ['code' => $code]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencari voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    public function userValidationHistory(Request $request)
    {
        try {
            $userId = $request->user()?->id ?? auth()->id();
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'User tidak terautentikasi'], 401);
            }


            if (Schema::hasColumn('voucher_validations', 'voucher_item_id')) {

                $rows = VoucherValidation::query()
                    ->with(['voucher', 'user'])
                    ->leftJoin('voucher_items', 'voucher_items.id', '=', 'voucher_validations.voucher_item_id')
                    ->select('voucher_validations.*', 'voucher_items.user_id as owner_id')
                    ->where(function ($q) use ($userId) {
                        $q->where('voucher_items.user_id', $userId)
                            ->orWhere('voucher_validations.user_id', $userId);
                    })
                    ->orderBy('voucher_validations.validated_at', 'desc')
                    ->get();
            } else {

                $allRows = VoucherValidation::query()
                    ->with(['voucher', 'user'])
                    ->orderBy('voucher_validations.validated_at', 'desc')
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
                    if (preg_match('/owner_id:(\\d+)/', $row->notes, $matches)) {
                        $row->owner_id = (int)$matches[1];
                    } else {

                        $item = VoucherItem::where('code', $row->code)
                            ->where('voucher_id', $row->voucher_id)
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
                $voucher = $r->voucher;
                if ($voucher) {
                    $voucher->title = $voucher->title ?? $voucher->name ?? 'Voucher';
                }

                $owner = null;
                if ($r->owner_id && isset($owners[$r->owner_id])) {
                    $owner = ['id' => $r->owner_id, 'name' => $owners[$r->owner_id]->name];
                }


                $isOwner = $r->owner_id && (int)$r->owner_id === (int)$userId;
                $isValidator = $r->user_id && (int)$r->user_id === (int)$userId;

                return [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'validated_at' => $r->validated_at,
                    'notes'        => $r->notes,
                    'voucher'      => $voucher,
                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    'owner'        => $owner,
                    'itemType'     => 'voucher',

                    'user_relationship' => $isOwner ? 'owner' : ($isValidator ? 'validator' : 'unknown'),
                    'show_owner_info' => $isValidator,
                    'show_validator_info' => $isOwner,
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error("Error in userValidationHistory (voucher): " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()], 500);
        }
    }

    public function claim(Request $request, string $id)
    {
        Log::info('CLAIM_PATH=VoucherController@claim', [
            'voucher_id' => $id,
            'user_id' => $request->user()?->id,
            'at' => now()->toDateTimeString(),
        ]);
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // quick hit log to identify which endpoint is invoked from FE
        Log::info('CLAIM VC hit', [
            'voucher_id' => $id,
            'user_id' => $user->id ?? null,
        ]);

        return DB::transaction(function () use ($request, $id, $user) {
            $voucher = Voucher::lockForUpdate()->find($id);
            if (!$voucher) {
                return response()->json(['success' => false, 'message' => 'Voucher tidak ditemukan'], 404);
            }

            // Check tanggal validasi untuk voucher dengan start/finish validate
            if ($voucher->start_validate && now()->lt($voucher->start_validate)) {
                if ($nid = $request->input('notification_id')) {
                    Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
                }
                return response()->json(['success' => false, 'message' => 'Voucher belum aktif'], 410);
            }

            if ($voucher->finish_validate && now()->gt($voucher->finish_validate->endOfDay())) {
                if ($nid = $request->input('notification_id')) {
                    Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
                }
                return response()->json(['success' => false, 'message' => 'Voucher sudah berakhir'], 410);
            }

            // Check valid_until (backward compatibility)
            $validUntil = $voucher->valid_until ? Carbon::parse($voucher->valid_until) : null;
            if ($validUntil && now()->greaterThan($validUntil)) {
                if ($nid = $request->input('notification_id')) {
                    Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
                }
                return response()->json(['success' => false, 'message' => 'Voucher sudah kadaluwarsa'], 410);
            }

            // Check targeting
            if ($voucher->target_type === 'user') {
                if ($voucher->target_user_id && (int)$voucher->target_user_id !== (int)$user->id) {
                    return response()->json(['success' => false, 'message' => 'Voucher ini tidak ditujukan untuk Anda'], 403);
                }
            } elseif ($voucher->target_type === 'community' && $voucher->community_id) {
                $isMember = DB::table('community_memberships')
                    ->where('community_id', $voucher->community_id)
                    ->where('user_id', $user->id)
                    ->when(\Illuminate\Support\Facades\Schema::hasColumn('community_memberships', 'status'), fn($q) => $q->where('status', 'active'))
                    ->exists();
                if (!$isMember) {
                    return response()->json(['success' => false, 'message' => 'Voucher khusus anggota komunitas'], 403);
                }
            }

            // ✨ VOUCHER HARIAN: Check grab history untuk hari ini
            if ($voucher->is_daily_grab) {
                $today = now()->toDateString(); // Format: Y-m-d

                // Check apakah user sudah claim hari ini
                $alreadyClaimedToday = DB::table('voucher_grabs')
                    ->where('voucher_id', $voucher->id)
                    ->where('user_id', $user->id)
                    ->where('date', $today)
                    ->exists();

                if ($alreadyClaimedToday) {
                    if ($nid = $request->input('notification_id')) {
                        Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
                    }
                    return response()->json(['success' => false, 'message' => 'Anda sudah klaim voucher ini hari ini'], 409);
                }

                // ✅ PERBAIKAN: Check stok hari ini dari summary_grabs (untuk voucher terhubung ke ads)
                // atau dari voucher_grabs (untuk voucher standalone)
                $totalGrabToday = 0;
                $maxGrabPerDay = 0;

                if ($voucher->ad_id) {
                    // Voucher terhubung ke ads → gunakan summary_grabs dan ads.max_grab
                    $summary = DB::table('summary_grabs')
                        ->where('ad_id', $voucher->ad_id)
                        ->where('date', $today)
                        ->first();

                    $totalGrabToday = $summary ? $summary->total_grab : 0;
                    $maxGrabPerDay = $voucher->ad ? $voucher->ad->max_grab : 0;
                } else {
                    // Voucher standalone → gunakan voucher_grabs dan voucher.stock
                    $totalGrabToday = DB::table('voucher_grabs')
                        ->where('voucher_id', $voucher->id)
                        ->where('date', $today)
                        ->sum('total_grab');

                    $maxGrabPerDay = $voucher->stock;
                }

                $remaining = $voucher->unlimited_grab ? null : ((int)$maxGrabPerDay - (int)$totalGrabToday);

                Log::info('📊 Voucher harian stock check', [
                    'voucher_id' => $voucher->id,
                    'ad_id' => $voucher->ad_id,
                    'max_grab_per_day' => $maxGrabPerDay,
                    'total_grab_today' => $totalGrabToday,
                    'remaining' => $remaining,
                    'unlimited' => $voucher->unlimited_grab,
                    'date' => $today,
                ]);

                if (!$voucher->unlimited_grab && $remaining <= 0) {
                    if ($nid = $request->input('notification_id')) {
                        Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
                    }
                    return response()->json(['success' => false, 'message' => 'Voucher hari ini sudah habis'], 410);
                }

                // Create voucher item
                $item = VoucherItem::create([
                    'voucher_id' => $voucher->id,
                    'user_id'    => $user->id,
                    'code'       => 'VI-' . strtoupper(\Illuminate\Support\Str::random(10)),
                ]);

                // Insert ke voucher_grabs untuk tracking harian
                DB::table('voucher_grabs')->insert([
                    'voucher_id' => $voucher->id,
                    'user_id'    => $user->id,
                    'date'       => $today,
                    'total_grab' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Log::info('✅ Voucher harian claimed', [
                    'voucher_id' => $voucher->id,
                    'user_id' => $user->id,
                    'date' => now()->toDateString(),
                    'remaining_today' => $remaining - 1,
                ]);
            } else {
                // ✨ VOUCHER NORMAL: Check double claim (backward compatibility)
                if (VoucherItem::where('voucher_id', $voucher->id)->where('user_id', $user->id)->exists()) {
                    if ($nid = $request->input('notification_id')) {
                        Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
                    }
                    return response()->json(['success' => true, 'message' => 'Voucher sudah pernah diklaim'], 200);
                }

                // Check stock
                if (!$voucher->unlimited_grab && !is_null($voucher->stock) && (int)$voucher->stock <= 0) {
                    if ($nid = $request->input('notification_id')) {
                        Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
                    }
                    return response()->json(['success' => false, 'message' => 'Voucher habis (out of stock)'], 410);
                }

                // Create voucher item
                $item = VoucherItem::create([
                    'voucher_id' => $voucher->id,
                    'user_id'    => $user->id,
                    'code'       => 'VI-' . strtoupper(\Illuminate\Support\Str::random(10)),
                ]);

                // Decrement stock untuk voucher normal
                if (!$voucher->unlimited_grab && !is_null($voucher->stock)) {
                    $voucher->decrement('stock');
                }

                Log::info('VOUCHER_DEC_AFTER', [
                    'id' => $voucher->id,
                    'stock_db' => DB::table('vouchers')->where('id', $voucher->id)->value('stock'),
                ]);
            }

            // --- Sync ke ads.max_grab (jika voucher terhubung ke ads) ---
            if (!is_null($voucher->ad_id)) {
                $ad = \App\Models\Ad::find($voucher->ad_id);

                // Hanya sync jika ads juga daily grab
                if ($ad && $ad->is_daily_grab) {
                    // ✅ PERBAIKAN: Gunakan updateOrCreate untuk increment daily grab
                    $summary = \App\Models\SummaryGrab::firstOrNew([
                        'ad_id' => $ad->id,
                        'date'  => now()->toDateString(),
                    ]);

                    if ($summary->exists) {
                        // Jika sudah ada, increment
                        $summary->increment('total_grab', 1);
                    } else {
                        // Jika baru, set ke 1
                        $summary->total_grab = 1;
                        $summary->save();
                    }

                    Log::info('INC summary_grabs for voucher daily grab', [
                        'ad_id' => $ad->id,
                        'date' => now()->toDateString(),
                        'total_grab_now' => $summary->fresh()->total_grab,
                    ]);
                } else {
                    // Kurangi max_grab untuk ads normal (TIDAK boleh untuk daily grab!)
                    $affected = DB::table('ads')
                        ->where('id', $voucher->ad_id)
                        ->whereNotNull('max_grab')
                        ->where('max_grab', '>', 0)
                        ->where('is_daily_grab', 0) // ← PENTING: Hanya untuk non-daily
                        ->decrement('max_grab', 1);

                    if ($affected > 0) {
                        DB::table('ads')->where('id', $voucher->ad_id)->update(['updated_at' => now()]);
                        Log::info('DEC ads.max_grab after claim', [
                            'ad_id' => $voucher->ad_id,
                            'max_grab_now' => DB::table('ads')->where('id', $voucher->ad_id)->value('max_grab'),
                        ]);
                    }
                }
            }

            if ($nid = $request->input('notification_id')) {
                Notification::where('id', $nid)->where('user_id', $user->id)->update(['read_at' => now()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diklaim',
                'data'    => ['voucher' => $voucher->fresh(), 'item' => $item],
            ]);
        });
    }
}
