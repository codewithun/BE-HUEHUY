<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\PromoValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PromoController extends Controller
{
    public function index()
    {
        try {
            $promos = Promo::all();
            return response()->json([
                'success' => true,
                'data' => $promos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data promo'
            ], 500);
        }
    }

    public function show($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $promo
        ]);
    }

    /**
     * Show promo for public access (QR entry) - No authentication required
     */
    public function showPublic($id)
    {
        try {
            // Check if promo relation exists first, if not use without relation
            $query = Promo::where('id', $id);
            
            // Only add with() if relations exist in your model
            try {
                // Try to load relations if they exist
                $promo = $query->with(['community'])->first();
            } catch (\Exception $e) {
                // If relations don't exist, load without them
                Log::warning('Relations not available for Promo model, loading without them');
                $promo = $query->first();
            }

            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan'
                ], 404);
            }

            // Check if promo is active (if status column exists)
            if (isset($promo->status) && $promo->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak aktif'
                ], 404);
            }

            // Build response data with safe access to properties
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
            ];

            // Add image URL if image exists
            if (isset($promo->image) && $promo->image) {
                $responseData['image_url'] = asset('storage/' . $promo->image);
            } else {
                $responseData['image_url'] = null;
            }

            // Add community data if relation was loaded successfully
            if (isset($promo->community)) {
                $responseData['community'] = $promo->community;
            }

            // Add price information if columns exist
            if (isset($promo->original_price)) {
                $responseData['original_price'] = $promo->original_price;
            }
            if (isset($promo->discount_price)) {
                $responseData['discount_price'] = $promo->discount_price;
            }
            if (isset($promo->discount_percentage)) {
                $responseData['discount_percentage'] = $promo->discount_percentage;
            }

            // Count claimed if promo_items relation exists
            try {
                $claimedCount = $promo->promo_validations()->count();
                $responseData['claimed_count'] = $claimedCount;
            } catch (\Exception $e) {
                // If relation doesn't exist, set to 0
                $responseData['claimed_count'] = 0;
            }

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
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'detail' => 'nullable|string',
            'promo_distance' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'always_available' => 'boolean',
            'stock' => 'nullable|integer|min:0',
            'promo_type' => 'required|string',
            'location' => 'nullable|string',
            'owner_name' => 'required|string|max:255',
            'owner_contact' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'community_id' => 'nullable|exists:communities,id',
            'code' => 'nullable|string|unique:promos,code', // validasi kode unik jika diinput manual
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->except('image');

            // Generate kode unik jika tidak diinput manual
            if (empty($data['code'])) {
                $data['code'] = 'PRM-' . strtoupper(uniqid());
            }

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('promos', 'public');
                $data['image'] = $path;
            }

            $promo = Promo::create($data);
            return response()->json([
                'success' => true,
                'message' => 'Promo berhasil dibuat',
                'data' => $promo
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat promo'
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

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'detail' => 'nullable|string',
            'promo_distance' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'always_available' => 'boolean',
            'stock' => 'nullable|integer|min:0',
            'promo_type' => 'sometimes|required|string',
            'location' => 'nullable|string',
            'owner_name' => 'sometimes|required|string|max:255',
            'owner_contact' => 'sometimes|required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'community_id' => 'nullable|exists:communities,id',
            'code' => 'nullable|string|unique:promos,code,' . $id, // validasi kode unik saat update
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->except('image');

            // Jika code kosong, tetap gunakan code lama
            if (empty($data['code'])) {
                $data['code'] = $promo->code;
            }

            if ($request->hasFile('image')) {
                if (!empty($promo->image)) {
                    Storage::disk('public')->delete($promo->image);
                }
                $path = $request->file('image')->store('promos', 'public');
                $data['image'] = $path;
            }

            $promo->update($data);
            return response()->json([
                'success' => true,
                'message' => 'Promo berhasil diperbarui',
                'data' => $promo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui promo'
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
            $promo = Promo::where('id', $promoId)
                ->where('community_id', $communityId)
                ->first();

            if (! $promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan untuk komunitas ini'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $promo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data promo'
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

            // Buat record history validasi
            $validation = PromoValidation::create([
                'promo_id' => $promo->id,
                'user_id' => $request->user()?->id ?? auth()->id() ?? null,
                'code' => $request->code,
                'validated_at' => now(),
                'notes' => $request->input('notes'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kode promo valid',
                'data' => [
                    'promo' => $promo,
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

    // endpoint untuk mengambil history validasi promo
    public function history($promoId)
    {
        Log::info("Fetching history for promo ID: " . $promoId);
        
        try {
            $promo = Promo::with(['validations.user'])->find($promoId);
            
            Log::info("Promo found: " . ($promo ? 'yes' : 'no'));
            if ($promo) {
                Log::info("Validations count: " . $promo->validations->count());
            }
            
            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan'
                ], 404);
            }

            $validations = $promo->validations()->with([
                'user',
                'promo'
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
