<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\Promo; // added
use Illuminate\Support\Facades\DB; // added

class PromoItemController extends Controller
{
    public function index(Request $request)
    {
        $query = PromoItem::with([
            'promo',
            'ad.cube.user',
            'ad.cube.corporate',
            'ad.cube.tags',
            'user'
        ]);

        // Filter berdasarkan user_scope untuk API frontend
        if ($request->has('user_scope') && $request->boolean('user_scope')) {
            $query->where('user_id', Auth::id());
        } else {
            // Untuk admin, tampilkan semua atau filter berdasarkan user_id jika ada
            if ($request->has('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            } else {
                // Untuk keamanan, selalu tampilkan milik user sendiri jika bukan admin request
                $query->where('user_id', Auth::id());
            }
        }

        if ($request->has('promo_id')) {
            $query->where('promo_id', $request->input('promo_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $items = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function indexByPromo($promoId)
    {
        $items = PromoItem::with([
            'promo',
            'ad.cube.user',
            'ad.cube.corporate',
            'ad.cube.tags',
            'user'
        ])
            ->where('promo_id', $promoId)
            ->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function show($id)
    {
        $item = PromoItem::with(['promo', 'ad', 'user'])->find($id);
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Promo item not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Terima id dari promos ATAU ads
            'promo_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $existsInPromos = \App\Models\Promo::where('id', $value)->exists();
                    $existsInAds    = \App\Models\Ad::where('id', $value)->exists();
                    if (! $existsInPromos && ! $existsInAds) {
                        $fail('The selected promo id is invalid.');
                    }
                },
            ],
            'user_id' => 'nullable|exists:users,id',
            'code' => 'nullable|string|unique:promo_items,code',
            'status' => 'nullable|in:available,reserved,redeemed,expired',
            'expires_at' => 'nullable|date',
            // optional: frontend can send claim boolean to indicate user is claiming the promo
            'claim' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $request->only(['promo_id', 'user_id', 'status', 'expires_at']);

        // always prefer authenticated user as owner of a claimed item
        $authUserId = Auth::id();
        $data['user_id'] = $request->input('user_id', $authUserId);

        // load promo/ad to decide code and status
        $promo = Promo::find($data['promo_id']);
        $ad = null;

        // If promo not found, try finding it as an Ad (since promos can be stored as ads)
        if (!$promo) {
            $ad = \App\Models\Ad::find($data['promo_id']);
            if ($ad) {
                // Coba cari promo dengan kode sama
                $existingPromo = \App\Models\Promo::where('code', $ad->code)->first();

                if (!$existingPromo) {
                    // Buat promo dummy agar FK valid
                    $newPromoId = DB::table('promos')->insertGetId([
                        'community_id'     => null,
                        'category_id'      => null,
                        'code'             => $ad->code ?? strtoupper('PRM-' . Str::random(6)),
                        'title'            => $ad->title ?? 'Auto Promo from Ad',
                        'description'      => $ad->description ?? '-',
                        'detail'           => null,
                        'promo_distance'   => 0,

                        'start_date'       => $ad->start_validate ?? now(),
                        'end_date'         => $ad->finish_validate ?? now()->addDays(7),

                        // unlimited? set stock NULL (lebih aman untuk deteksi unlimited)
                        'always_available' => $ad->unlimited_grab ? 1 : 0,
                        'stock'            => $ad->unlimited_grab ? null : ($ad->max_grab ?? 0),

                        'promo_type'       => $ad->promo_type ?? 'offline',
                        'validation_type'  => $ad->validation_type
                            ?? (!empty($ad->qr_required) ? 'qr' : 'code'),

                        // owner & kontak
                        'owner_name'       => $ad->owner_name
                            ?? optional($ad->cube)->owner_name
                            ?? optional($ad->cube?->user)->name
                            ?? 'System',
                        'owner_contact'    => $ad->owner_contact
                            ?? optional($ad->cube?->user)->phone
                            ?? '-',

                        // gambar: pilih yang tersedia
                        'image'            => $ad->picture_source
                            ?? $ad->image_1
                            ?? $ad->image_2
                            ?? $ad->image_3
                            ?? $ad->image
                            ?? null,
                        'image_updated_at' => now(),

                        'location'         => $ad->location ?? null,
                        'status'           => 'active',
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);

                    $promo = \App\Models\Promo::find($newPromoId);
                } else {
                    $promo = $existingPromo;
                }

                // Pastikan FK promo_id selalu ke tabel promos
                $data['promo_id'] = $promo->id;
            }
        }

        // 🚧 Jika promo_id merujuk ke Ad (bukan Promo) → buat promo dummy agar FK aman
        if (!$promo && $ad) {
            $existingPromo = Promo::where('code', $ad->code)->first();
            if (!$existingPromo) {
                $newPromoId = DB::table('promos')->insertGetId([
                    'code'             => $ad->code ?? strtoupper('PRM-' . Str::random(6)),
                    'title'            => $ad->title ?? 'Auto from Ad',
                    'description'      => $ad->description ?? '-',
                    'promo_type'       => $ad->promo_type ?? 'offline',
                    'validation_type'  => 'auto',
                    'status'           => 'active',
                    'always_available' => $ad->unlimited_grab ? 1 : 0,
                    'start_date'       => $ad->start_validate ?? now(),
                    'end_date'         => $ad->finish_validate ?? now()->addDays(7),
                    'stock'            => $ad->max_grab ?? 0,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $promo = Promo::find($newPromoId);
            } else {
                $promo = $existingPromo;
            }

            // pastikan promo_id mengarah ke record promos baru
            $data['promo_id'] = $promo->id;
        }

        // determine promo active state
        $isActive = false;
        if ($promo) {
            $now = Carbon::now();
            if (!empty($promo->always_available)) {
                $isActive = true;
            } else {
                $start = $promo->start_date ? Carbon::parse($promo->start_date) : null;
                $end = $promo->end_date ? Carbon::parse($promo->end_date) : null;

                if ($start && $end) {
                    $isActive = $now->between($start, $end);
                } elseif ($start && ! $end) {
                    $isActive = $now->greaterThanOrEqualTo($start);
                } elseif (! $start && $end) {
                    $isActive = $now->lessThanOrEqualTo($end);
                } else {
                    $isActive = true;
                }
            }
        }

        // Decide final item status later inside transaction
        $requestedStatus = $request->input('status');
        $isClaimAction = $request->boolean('claim');

        // set expires_at default from promo end_date if not provided
        if (empty($data['expires_at']) && $promo && $promo->end_date) {
            $data['expires_at'] = $promo->end_date;
        }

        // Use promo.code from database instead of always generating
        if ($promo && !empty($promo->code)) {
            $baseCode = (string) $promo->code;
            $code = $baseCode;

            $suffix = 1;
            while (PromoItem::where('code', $code)->exists()) {
                $code = $baseCode . '-' . $suffix++;
            }

            $data['code'] = $code;
        } else {
            $data['code'] = strtoupper('PMI-' . Str::random(8));
            while (PromoItem::where('code', $data['code'])->exists()) {
                $data['code'] = strtoupper('PMI-' . Str::random(8));
            }
        }

        // Cegah user claim promo yg sama > 1x
        if ($isClaimAction && $authUserId && !empty($data['promo_id'])) {
            $already = PromoItem::where('promo_id', $data['promo_id'])
                ->where('user_id', $authUserId)
                ->whereIn('status', ['available', 'reserved', 'redeemed'])
                ->exists();

            if ($already) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo ini sudah pernah Anda rebut.',
                ], 409);
            }
        }

        $result = DB::transaction(function () use ($promo, &$ad, $requestedStatus, $isClaimAction, $authUserId, &$data, $isActive) {
            if ($requestedStatus === 'redeemed') {
                // redeem saat create -> kurangi stok jika dikelola
                if ($promo && !is_null($promo->stock) && empty(optional($promo)->unlimited_grab)) {
                    // Try to decrement from Promo table first
                    $affected = 0;
                    if (isset($promo->id) && \App\Models\Promo::where('id', $promo->id)->exists()) {
                        $affected = \App\Models\Promo::where('id', $promo->id)
                            ->where('stock', '>', 0)
                            ->decrement('stock');
                    }

                    // If no Promo record, try Ad table
                    if ($affected === 0 && $ad && !$ad->unlimited_grab) {
                        $affected = \App\Models\Ad::where('id', $ad->id)
                            ->where('max_grab', '>', 0)
                            ->decrement('max_grab');
                    }

                    if ($affected === 0) {
                        return ['ok' => false, 'reason' => 'Stok promo habis'];
                    }
                }
                $data['status'] = 'redeemed';
                $data['redeemed_at'] = Carbon::now();
                if ($authUserId) {
                    $data['user_id'] = $authUserId;
                }
            } elseif ($isClaimAction && $authUserId) {
                // treat as a claim action coming from FE (e.g. { claim: true })
                // Kurangi stok promo saat di-claim (reserved)
                if ($promo && !is_null($promo->stock) && empty(optional($promo)->unlimited_grab)) {
                    // Try to decrement from Promo table first
                    $affected = 0;
                    if (isset($promo->id) && \App\Models\Promo::where('id', $promo->id)->exists()) {
                        $affected = \App\Models\Promo::where('id', $promo->id)
                            ->where('stock', '>', 0)
                            ->decrement('stock');
                    }

                    // If no Promo record, try Ad table
                    if ($affected === 0 && $ad && !$ad->unlimited_grab) {
                        $affected = \App\Models\Ad::where('id', $ad->id)
                            ->where('max_grab', '>', 0)
                            ->decrement('max_grab');
                    }

                    if ($affected === 0) {
                        return ['ok' => false, 'reason' => 'Stok promo habis'];
                    }
                }
                $data['status'] = 'reserved';
                $data['reserved_at'] = Carbon::now();
                $data['user_id'] = $authUserId;
            } else {
                // ignore frontend-sent 'reserved' to avoid accidental reserved state
                $data['status'] = $isActive ? 'available' : 'expired';
            }

            $item = PromoItem::create($data);
            return ['ok' => true, 'item' => $item];
        });

        if (!$result['ok']) {
            return response()->json(['success' => false, 'message' => $result['reason'] ?? 'Stok promo habis'], 409);
        }

        return response()->json(['success' => true, 'data' => $result['item']], 201);
    }

    public function storeForPromo(Request $request, $promoId)
    {
        $request->merge(['promo_id' => $promoId]);
        return $this->store($request);
    }

    public function update(Request $request, $id)
    {
        $item = PromoItem::find($id);
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Promo item not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'code' => 'nullable|string|unique:promo_items,code,' . $id,
            'status' => 'nullable|in:available,reserved,redeemed,expired',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $request->only(['user_id', 'code', 'status', 'expires_at']);

        // ensure code stays unique if provided empty -> keep old
        if (! $request->filled('code')) {
            unset($data['code']);
        }

        $item->update($data);

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function destroy($id)
    {
        $item = PromoItem::find($id);
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Promo item not found'], 404);
        }

        $item->delete();
        return response()->json(['success' => true, 'message' => 'Promo item deleted']);
    }

    /**
     * Redeem an item (mark redeemed, set redeemed_at and optionally user_id).
     * POST /admin/promo-items/{id}/redeem
     */
    public function redeem(Request $request, $id)
    {
        $item = PromoItem::find($id);
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Promo item not found'], 404);
        }

        if ($item->status === 'redeemed') {
            return response()->json(['success' => false, 'message' => 'Promo item sudah ditukarkan'], 409);
        }

        // Wajib kirim kode unik, tapi jangan pernah bocorkan nilainya
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'code'    => 'required|string',
        ], [
            'code.required' => 'Kode unik wajib diisi.',
            'code.string'   => 'Kode unik tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first() ?? 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Cek kecocokan kode unik terhadap QR milik item (pesan generic)
        $inputCode = trim((string) $request->input('code'));

        if (!hash_equals((string) $item->code, $inputCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode unik tidak valid.',
            ], 422);
        }

        $promo = Promo::find($item->promo_id);
        $ad = null;

        // If promo not found, try finding it as an Ad
        if (!$promo) {
            $ad = \App\Models\Ad::find($item->promo_id);
        }

        $result = DB::transaction(function () use ($request, &$item, $promo, &$ad) {
            // Only reduce stock if the item wasn't already reserved (to prevent double reduction)
            $shouldReduceStock = $item->status !== 'reserved';

            if ($shouldReduceStock) {
                $affected = 0;

                // Try Promo table first
                if ($promo && !is_null($promo->stock)) {
                    $affected = \App\Models\Promo::where('id', $promo->id)
                        ->where('stock', '>', 0)
                        ->decrement('stock');
                }

                // If no Promo record or no stock affected, try Ad table
                if ($affected === 0 && $ad && !$ad->unlimited_grab) {
                    $affected = \App\Models\Ad::where('id', $ad->id)
                        ->where('max_grab', '>', 0)
                        ->decrement('max_grab');
                }

                if ($affected === 0 && (($promo && !is_null($promo->stock)) || ($ad && !$ad->unlimited_grab))) {
                    return ['ok' => false, 'reason' => 'Stok promo habis'];
                }
            }

            $item->status = 'redeemed';
            $item->redeemed_at = Carbon::now();
            if ($request->filled('user_id')) {
                $item->user_id = $request->input('user_id');
            }
            $item->save();

            return ['ok' => true, 'item' => $item];
        });

        if (!$result['ok']) {
            return response()->json(['success' => false, 'message' => $result['reason'] ?? 'Stok promo habis'], 409);
        }

        return response()->json(['success' => true, 'data' => $result['item']]);
    }

    /**
     * Claim a promo (create promo item for authenticated user).
     * POST /admin/promos/{id}/claim atau POST /admin/promos/{id}/items
     */
    public function claim(Request $request, $promoId)
    {
        $validator = Validator::make($request->all(), [
            'claim' => 'nullable|boolean',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Merge promo_id dan set claim=true
        $request->merge([
            'promo_id' => $promoId,
            'claim' => true,
            'user_id' => Auth::id()
        ]);

        // Gunakan method store yang sudah ada
        return $this->store($request);
    }

    /**
     * Alternative claim endpoint that matches frontend expectation
     * POST /admin/promo-items dengan payload promo_id dan claim=true
     */
    public function claimDirect(Request $request)
    {
        // Pastikan ada promo_id dan claim=true
        if (!$request->has('promo_id') || !$request->boolean('claim')) {
            return response()->json(['success' => false, 'message' => 'Missing promo_id or claim flag'], 422);
        }

        // Set user_id ke authenticated user
        $request->merge(['user_id' => Auth::id()]);

        // Gunakan method store yang sudah ada
        return $this->store($request);
    }
}
