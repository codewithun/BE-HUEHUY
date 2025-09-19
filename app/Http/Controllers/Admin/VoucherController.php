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

class VoucherController extends Controller
{
    // ================= Helpers =================
    private function normalizeUserIds($raw): array
    {
        if (is_null($raw) || $raw === '') return [];
        $arr = is_array($raw) ? $raw : preg_split('/[,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        return collect($arr)->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
    }

    private function generateCode(): string
    {
        do {
            $code = 'VCR-' . strtoupper(Str::random(8));
        } while (Voucher::where('code', $code)->exists());
        return $code;
    }

    // ================= Index / Show =================
    public function index(Request $request)
    {
        $sortDirection = $request->get('sortDirection', 'DESC');
        $sortby        = $request->get('sortBy', 'created_at');
        $paginate      = $request->get('paginate', 10);
        $filter        = $request->get('filter', null);

        $query = Voucher::with(['community'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->get('search');
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', "%{$s}%")
                       ->orWhere('code', 'like', "%{$s}%");
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
                } else {
                    $query->where($column, 'like', '%' . $value . '%');
                }
            }
        }

        $data = $query->orderBy($sortby, $sortDirection)->paginate($paginate);

        if (empty($data->items())) {
            return response(['message' => 'empty data','data' => []], 200);
        }

        return response(['message' => 'success','data' => $data->items(),'total_row' => $data->total()]);
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

        return response(['message' => 'Success','data' => $model]);
    }

    /// ================= Store =================
public function store(Request $request)
{
    Log::info('Store voucher request data:', $request->all());

    // --- normalisasi awal ---
    // image: jika string kosong & bukan file, buang
    if ($request->has('image') && empty($request->input('image')) && !$request->hasFile('image')) {
        $request->request->remove('image');
    }

    // default target_type = all jika kosong
    $request->merge([
        'target_type' => $request->input('target_type', 'all'),
    ]);

    // valid_until kosong -> null (biar lolos nullable|date)
    if ($request->has('valid_until') && $request->input('valid_until') === '') {
        $request->merge(['valid_until' => null]);
    }

    // handle target_user_ids dari berbagai format
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

    // community_id: mapping nilai "kosong"
    $request->merge([
        'community_id'   => in_array($request->input('community_id'), [null,'','null','undefined'], true) ? null : $request->input('community_id'),
        'target_user_id' => in_array($request->input('target_user_id'), [null,'','null','undefined'], true) ? null : $request->input('target_user_id'),
    ]);

    // kalau bukan user, singkirkan field user targeting
    if ($request->input('target_type') !== 'user') {
        $request->request->remove('target_user_ids');
        $request->request->remove('target_user_id');
    }
    // kalau bukan community, kosongkan community_id
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

        'validation_type' => ['nullable', Rule::in(['auto','manual'])],
        'code'            => [
            Rule::requiredIf(fn () => ($request->input('validation_type') ?: 'auto') === 'manual'),
            'string','max:255', Rule::unique('vouchers','code')
        ],

        'target_type'     => ['required', Rule::in(['all','user','community'])],
        'target_user_id'  => 'nullable|integer|exists:users,id',
        // KUNCI: exclude_unless agar rule lain tidak dieksekusi bila bukan user
        'target_user_ids' => ['exclude_unless:target_type,user', 'required_if:target_type,user', 'array', 'min:1'],
        'target_user_ids.*' => 'integer|exists:users,id',
        'community_id'    => 'nullable|required_if:target_type,community|exists:communities,id',
    ], [], [
        'target_user_id'  => 'user',
        'target_user_ids' => 'daftar user',
    ]);

    if ($validator->fails()) {
        return response()->json(['success'=>false,'message'=>'Validasi gagal','errors'=>$validator->errors()], 422);
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
        return response()->json(['success'=>false,'message'=>'Gagal membuat voucher: ' . $e->getMessage()], 500);
    }
}


    // ================= Update =================
