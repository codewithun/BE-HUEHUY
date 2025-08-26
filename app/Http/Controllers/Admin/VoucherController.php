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
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        $model = new Voucher();
        $query = Voucher::with('community'); // hanya relasi community

        // Search
        if ($request->get("search") != "") {
            $query = $query->where('name', 'like', '%' . $request->get("search") . '%');
        }

        // Filter
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                if ($column == 'community_id') {
                    $filterVal = explode(':', $value)[1];
                    $query = $query->where('community_id', $filterVal);
                } else {
                    $query = $query->where($column, 'like', '%' . $value . '%');
                }
            }
        }

        $query = $query->orderBy($sortby, $sortDirection)
            ->paginate($paginate);

        if (empty($query->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        return response([
            "message" => "success",
            "data" => $query->items(),
            "total_row" => $query->total(),
        ]);
    }

    public function show(string $id)
    {
        $model = Voucher::with('community', 'voucher_items', 'voucher_items.user')
            ->where('id', $id)
            ->first();

        if (!$model) {
            return response([
                'messaege' => 'Data not found'
            ], 404);
        }

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'type' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'tenant_location' => 'nullable|string',
            'stock' => 'required|integer|min:0',
            'delivery' => 'required|in:manual,auto',
            'code' => 'required|string|unique:vouchers,code',
            'community_id' => 'nullable|exists:communities,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();
            
            $data = $request->except('image');
            
            // Handle image upload
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('vouchers', 'public');
                $data['image'] = $path;
            }

            $model = Voucher::create($data);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil dibuat',
                'data' => $model
            ], 201);
        } catch (\Exception $e) {
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
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'type' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'tenant_location' => 'nullable|string',
            'stock' => 'required|integer|min:0',
            'delivery' => 'required|in:manual,auto',
            'code' => 'required|string|unique:vouchers,code,' . $id,
            'community_id' => 'nullable|exists:communities,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();
            
            $model = Voucher::findOrFail($id);
            $data = $request->except('image');
            
            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($model->image && Storage::disk('public')->exists($model->image)) {
                    Storage::disk('public')->delete($model->image);
                }
                
                $path = $request->file('image')->store('vouchers', 'public');
                $data['image'] = $path;
            }

            $model->fill($data);
            $model->save();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diupdate',
                'data' => $model
            ]);
        } catch (\Exception $e) {
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
        // ? Initial
        $model = Voucher::findOrFail($id);

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

    public function sendToUser(Request $request, $voucherId)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $voucher = Voucher::findOrFail($voucherId);

            // Cek stock
            if ($voucher->stock <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock voucher habis!'
                ], 400);
            }

            // Buat voucher item untuk user
            $voucherItem = new \App\Models\VoucherItem();
            $voucherItem->user_id = $request->user_id;
            $voucherItem->voucher_id = $voucher->id;
            $voucherItem->code = $voucher->code;
            $voucherItem->save();

            // Kurangi stock
            $voucher->stock -= 1;
            $voucher->save();

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil dikirim ke user',
                'data' => $voucherItem
            ]);
        } catch (\Exception $e) {
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

            // Cek apakah sudah pernah divalidasi
            $existingValidation = VoucherValidation::where([
                'voucher_id' => $voucher->id,
                'user_id' => $request->user()?->id ?? auth()->id() ?? null,
                'code' => $request->code
            ])->first();

            if ($existingValidation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode voucher sudah pernah divalidasi'
                ], 409);
            }

            // Buat record history validasi
            $validation = VoucherValidation::create([
                'voucher_id' => $voucher->id,
                'user_id' => $request->user()?->id ?? auth()->id() ?? null,
                'code' => $request->code,
                'validated_at' => now(),
                'notes' => $request->input('notes'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kode voucher valid',
                'data' => [
                    'voucher' => $voucher,
                    'validation' => $validation
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error in validateCode: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memvalidasi kode: ' . $e->getMessage()
            ], 500);
        }
    }

    // endpoint untuk mengambil history validasi voucher
    public function history($voucherId)
    {
        Log::info("Fetching history for voucher ID: " . $voucherId);
        
        try {
            $voucher = Voucher::with(['validations.user'])->find($voucherId);
            
            Log::info("Voucher found: " . ($voucher ? 'yes' : 'no'));
            if ($voucher) {
                Log::info("Validations count: " . $voucher->validations->count());
            }
            
            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher tidak ditemukan'
                ], 404);
            }

            $validations = $voucher->validations()->with([
                'user',
                'voucher'
            ])->get();

            return response()->json([
                'success' => true,
                'data' => $validations
            ]);
        } catch (\Exception $e) {
            Log::error("Error in history method: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()
            ], 500);
        }
    }

    // endpoint untuk mengambil history validasi voucher untuk user yang login
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

            $validations = VoucherValidation::with([
                'user',
                'voucher'
            ])->where('user_id', $userId)
              ->orderBy('validated_at', 'desc')
              ->get();

            return response()->json([
                'success' => true,
                'data' => $validations
            ]);
        } catch (\Exception $e) {
            Log::error("Error in userValidationHistory method: " . $e->getMessage());
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
                'data' => $voucherItems
            ]);
        } catch (\Exception $e) {
            Log::error("Error in voucherItems: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil voucher items: ' . $e->getMessage()
            ], 500);
        }
    }
}
