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
use Illuminate\Support\Collection; // pastikan ada di imports

class VoucherController extends Controller
{
    // ================= Helpers =================
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

    /**
     * Filter builder ke "regular user" (bukan admin/superadmin/manager/tenant_manager/staff/owner).
     * Flexible: dukung kolom role/level/type, roles (json/string), serta flags boolean umum.
     * UPDATED: Tambahan support untuk relasi role()->name
     */
    private function applyRegularUserFilter(\Illuminate\Database\Eloquent\Builder $builder): \Illuminate\Database\Eloquent\Builder
    {
        $deny = ['admin', 'superadmin', 'manager', 'tenant', 'tenant_manager', 'manager_tenant', 'staff', 'owner', 'operator', 'moderator'];

        // 1) Cek relasi role()->name (jika ada)
        if (Schema::hasTable('roles') && method_exists(\App\Models\User::class, 'role')) {
            $builder->whereHas('role', function ($q) {
                $q->where(DB::raw('LOWER(name)'), 'user');
            });
        }
        // 2) Fallback ke kolom langsung di users table
        elseif (Schema::hasColumn('users', 'role')) {
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

        // 3) Hardening dengan flags boolean (jika ada)
        $boolFalse = function ($q, $col) {
            $q->where(function ($qq) use ($col) {
                $qq->whereNull($col)->orWhere($col, false)->orWhere($col, 0)->orWhere($col, '0');
            });
        };

        if (Schema::hasColumn('users', 'is_admin')) {
            $boolFalse($builder, 'is_admin');
        }
        if (Schema::hasColumn('users', 'is_superadmin')) {
            $boolFalse($builder, 'is_superadmin');
        }
        if (Schema::hasColumn('users', 'is_staff')) {
            $boolFalse($builder, 'is_staff');
        }
        if (Schema::hasColumn('users', 'is_manager')) {
            $boolFalse($builder, 'is_manager');
        }
        if (Schema::hasColumn('users', 'is_tenant_manager')) {
            $boolFalse($builder, 'is_tenant_manager');
        }
        if (Schema::hasColumn('users', 'tenant_manager')) {
            $boolFalse($builder, 'tenant_manager');
        }

        return $builder;
    }

    /**
     * Dari daftar ID, ambil yang benar-benar "regular user" saja.
     */
    private function filterRegularUserIds(array $ids): array
    {
        if (empty($ids)) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $q = \App\Models\User::query()->whereIn('id', $ids);
        $q = $this->applyRegularUserFilter($q);

        return $q->pluck('id')->map(fn($v) => (int) $v)->all();
    }

    /**
     * Hydrate audience voucher dari tabel notifications (type=voucher).
     * Menempelkan:
     * - target_user_ids (array<int>)
     * - target_user_names (array<string>, maks 5 nama)
     * - target_user_total (int)
     */
    private function hydrateAudience(Collection $vouchers): Collection
    {
        if ($vouchers->isEmpty()) return $vouchers;

        // Ambil hanya voucher dengan target_type 'user'
        $userTargetVoucherIds = $vouchers
            ->filter(fn($v) => ($v->target_type ?? null) === 'user')
            ->pluck('id')
            ->values();

        if ($userTargetVoucherIds->isEmpty()) return $vouchers;

        // Kumpulkan user penerima dari notifications
        $notifRows = Notification::where('type', 'voucher')
            ->whereIn('target_id', $userTargetVoucherIds) // target_id = voucher_id
            ->select('target_id', 'user_id')
            ->get()
            ->groupBy('target_id');

        // Ambil nama user sekali fetch
        $allUserIds = $notifRows->flatMap(fn($rows) => $rows->pluck('user_id'))->unique()->values();
        $userNames = User::whereIn('id', $allUserIds)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn($u) => [$u->id => ($u->name ?: $u->email ?: ('#' . $u->id))]);

        // Tempel ke tiap voucher
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

    // ================= Index / Show =================
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
                        ->orWhere('code', 'like', "%{$s}%");
                });
            });

        // Filter berdasarkan admin jika diperlukan
        // Uncomment jika ada kolom admin_id di tabel vouchers
        // $query->where('admin_id', $adminId);

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
                } else {
                    $query->where($column, 'like', '%' . $value . '%');
                }
            }
        }

        // Handle "all" logic - if paginate=0 or all=1, use get() instead of paginate()
        if ($paginate <= 0 || $request->boolean('all')) {
            $items = $query->orderBy($sortby, $sortDirection)->get();

            // >>> penting: isi daftar penerima untuk target_type 'user'
            $items = $this->hydrateAudience($items);

            return response([
                'message'   => 'success',
                'data'      => $items,
                'total_row' => $items->count(),
            ]);
        }

        // Regular pagination
        $page = $query->orderBy($sortby, $sortDirection)->paginate($paginate);

        if (empty($page->items())) {
            return response([
                'message' => 'empty data',
                'data'    => [],
            ], 200);
        }

        $items = collect($page->items());

        // >>> penting: isi daftar penerima untuk target_type 'user'
        $items = $this->hydrateAudience($items);

        return response([
            'message'   => 'success',
            'data'      => $items->values(),
            'total_row' => $page->total(),
        ]);
    }

    /**
     * For dropdown usage - returns minimal voucher data
     */
    public function forDropdown(Request $request)
    {
        try {
            $query = Voucher::select('id', 'name', 'code', 'community_id', 'target_type')
                ->when($request->filled('community_id'), function ($q) use ($request) {
                    $q->where('community_id', $request->get('community_id'));
                })
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('name', 'like', "%{$s}%")
                            ->orWhere('code', 'like', "%{$s}%");
                    });
                });

            $vouchers = $query->orderBy('name', 'ASC')->get();

            $formattedVouchers = $vouchers->map(function ($voucher) {
                return [
                    'value' => $voucher->id,
                    'label' => $voucher->name,
                    'code' => $voucher->code,
                    'community_id' => $voucher->community_id,
                    'target_type' => $voucher->target_type,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'success',
                'data' => $formattedVouchers,
                'count' => $formattedVouchers->count(),
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
            return response(['messaege' => 'Data not found'], 404);
        }

        return response(['message' => 'Success', 'data' => $model]);
    }

    /// ================= Store =================
    public function store(Request $request)
    {
        Log::info('Store voucher request data:', $request->all());

        // --- normalisasi awal ---
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
                if (str_starts_with($tu, '[')) {
                    $tu = json_decode($tu, true) ?: [];
                } else {
                    $tu = explode(',', $tu);
                }
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

        // --- VALIDASI ---
        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
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
            }

            if ($data['validation_type'] === 'auto' && empty($data['code'])) {
                $data['code'] = $this->generateCode();
            }

            unset($data['target_user_ids']);

            $model = Voucher::create($data);

            $notificationCount = $this->sendVoucherNotifications($model, $explicitUserIds);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Voucher berhasil dibuat dan {$notificationCount} notifikasi terkirim",
                'data'    => $model
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error creating voucher: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal membuat voucher: ' . $e->getMessage()], 500);
        }
    }

    // ================= Update =================
    public function update(Request $request, string $id)
    {
        Log::info('Update voucher request data:', $request->all());

        // Normalize data
        $request->merge([
            'target_type' => $request->input('target_type', 'all'),
        ]);

        if ($request->has('valid_until') && $request->input('valid_until') === '') {
            $request->merge(['valid_until' => null]);
        }

        if ($request->has('target_user_ids')) {
            $tu = $request->input('target_user_ids');
            if (is_string($tu)) {
                if (str_starts_with($tu, '[')) {
                    $tu = json_decode($tu, true) ?: [];
                } else {
                    $tu = explode(',', $tu);
                }
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

        // Create dynamic validation rules based on whether image is being uploaded
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
        ];

        // Only validate image as file if it's actually being uploaded
        if ($request->hasFile('image')) {
            $validationRules['image'] = 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048';
        } else {
            // Allow image field to be a string (existing URL) or null
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

            // Remove image from data if it's just a URL string (not a new upload)
            if (isset($data['image']) && is_string($data['image']) && !$request->hasFile('image')) {
                unset($data['image']);
            }

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

            $incomingType = $data['validation_type'] ?? $model->validation_type ?? 'auto';
            $data['validation_type'] = $incomingType;

            // Only handle file upload if there's actually a new file
            if ($request->hasFile('image')) {
                if ($model->image && Storage::disk('public')->exists($model->image)) {
                    Storage::disk('public')->delete($model->image);
                }
                $data['image'] = $request->file('image')->store('vouchers', 'public');
            }

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

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diupdate',
                'data' => $model
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error updating voucher', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    // ================= Destroy =================
    public function destroy(string $id)
    {
        try {
            $model = Voucher::findOrFail($id);
            if ($model->image && Storage::disk('public')->exists($model->image)) {
                Storage::disk('public')->delete($model->image);
            }
            $model->delete();

            return response(['message' => 'Success', 'data' => $model]);
        } catch (\Throwable $th) {
            return response(['message' => 'Error: server side having problem!'], 500);
        }
    }

    // ================= Notifications =================
    private function sendVoucherNotifications(Voucher $voucher, array $explicitUserIds = [])
    {
        try {
            $now      = now();
            $imageUrl = $voucher->image ? asset('storage/' . $voucher->image) : null;

            // mulai dari user terverifikasi
            $builder = User::query()->whereNotNull('verified_at');

            if ($voucher->target_type === 'user') {
                // 1) jika ada explicit IDs, tetap DI-SARING lagi ke regular user only
                if (!empty($explicitUserIds)) {
                    $safeIds = $this->filterRegularUserIds($explicitUserIds);
                    if (empty($safeIds)) {
                        // tidak ada penerima valid
                        Log::info('No regular-user recipients after filtering explicit ids.');
                        return 0;
                    }
                    $builder->whereIn('id', $safeIds);
                }
                // 2) jika hanya single target_user_id di master, tetap DI-SARING juga
                elseif ($voucher->target_user_id) {
                    $safeIds = $this->filterRegularUserIds([$voucher->target_user_id]);
                    if (empty($safeIds)) {
                        Log::info('Single target_user_id filtered out (not a regular user).');
                        return 0;
                    }
                    $builder->whereIn('id', $safeIds);
                }
                // 3) tidak ada target user -> kosong
                else {
                    $builder->whereRaw('1=0');
                }

                // **tambahan pengaman**: walau 'user', pastikan itu benar-benar user biasa
                // (sudah difilter di filterRegularUserIds, tapi untuk extra safety)
                $builder = $this->applyRegularUserFilter($builder);
            } elseif ($voucher->target_type === 'community' && $voucher->community_id) {
                $builder->whereHas('communityMemberships', function ($q) use ($voucher) {
                    $q->where('community_id', $voucher->community_id)->where('status', 'active');
                });
                // community juga wajib regular user
                $builder = $this->applyRegularUserFilter($builder);
            } else {
                // === 'all' atau nilai lain ===
                // <<<< FIX PENTING: HANYA REGULAR USERS >>>>
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
                            'target_type'  => $voucher->target_type
                        ]),
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }

                if (!empty($batch)) {
                    foreach (array_chunk($batch, 100) as $chunk) {
                        \App\Models\Notification::insert($chunk);
                    }
                    $sent += count($batch);
                }
            });

            Log::info("Voucher notifications sent", [
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


    // ================= Validate Code / History / Items =================
    /**
     * Idempotent validation:
     * - Cari VoucherItem dari code / item_id hint / (voucher master + owner)
     * - Update used_at (+ status='used' jika kolom ada)
     * - Kalau sudah digunakan, balas 200 OK (bukan 409)
     */
    public function validateCode(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'code'               => 'required|string',
            'is_tenant_validation' => 'sometimes|boolean',
            'validator_role'     => 'sometimes|in:tenant',
            'item_id'            => 'sometimes|integer',
            'item_owner_id'      => 'sometimes|integer',
            'expected_type'      => 'sometimes|in:voucher',
            'validation_purpose' => 'sometimes|string',
            'qr_timestamp'       => 'sometimes',
        ], [
            'code.required' => 'Kode voucher wajib diisi.',
        ]);

        $code       = trim($data['code']);
        $itemIdHint = $data['item_id']      ?? null;
        $ownerHint  = $data['item_owner_id'] ?? null;

        // 1) by voucher_items.code
        $item = \App\Models\VoucherItem::with(['voucher', 'voucher.community', 'user'])
            ->where('code', $code)
            ->first();

        // 2) fallback: by item_id hint
        if (! $item && $itemIdHint) {
            $item = \App\Models\VoucherItem::with(['voucher', 'voucher.community', 'user'])->find($itemIdHint);
        }

        // 3) fallback: by master voucher code + owner
        if (! $item) {
            $voucher = \App\Models\Voucher::where('code', $code)->first();
            if ($voucher && $ownerHint) {
                $item = \App\Models\VoucherItem::with(['voucher', 'voucher.community', 'user'])
                    ->where('voucher_id', $voucher->id)
                    ->where('user_id', $ownerHint)
                    ->latest('id')
                    ->first();
            }
        }

        $inputCode  = trim((string) ($data['code'] ?? $code));
        $actualCode = (string) $item->code;

        if (!hash_equals($actualCode, $inputCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode unik tidak valid.'
            ], 422);
        }

        // optional expiry check
        if (!empty($item->voucher?->valid_until) && now()->greaterThan($item->voucher->valid_until)) {
            return response()->json(['success' => false, 'message' => 'Voucher kedaluwarsa'], 422);
        }

        // idempotent
        if (!is_null($item->used_at) || ($item->status ?? null) === 'used' || ($item->status ?? null) === 'redeemed') {
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

        \Illuminate\Support\Facades\DB::transaction(function () use ($item, $user, $code, $request) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('voucher_items', 'used_at')) {
                $item->used_at = now();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('voucher_items', 'status')) {
                $item->status = 'used';
            }
            $item->save();

            // jejak validasi (abaikan jika tabel tidak ada)
            try {
                \App\Models\VoucherValidation::create([
                    'voucher_id'   => $item->voucher_id,
                    'user_id'      => $user->id,
                    'code'         => $code,
                    'validated_at' => now(),
                    'notes'        => $request->input('validation_purpose'),
                ]);
            } catch (\Throwable $e) {
            }
        });

        $item->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Validasi berhasil',
            'data'    => [
                'voucher_item_id' => $item->id,
                'voucher_item'    => $item,
                'voucher'         => $item->voucher,
            ],
        ], 200);
    }

    public function history($voucherId)
    {
        Log::info('Fetching history for voucher ID: ' . $voucherId);

        try {
            $voucher = Voucher::with(['validations.user'])->find($voucherId);

            if (!$voucher) {
                return response()->json(['success' => false, 'message' => 'Voucher tidak ditemukan'], 404);
            }

            $validations = $voucher->validations()
                ->with(['user', 'voucher'])
                ->orderBy('validated_at', 'desc')
                ->get();

            return response()->json(['success' => true, 'data' => $validations]);
        } catch (\Throwable $e) {
            Log::error('Error in history method: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()], 500);
        }
    }

    public function voucherItems(Request $request)
    {
        try {
            $userId = $request->user()?->id ?? auth()->id();

            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'User tidak terautentikasi'], 401);
            }

            $voucherItems = VoucherItem::with(['voucher.community'])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['success' => true, 'data' => $voucherItems]);
        } catch (\Throwable $e) {
            Log::error('Error in voucherItems: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil voucher items: ' . $e->getMessage()], 500);
        }
    }

    public function userValidationHistory(Request $request)
    {
        try {
            $userId = $request->user()?->id ?? auth()->id();

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi'
                ], 401);
            }

            // Ambil riwayat validasi milik user (validator) yang login
            $rows = \App\Models\VoucherValidation::with(['voucher', 'user'])
                ->where('user_id', $userId)
                ->orderBy('validated_at', 'desc')
                ->get();

            // (Opsional) samakan shape dengan FE agar judul tampil:
            // Voucher punya kolom "name", FE kadang membaca "title".
            $data = $rows->map(function ($r) {
                $voucher = $r->voucher;
                if ($voucher) {
                    // buat alias title agar FE tidak kosong
                    $voucher->title = $voucher->title ?? $voucher->name ?? 'Voucher';
                }
                return [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'validated_at' => $r->validated_at,
                    'notes'        => $r->notes,
                    'voucher'      => $voucher,
                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    'itemType'     => 'voucher', // supaya FE gampang merge
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error("Error in userValidationHistory (voucher): " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()
            ], 500);
        }
    }
}
