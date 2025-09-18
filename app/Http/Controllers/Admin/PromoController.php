<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\PromoValidation;
use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB; // ADD

class PromoController extends Controller
{
    /**
     * Transform promo image URLs
     */
    private function transformPromoImageUrls($promo)
    {
        // Handle main promo image
        if ($promo->image) {
            if (Storage::disk('public')->exists($promo->image)) {
                $promo->image_url = asset('storage/' . $promo->image);
            } else {
                $promo->image_url = asset('images/default-promo.jpg');
            }
        } else {
            $promo->image_url = asset('images/default-promo.jpg');
        }

        return $promo;
    }

    // Align with VoucherController index (search/sort/paginate + response shape)
    public function index(Request $request)
    {
        try {
            $sortDirection = $request->get('sortDirection', 'DESC');
            $sortby        = $request->get('sortBy', 'created_at');
            $paginate      = (int) $request->get('paginate', 10);
            $filter        = $request->get('filter', null);

            $query = Promo::query()
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('title', 'like', "%{$s}%")
                           ->orWhere('code', 'like', "%{$s}%")
                           ->orWhere('description', 'like', "%{$s}%");
                    });
                });

            // Optional filters like in voucher index
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

            $data  = $query->orderBy($sortby, $sortDirection)->paginate($paginate);
            $items = collect($data->items())->map(fn ($p) => $this->transformPromoImageUrls($p))->values();

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
                'message' => 'Error: server side having problem!'
            ], 500);
        }
    }

    public function show($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'messaege' => 'Data not found'
            ], 404);
        }

        $promo = $this->transformPromoImageUrls($promo);

        return response([
            'message' => 'Success',
            'data'    => $promo
        ]);
    }

    /**
     * Show promo for public access (QR entry) - No authentication required
     */
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
                'code' => $promo->code ?? null,
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
                'image' => $promo->image,
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

            // FIX: konsisten pakai relasi 'validations' seperti Voucher
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
        // Normalisasi seperti di VoucherController
        $request->merge([
            'community_id'     => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'always_available' => in_array($request->input('always_available'), [1, '1', true, 'true'], true) ? '1' : '0',
        ]);

        $validator = Validator::make($request->all(), [
            'title'           => 'required|string|max:255',
            'description'     => 'required|string',
            'detail'          => 'nullable|string',
            'promo_distance'  => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'always_available'=> 'in:0,1,true,false',
            'stock'           => 'nullable|integer|min:0',
            'promo_type'      => 'required|string|in:offline,online',
            'location'        => 'nullable|string',
            'owner_name'      => 'required|string|max:255',
            'owner_contact'   => 'required|string|max:255',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'community_id'    => 'required|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code',
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

            // Cast & defaults
            $data['always_available'] = in_array($data['always_available'] ?? '0', [1, '1', true, 'true'], true);
            $data['promo_distance']   = isset($data['promo_distance']) ? (float) $data['promo_distance'] : 0;
            $data['stock']            = isset($data['stock']) ? (int) $data['stock'] : 0;

            // Generate code bila kosong (unique-ish)
            if (empty($data['code'])) {
                do {
                    $data['code'] = 'PRM-' . strtoupper(bin2hex(random_bytes(3)));
                } while (Promo::where('code', $data['code'])->exists());
            }

            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('promos', 'public');
            }

            $promo = Promo::create($data);

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

        // Normalisasi seperti voucher
        $request->merge([
            'community_id'     => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'always_available' => in_array($request->input('always_available'), [1, '1', true, 'true'], true) ? '1' : (in_array($request->input('always_available'), [0, '0', false, 'false'], true) ? '0' : null),
        ]);

        $validator = Validator::make($request->all(), [
            'title'           => 'sometimes|required|string|max:255',
            'description'     => 'sometimes|required|string',
            'detail'          => 'nullable|string',
            'promo_distance'  => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'always_available'=> 'nullable|in:0,1,true,false',
            'stock'           => 'nullable|integer|min:0',
            'promo_type'      => 'sometimes|required|string|in:offline,online',
            'location'        => 'nullable|string',
            'owner_name'      => 'sometimes|required|string|max:255',
            'owner_contact'   => 'sometimes|required|string|max:255',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'community_id'    => 'sometimes|required|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code,' . $id,
        ]);

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

            // Preserve existing community_id if not provided
            if (!array_key_exists('community_id', $data) || $data['community_id'] === null) {
                $data['community_id'] = $promo->community_id;
            }

            // Normalize booleans & numerics
            if (array_key_exists('always_available', $data) && $data['always_available'] !== null) {
                $data['always_available'] = in_array($data['always_available'], [1, '1', true, 'true'], true);
            }
            if (isset($data['promo_distance']) && $data['promo_distance'] !== null) {
                $data['promo_distance'] = (float) $data['promo_distance'];
            }
            if (isset($data['stock']) && $data['stock'] !== null) {
                $data['stock'] = (int) $data['stock'];
            }

            // Handle image upload
            if ($request->hasFile('image')) {
                if (!empty($promo->image)) {
                    Storage::disk('public')->delete($promo->image);
                }
                $data['image'] = $request->file('image')->store('promos', 'public');
            }

            $promo->update($data);

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
            if (!empty($promo->image)) {
                Storage::disk('public')->delete($promo->image);
            }
            $promo->delete();
            return response()->json([
                'success' => true,
                'message' => 'Promo berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus promo'
            ], 500);
        }
    }

    // list promos for a specific community (assigned promos)
    public function indexByCommunity($communityId)
    {
        $promos = Promo::where('community_id', $communityId)->get();
        
        // Transform image URLs
        $promos = $promos->map(function($promo) {
            return $this->transformPromoImageUrls($promo);
        });
        
        return response()->json([
            'success' => true,
            'data' => $promos
        ]);
    }

    // store promo under a specific community (creates new promo and assigns)
    public function storeForCommunity(Request $request, $communityId)
    {
        // Jika request ingin attach existing promo, delegasikan ke assignToCommunity
        if ($request->boolean('attach_existing') || $request->has('promo_id') && !$request->has('title')) {
            // pastikan promo_id ada
            return $this->assignToCommunity($request, $communityId);
        }

        // reuse store validation but force community_id for creation
        $request->merge(['community_id' => $communityId]);

        return $this->store($request);
    }

    /**
     * Returns promos available to be assigned to the community.
     * (promos that are unassigned OR already belong to the community)
     */
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

    /**
     * Assign an existing promo to a community (set community_id).
     * Body: { promo_id: int }
     */
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

        // optional: prevent assigning if already assigned to other community
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

    /**
     * Detach a promo from a community (set community_id = null).
     */
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

    /**
     * Show single promo scoped to a community.
     * GET /api/communities/{community}/promos/{promo}
     */
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

            $promo = $this->transformPromoImageUrls($promo);

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
                'code' => $promo->code ?? null,
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
                'image' => $promo->image,
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
                // FIX: konsisten pakai relasi 'validations'
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

    public function validateCode(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string'
            ]);

            $promo = Promo::where('code', $request->code)->first();

            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode promo tidak ditemukan'
                ], 404);
            }

            // Cek apakah sudah pernah divalidasi
            $existingValidation = PromoValidation::where([
                'promo_id' => $promo->id,
                'user_id' => $request->user()?->id ?? auth()->id() ?? null,
                'code' => $request->code
            ])->first();

            if ($existingValidation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode promo sudah pernah divalidasi'
                ], 409);
            }

            // Transaksi + decrement stok (jika stok tidak null)
            $result = DB::transaction(function () use ($request, $promo) {
                if (!is_null($promo->stock)) {
                    $affected = Promo::where('id', $promo->id)
                        ->where('stock', '>', 0)
                        ->decrement('stock');

                    if ($affected === 0) {
                        return ['ok' => false, 'reason' => 'Stok promo habis'];
                    }
                }

                $validation = PromoValidation::create([
                    'promo_id' => $promo->id,
                    'user_id' => $request->user()?->id ?? auth()->id() ?? null,
                    'code' => $request->code,
                    'validated_at' => now(),
                    'notes' => $request->input('notes'),
                ]);

                $promoRefreshed = Promo::find($promo->id);

                return ['ok' => true, 'validation' => $validation, 'promo' => $promoRefreshed];
            });

            if (!$result['ok']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['reason'] ?? 'Stok promo habis'
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Kode promo valid',
                'data' => [
                    'promo' => $result['promo'],
                    'validation' => $result['validation'],
                    'remaining_stock' => $result['promo']->stock,
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

    // endpoint untuk mengambil history validasi promo
    public function history($promoId)
    {
        Log::info("Fetching history for promo ID: " . $promoId);

        try {
            // FIX: konsisten relasi 'validations' + urutkan desc
            $promo = Promo::with(['validations.user'])->find($promoId);

            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan'
                ], 404);
            }

            $validations = $promo->validations()
                ->with(['user', 'promo'])
                ->orderBy('validated_at', 'desc')
                ->get();

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
    
    // endpoint untuk mengambil history validasi promo untuk user yang login
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

            $validations = PromoValidation::with([
                'user',
                'promo'
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
