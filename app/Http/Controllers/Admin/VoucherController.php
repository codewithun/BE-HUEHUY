<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherItem;
use App\Models\VoucherValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VoucherController extends Controller
{
    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
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

                // support "community_id:123" style
                if ($column === 'community_id') {
                    $filterVal = is_string($value) && str_contains($value, ':')
                        ? explode(':', $value)[1]
                        : $value;
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
            return response([
                'message' => 'empty data',
                'data'    => [],
            ], 200);
        }

        return response([
            'message'   => 'success',
            'data'      => $data->items(),
            'total_row' => $data->total(),
        ]);
    }

    public function show(string $id)
    {
        $model = Voucher::with([
                'community',
                'voucher_items',
                'voucher_items.user',
            ])
            ->where('id', $id)
            ->first();

        if (!$model) {
            return response([
                'messaege' => 'Data not found'
            ], 404);
        }

        return response([
            'message' => 'Success',
            'data'    => $model
        ]);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // basic
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'type'            => 'nullable|string',
            'valid_until'     => 'nullable|date',
            'tenant_location' => 'nullable|string|max:255',
            'stock'           => 'required|integer|min:0',
            'code'            => 'required|string|max:255|unique:vouchers,code',
            'community_id'    => 'nullable|exists:communities,id',

            // targeting (replace delivery)
            'target_type'     => 'required|in:all,user,community',
            'target_user_id'  => 'nullable|required_if:target_type,user|exists:users,id',
        ], [], [
            'target_user_id'  => 'user'
        ]);

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

            // handle image upload
            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('vouchers', 'public');
            }

            // jika target_type != community, pastikan community_id nullable
            if (($data['target_type'] ?? 'all') !== 'community') {
                // biarin community_id kalau kamu memang mau tempelkan; kalau tidak dipakai, set null:
                // $data['community_id'] = null;
            }

            $model = Voucher::create($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil dibuat',
                'data'    => $model
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            // basic
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'type'            => 'nullable|string',
            'valid_until'     => 'nullable|date',
            'tenant_location' => 'nullable|string|max:255',
            'stock'           => 'required|integer|min:0',
            'code'            => 'required|string|max:255|unique:vouchers,code,' . $id,
            'community_id'    => 'nullable|exists:communities,id',

            // targeting
            'target_type'     => 'required|in:all,user,community',
            'target_user_id'  => 'nullable|required_if:target_type,user|exists:users,id',
        ], [], [
            'target_user_id'  => 'user'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $model = Voucher::findOrFail($id);
            $data  = $validator->validated();

            if ($request->hasFile('image')) {
                if ($model->image && Storage::disk('public')->exists($model->image)) {
                    Storage::disk('public')->delete($model->image);
                }
                $data['image'] = $request->file('image')->store('vouchers', 'public');
            }

            // sanitize community id vs target_type
            if (($data['target_type'] ?? 'all') !== 'community') {
                // $data['community_id'] = null; // opsional
            }

            $model->fill($data)->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diupdate',
                'data'    => $model
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        try {
            $model = Voucher::findOrFail($id);

            if ($model->image && Storage::disk('public')->exists($model->image)) {
                Storage::disk('public')->delete($model->image);
            }

            $model->delete();

            return response([
                'message' => 'Success',
                'data'    => $model
            ]);
        } catch (\Throwable $th) {
            return response([
                'message' => 'Error: server side having problem!'
            ], 500);
        }
    }

    /**
     * Kirim 1 voucher item ke user tertentu (manual push).
     * NB: ini contoh sederhana; kustom sesuai kebutuhan.
     */
    public function sendToUser(Request $request, $voucherId)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ], [], ['user_id' => 'user']);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $voucher = Voucher::findOrFail($voucherId);

            if ($voucher->stock <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock voucher habis!'
                ], 400);
            }

            $voucherItem = new VoucherItem();
            $voucherItem->user_id    = $request->user_id;
            $voucherItem->voucher_id = $voucher->id;
            $voucherItem->code       = $voucher->code; // atau generate unik per item
            $voucherItem->save();

            $voucher->decrement('stock');

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil dikirim ke user',
                'data'    => $voucherItem
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    public function validateCode(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string'
            ]);

            $voucher = Voucher::where('code', $request->code)->first();

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode voucher tidak ditemukan'
                ], 404);
            }

            $userId = $request->user()?->id ?? auth()->id() ?? null;

            // sudah pernah divalidasi?
            $existingValidation = VoucherValidation::where([
                'voucher_id' => $voucher->id,
                'user_id'    => $userId,
                'code'       => $request->code
            ])->first();

            if ($existingValidation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode voucher sudah pernah divalidasi'
                ], 409);
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
                'data'    => [
                    'voucher'    => $voucher,
                    'validation' => $validation
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in validateCode: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memvalidasi kode: ' . $e->getMessage()
            ], 500);
        }
    }

    public function history($voucherId)
    {
        Log::info('Fetching history for voucher ID: ' . $voucherId);

        try {
            $voucher = Voucher::with(['validations.user'])->find($voucherId);

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher tidak ditemukan'
                ], 404);
            }

            $validations = $voucher->validations()
                ->with(['user', 'voucher'])
                ->orderBy('validated_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $validations
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in history method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()
            ], 500);
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

            $validations = VoucherValidation::with(['user', 'voucher'])
                ->where('user_id', $userId)
                ->orderBy('validated_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $validations
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in userValidationHistory method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()
            ], 500);
        }
    }

    public function voucherItems(Request $request)
    {
        try {
            $userId = $request->user()?->id ?? auth()->id();

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi'
                ], 401);
            }

            $voucherItems = VoucherItem::with(['voucher.community'])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $voucherItems
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in voucherItems: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil voucher items: ' . $e->getMessage()
            ], 500);
        }
    }
}
