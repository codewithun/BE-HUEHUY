<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\PromoItem;
use App\Models\PromoValidation;
use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

class PromoController extends Controller
{
    /**
     * Transform promo image URLs
     */
    private function transformPromoImageUrls($promo)
    {
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
            $adminId = Auth::id();
            $sortDirection = $request->get('sortDirection', 'DESC');
            $sortby        = $request->get('sortBy', 'created_at');
            $paginate      = (int) $request->get('paginate', 10);
            $filter        = $request->get('filter', null);

            $query = Promo::with(['community'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('title', 'like', "%{$s}%")
                           ->orWhere('code', 'like', "%{$s}%")
                           ->orWhere('description', 'like', "%{$s}%");
                    });
                });

            // Filter berdasarkan admin jika diperlukan
            // Uncomment jika ada kolom admin_id di tabel promos
            // $query->where('admin_id', $adminId);

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

            // Handle "all" logic - if paginate=0 or all=1, use get() instead of paginate()
            if ($paginate <= 0 || $request->boolean('all')) {
                $items = $query->orderBy($sortby, $sortDirection)->get();
                
                $transformedItems = $items->map(function ($p) {
                    $p = $this->transformPromoImageUrls($p);
                    $p->validation_type = $p->validation_type ?? 'auto';
                    return $p;
                });

                return response([
                    'message'   => 'success',
                    'data'      => $transformedItems,
                    'total_row' => $transformedItems->count(),
                ]);
            }

            // Regular pagination
            $data = $query->orderBy($sortby, $sortDirection)->paginate($paginate);
            $items = collect($data->items())->map(function ($p) {
                $p = $this->transformPromoImageUrls($p);
                $p->validation_type = $p->validation_type ?? 'auto';
                return $p;
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

    /**
     * For dropdown usage - returns minimal promo data
     */
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
                'messaege' => 'Data not found'
            ], 404);
        }

        $promo = $this->transformPromoImageUrls($promo);
        $promo->validation_type = $promo->validation_type ?? 'auto';

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
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
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
            'validation_type' => 'required|string|in:auto,manual',
            'location'        => 'nullable|string',
            'owner_name'      => 'required|string|max:255',
            'owner_contact'   => 'required|string|max:255',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'community_id'    => 'required|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code|required_if:validation_type,manual',
        ], [
            'validation_type.required' => 'Tipe validasi wajib diisi.',
            'validation_type.in'       => 'Tipe validasi harus auto atau manual.',
            'code.required_if'         => 'Kode wajib diisi untuk tipe validasi manual.',
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

            $data['always_available'] = in_array($data['always_available'] ?? '0', [1, '1', true, 'true'], true);
            $data['promo_distance']   = isset($data['promo_distance']) ? (float) $data['promo_distance'] : 0;
            $data['stock']            = isset($data['stock']) ? (int) $data['stock'] : 0;

            $data['validation_type'] = strtolower($data['validation_type'] ?? 'auto');
            if (!in_array($data['validation_type'], ['auto', 'manual'], true)) {
                $data['validation_type'] = 'auto';
            }

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

        $request->merge([
            'community_id'     => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'always_available' => in_array($request->input('always_available'), [1, '1', true, 'true'], true) ? '1' : (in_array($request->input('always_available'), [0, '0', false, 'false'], true) ? '0' : null),
        ]);

        // Create dynamic validation rules based on whether image is being uploaded
        $validationRules = [
            'title'           => 'sometimes|required|string|max:255',
            'description'     => 'sometimes|required|string',
            'detail'          => 'nullable|string',
            'promo_distance'  => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'always_available'=> 'nullable|in:0,1,true,false',
            'stock'           => 'nullable|integer|min:0',
            'promo_type'      => 'sometimes|required|string|in:offline,online',
            'validation_type' => 'nullable|string|in:auto,manual',
            'location'        => 'nullable|string',
            'owner_name'      => 'sometimes|required|string|max:255',
            'owner_contact'   => 'sometimes|required|string|max:255',
            'community_id'    => 'sometimes|required|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code,' . $id . '|required_if:validation_type,manual',
        ];

        // Only validate image as file if it's actually being uploaded
        if ($request->hasFile('image')) {
            $validationRules['image'] = 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048';
        } else {
            // Allow image field to be a string (existing URL) or null
            $validationRules['image'] = 'nullable|string';
        }

        $validator = Validator::make($request->all(), $validationRules, [
            'validation_type.in' => 'Tipe validasi harus auto atau manual.',
            'code.required_if'   => 'Kode wajib diisi untuk tipe validasi manual.',
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

            // Remove image from data if it's just a URL string (not a new upload)
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

            // Only handle file upload if there's actually a new file
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
        $promos = $promos->map(function($promo) {
            $promo = $this->transformPromoImageUrls($promo);
            $promo->validation_type = $promo->validation_type ?? 'auto';
            return $promo;
        });

        return response()->json([
            'success' => true,
            'data' => $promos
        ]);
    }

    // store promo under a specific community (creates new promo and assigns)
    public function storeForCommunity(Request $request, $communityId)
    {
        if ($request->boolean('attach_existing') || $request->has('promo_id') && !$request->has('title')) {
            return $this->assignToCommunity($request, $communityId);
        }

        $request->merge(['community_id' => $communityId]);

        return $this->store($request);
    }

    /**
     * Returns promos available to be assigned to the community.
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
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
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
     * Idempotent validation:
     * - Cari PromoItem dari code / item_id hint / (promo master + owner)
     * - Update redeemed_at (+ status='redeemed' jika kolom ada)
     * - Jika item belum ada tapi ada owner_hint → buatkan untuk owner itu (bukan tenant)
     * - Kalau sudah redeemed, balas 200 OK (bukan 409)
     */
    public function validateCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code'               => 'required|string',
            'is_tenant_validation' => 'sometimes|boolean',
            'validator_role'     => 'sometimes|in:tenant',
            'item_id'            => 'sometimes|integer',
            'item_owner_id'      => 'sometimes|integer',
            'expected_type'      => 'sometimes|in:promo',
            'validation_purpose' => 'sometimes|string',
            'qr_timestamp'       => 'sometimes',
        ], [
            'code.required' => 'Kode unik wajib diisi.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success'=>false,'message'=>$validator->errors()->first(),'errors'=>$validator->errors()], 422);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['success'=>false,'message'=>'Unauthenticated'], 401);
        }

        $code       = trim($request->input('code'));
        $itemIdHint = $request->input('item_id');
        $ownerHint  = $request->input('item_owner_id');

        // 1) by promo_items.code
        $item = PromoItem::with(['promo','user'])
            ->where('code', $code)
            ->first();

        // 2) fallback: by item_id
        if (! $item && $itemIdHint) {
            $item = PromoItem::with(['promo','user'])->find($itemIdHint);
        }

        // 3) fallback: master promo code + owner
        if (! $item) {
            $promo = Promo::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();
            if ($promo && $ownerHint) {
                $item = PromoItem::with(['promo','user'])
                    ->where('promo_id', $promo->id)
                    ->where('user_id', $ownerHint)
                    ->latest('id')
                    ->first();
            }
        }

        // promo object (untuk validasi aktif/expired)
        $promo = $item?->promo ?? Promo::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();
        if (! $promo) {
            return response()->json(['success'=>false,'message'=>'Promo tidak ditemukan'], 404);
        }

        // cek aktif/masa berlaku
        $now   = Carbon::now();
        $start = $promo->start_date ? Carbon::parse($promo->start_date) : null;
        $end   = $promo->end_date ? Carbon::parse($promo->end_date) : null;

        $active = false;
        if (!empty($promo->always_available)) {
            $active = true;
        } else {
            if ($start && $end)       $active = $now->between($start, $end);
            elseif ($start && !$end)  $active = $now->greaterThanOrEqualTo($start);
            elseif (!$start && $end)  $active = $now->lessThanOrEqualTo($end);
            else                      $active = true;
        }
        if (! $active) {
            return response()->json(['success'=>false,'message'=>'Promo tidak aktif atau sudah berakhir'], 422);
        }

        // Jika item belum ada: buat hanya jika ada ownerHint (agar masuk ke Saku pemilik, bukan tenant)
        if (! $item) {
            if (! $ownerHint) {
                return response()->json(['success'=>false,'message'=>'Promo item tidak ditemukan untuk pemilik yang jelas'], 404);
            }

            // kurangi stok jika ada
            if (!is_null($promo->stock)) {
                $affected = Promo::where('id', $promo->id)->where('stock', '>', 0)->decrement('stock');
                if ($affected === 0) {
                    return response()->json(['success'=>false,'message'=>'Stok promo habis'], 409);
                }
            }

            // generate kode unik untuk promo item
            $uniqueCode = function () {
                do {
                    $c = strtoupper('PMI-'.\Illuminate\Support\Str::random(8));
                } while (PromoItem::where('code', $c)->exists());
                return $c;
            };

            $item = DB::transaction(function () use ($promo, $ownerHint, $uniqueCode, $now) {
                $pi = PromoItem::create([
                    'promo_id'     => $promo->id,
                    'user_id'      => $ownerHint,
                    'code'         => $uniqueCode(),
                    'status'       => 'redeemed',
                    'redeemed_at'  => $now,
                    'expires_at'   => $promo->end_date,
                ]);

                // simpan jejak validasi (opsional)
                try {
                    PromoValidation::create([
                        'promo_id'     => $promo->id,
                        'user_id'      => $ownerHint, // pemilik item
                        'code'         => $promo->code,
                        'validated_at' => $now,
                    ]);
                } catch (\Throwable $e) {}

                return $pi->load(['promo','user']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Validasi berhasil',
                'data'    => [
                    'promo_item_id' => $item->id,
                    'promo_item'    => $item,
                    'promo'         => $this->transformPromoImageUrls($promo),
                ],
            ], 200);
        }

        // idempotent: sudah redeemed?
        if (!is_null($item->redeemed_at) || ($item->status ?? null) === 'redeemed' || ($item->status ?? null) === 'used') {
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

        // update redeemed_at (dan stok jika perlu)
        DB::transaction(function () use ($promo, $item, $now, $request) {
            if (!is_null($promo->stock)) {
                Promo::where('id', $promo->id)->where('stock', '>', 0)->decrement('stock');
            }

            if (Schema::hasColumn('promo_items', 'redeemed_at')) {
                $item->redeemed_at = $now;
            }
            if (Schema::hasColumn('promo_items', 'status')) {
                $item->status = 'redeemed';
            }
            $item->save();

            // jejak validasi (opsional)
            try {
                PromoValidation::create([
                    'promo_id'     => $promo->id,
                    'user_id'      => $item->user_id, // pemilik item
                    'code'         => $promo->code,
                    'validated_at' => $now,
                ]);
            } catch (\Throwable $e) {}
        });

        $item->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Validasi berhasil',
            'data'    => [
                'promo_item_id' => $item->id,
                'promo_item'    => $item,
                'promo'         => $this->transformPromoImageUrls($promo),
            ],
        ], 200);
    }

    public function history($promoId)
    {
        Log::info("Fetching history for promo ID: " . $promoId);

        try {
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
