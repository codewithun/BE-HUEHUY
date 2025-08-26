<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherItem;
use App\Models\VoucherValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'type' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'tenant_location' => 'nullable|string',
            'stock' => 'required|integer|min:0',
            'delivery' => 'required|in:manual,auto',
            'code' => 'required|string|unique:vouchers,code', // wajib diisi manual
            'community_id' => 'nullable|exists:communities,id',
        ]);
        if ($validation) return $validation;

        DB::beginTransaction();
        $model = new Voucher();
        $model->fill($request->only([
            'name', 'description', 'image', 'type', 'valid_until', 'tenant_location', 'stock', 'delivery', 'code', 'community_id'
        ]));
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
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
        DB::beginTransaction();
        $model = Voucher::findOrFail($id);
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'type' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'tenant_location' => 'nullable|string',
            'stock' => 'required|integer|min:0',
            'delivery' => 'required|in:manual,auto',
            'code' => 'required|string|unique:vouchers,code,' . $id, // pastikan unik kecuali dirinya sendiri
            'community_id' => 'nullable|exists:communities,id',
        ]);
        if ($validation) return $validation;

        $model->fill($request->only([
            'name', 'description', 'image', 'type', 'valid_until', 'tenant_location', 'stock', 'delivery', 'code', 'community_id'
        ]));
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
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
        $validation = $this->validation($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
        if ($validation) return $validation;

        $voucher = Voucher::findOrFail($voucherId);

        // Cek stock
        if ($voucher->stock <= 0) {
            return response([
                "message" => "Stock voucher habis!"
            ], 400);
        }

        // Buat voucher item untuk user
        $voucherItem = new \App\Models\VoucherItem();
        $voucherItem->user_id = $request->user_id;
        $voucherItem->voucher_id = $voucher->id;
        $voucherItem->code = $voucher->code; // atau generate kode unik jika perlu
        $voucherItem->save();

        // Kurangi stock
        $voucher->stock -= 1;
        $voucher->save();

        return response([
            "message" => "Voucher berhasil dikirim ke user",
            "data" => $voucherItem
        ]);
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
}