public function update(Request $request, string $id)
{
    Log::info('Update voucher request data:', $request->all());

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
        'community_id'   => in_array($request->input('community_id'), [null,'','null','undefined'], true) ? null : $request->input('community_id'),
        'target_user_id' => in_array($request->input('target_user_id'), [null,'','null','undefined'], true) ? null : $request->input('target_user_id'),
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

        'validation_type' => ['nullable', Rule::in(['auto','manual'])],
        'code'            => ['nullable','string','max:255', Rule::unique('vouchers','code')->ignore($id)],

        'target_type'     => ['required', Rule::in(['all','user','community'])],
        'target_user_id'  => 'nullable|integer|exists:users,id',
        // KUNCI: exclude_unless + required_if
        'target_user_ids' => ['exclude_unless:target_type,user', 'required_if:target_type,user', 'array', 'min:1'],
        'target_user_ids.*' => 'integer|exists:users,id',
        'community_id'    => 'nullable|required_if:target_type,community|exists:communities,id',
    ], [], [
        'target_user_id'  => 'user',
        'target_user_ids' => 'daftar user',
    ]);

    if ($validator->fails()) {
        return response()->json(['success'=>false,'message'=>'Validasi gagal','errors'=>$validator->errors()], 422);
    }

    try {
        DB::beginTransaction();

        $model = Voucher::findOrFail($id);
        $data  = $validator->validated();

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
        return response()->json(['success'=>true,'message'=>'Voucher berhasil diupdate','data'=>$model]);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json(['success'=>false,'message'=>'Gagal mengupdate voucher: ' . $e->getMessage()], 500);
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

            return response(['message'=>'Success','data'=>$model]);
        } catch (\Throwable $th) {
            return response(['message'=>'Error: server side having problem!'], 500);
        }
    }

    // ================= Notifications =================
    private function sendVoucherNotifications(Voucher $voucher, array $explicitUserIds = [])
    {
        try {
            $now      = now();
            $imageUrl = $voucher->image ? asset('storage/' . $voucher->image) : null;

            $builder = User::query()->whereNotNull('verified_at');

            if ($voucher->target_type === 'user') {
                if (!empty($explicitUserIds)) {
                    $builder->whereIn('id', $explicitUserIds);
                } elseif ($voucher->target_user_id) {
                    $builder->where('id', $voucher->target_user_id);
                } else {
                    $builder->whereRaw('1=0');
                }
            } elseif ($voucher->target_type === 'community' && $voucher->community_id) {
                $builder->whereHas('communityMemberships', function($q) use ($voucher) {
                    $q->where('community_id', $voucher->community_id)->where('status', 'active');
                });
            }

            $sent = 0;
            $builder->select('id')->chunkById(500, function ($users) use ($voucher, $now, $imageUrl, &$sent) {
                $batch = [];
                foreach ($users as $user) {
                    $batch[] = [
                        'user_id'     => $user->id,
                        'type'        => 'voucher',
                        'title'       => 'Voucher Baru Tersedia!',
                        'message'     => "Voucher '{$voucher->name}' tersedia untuk Anda. Gunakan kode: {$voucher->code}",
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
                        Notification::insert($chunk);
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
    public function validateCode(Request $request)
    {
        try {
            $request->validate(['code' => 'required|string']);

            $voucher = Voucher::where('code', $request->code)->first();
            if (!$voucher) {
                return response()->json(['success'=>false,'message'=>'Kode voucher tidak ditemukan'], 404);
            }

            $userId = $request->user()?->id ?? auth()->id() ?? null;

            $existingValidation = VoucherValidation::where([
                'voucher_id' => $voucher->id,
                'user_id'    => $userId,
                'code'       => $request->code
            ])->first();

            if ($existingValidation) {
                return response()->json(['success'=>false,'message'=>'Kode voucher sudah pernah divalidasi'], 409);
            }

            $validation = VoucherValidation::create([
                'voucher_id'   => $voucher->id,
                'user_id'      => $userId,
                'code'         => $request->code,
                'validated_at' => now(),
                'notes'        => $request->input('notes'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kode voucher valid',
                'data'    => ['voucher' => $voucher,'validation' => $validation]
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in validateCode: ' . $e->getMessage());
            return response()->json(['success'=>false,'message'=>'Gagal memvalidasi kode: ' . $e->getMessage()], 500);
        }
    }

    public function history($voucherId)
    {
        Log::info('Fetching history for voucher ID: ' . $voucherId);

        try {
            $voucher = Voucher::with(['validations.user'])->find($voucherId);

            if (!$voucher) {
                return response()->json(['success'=>false,'message'=>'Voucher tidak ditemukan'], 404);
            }

            $validations = $voucher->validations()
                ->with(['user', 'voucher'])
                ->orderBy('validated_at', 'desc')
                ->get();

            return response()->json(['success'=>true,'data'=>$validations]);
        } catch (\Throwable $e) {
            Log::error('Error in history method: ' . $e->getMessage());
            return response()->json(['success'=>false,'message'=>'Gagal mengambil riwayat validasi: ' . $e->getMessage()], 500);
        }
    }

    public function voucherItems(Request $request)
    {
        try {
            $userId = $request->user()?->id ?? auth()->id();

            if (!$userId) {
                return response()->json(['success'=>false,'message'=>'User tidak terautentikasi'], 401);
            }

            $voucherItems = VoucherItem::with(['voucher.community'])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['success'=>true,'data'=>$voucherItems]);
        } catch (\Throwable $e) {
            Log::error('Error in voucherItems: ' . $e->getMessage());
            return response()->json(['success'=>false,'message'=>'Gagal mengambil voucher items: ' . $e->getMessage()], 500);
        }
    }
}
