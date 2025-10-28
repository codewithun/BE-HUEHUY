<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\Promo;
use Illuminate\Support\Facades\DB;

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


        $hasPromoScope = $request->filled('promo_id') || $request->filled('promo_code');


        if ($request->boolean('user_scope')) {

            $query->where('user_id', Auth::id());
        } elseif ($request->filled('user_id')) {

            $query->where('user_id', $request->input('user_id'));
        } elseif (!$hasPromoScope) {

            $query->where('user_id', Auth::id());
        }



        if ($request->filled('promo_id')) {
            $query->where('promo_id', $request->input('promo_id'));
        }

        if ($request->filled('promo_code')) {
            $code = trim((string) $request->input('promo_code'));
            $query->where(function ($q) use ($code) {
                $q->whereHas('promo', function ($qp) use ($code) {
                    $qp->where('code', $code);
                })
                    ->orWhere('code', $code)
                    ->orWhere('code', 'like', $code . '-%');
            });
        }


        if ($request->filled('validation_type_filter')) {
            $filterType = $request->input('validation_type_filter');
            if ($filterType === 'qr_only') {
                $query->whereHas('promo', function ($q) {
                    $q->where('validation_type', 'auto');
                });
            } elseif ($filterType === 'manual_only') {
                $query->whereHas('promo', function ($q) {
                    $q->where('validation_type', 'manual');
                });
            }
        }

        if ($request->filled('status')) {
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

            'promo_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $existsInPromos = \App\Models\Promo::where('id', $value)->exists();
                    $existsInAds    = \App\Models\Ad::where('id', $value)->exists();
                    if (!$existsInPromos && !$existsInAds) {
                        $fail('The selected promo id is invalid.');
                    }
                },
            ],
            'user_id'    => 'nullable|exists:users,id',
            'code'       => 'nullable|string|unique:promo_items,code',
            'status'     => 'nullable|in:available,reserved,redeemed,expired',
            'expires_at' => 'nullable|date',
            'claim'      => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        $data       = $request->only(['promo_id', 'user_id', 'status', 'expires_at']);
        $authUserId = Auth::id();
        $data['user_id'] = $request->input('user_id', $authUserId);


        $promo = Promo::find($data['promo_id']);
        $ad    = null;

        if (!$promo) {
            $ad = \App\Models\Ad::find($data['promo_id']);
            if ($ad) {

                $existingPromo = \App\Models\Promo::where('code', $ad->code)->first();
                if (!$existingPromo) {
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
                        'always_available' => $ad->unlimited_grab ? 1 : 0,
                        'stock'            => $ad->unlimited_grab ? null : ($ad->max_grab ?? 0),
                        'promo_type'       => $ad->promo_type ?? 'offline',
                        'validation_type'  => $ad->validation_type ?? 'auto',
                        'owner_name'       => $ad->owner_name
                            ?? optional($ad->cube)->owner_name
                            ?? optional($ad->cube?->user)->name
                            ?? 'System',
                        'owner_contact'    => $ad->owner_contact
                            ?? optional($ad->cube?->user)->phone
                            ?? '-',
                        'image'            => $ad->picture_source
                            ?? $ad->image_1 ?? $ad->image_2 ?? $ad->image_3
                            ?? $ad->image ?? null,
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
                $data['promo_id'] = $promo->id;
            }
        }


        $isActive = false;
        if ($promo) {
            $now = Carbon::now();
            if (!empty($promo->always_available)) {
                $isActive = true;
            } else {
                $start = $promo->start_date ? Carbon::parse($promo->start_date) : null;
                $end   = $promo->end_date ? Carbon::parse($promo->end_date) : null;
                if ($start && $end)        $isActive = $now->between($start, $end);
                elseif ($start && !$end)   $isActive = $now->greaterThanOrEqualTo($start);
                elseif (!$start && $end)   $isActive = $now->lessThanOrEqualTo($end);
                else                       $isActive = true;
            }
        }

        $requestedStatus = $request->input('status');
        $isClaimAction   = $request->boolean('claim');

        if (empty($data['expires_at']) && $promo && $promo->end_date) {
            $data['expires_at'] = $promo->end_date;
        }


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


        $result = DB::transaction(function () use ($promo, $ad, $requestedStatus, $isClaimAction, $authUserId, &$data, $isActive) {


            $lockedPromo = null;
            $lockedAd    = null;

            if ($promo) {
                $lockedPromo = \App\Models\Promo::where('id', $promo->id)->lockForUpdate()->first();
                if (!$lockedPromo) {
                    return ['ok' => false, 'reason' => 'Promo tidak ditemukan'];
                }

                if (!empty($lockedPromo->end_date) && now()->greaterThan($lockedPromo->end_date)) {
                    return ['ok' => false, 'reason' => 'Promo kedaluwarsa'];
                }
            } elseif ($ad) {
                $lockedAd = \App\Models\Ad::where('id', $ad->id)->lockForUpdate()->first();
                if (!$lockedAd) {
                    return ['ok' => false, 'reason' => 'Promo tidak ditemukan'];
                }
            } else {
                return ['ok' => false, 'reason' => 'Promo tidak ditemukan'];
            }


            $existing = PromoItem::where('promo_id', $data['promo_id'])
                ->where('user_id', $authUserId)
                ->whereIn('status', ['available', 'reserved', 'redeemed'])
                ->first();

            if ($existing) {
                return [
                    'ok' => true,
                    'already' => true,
                    'item' => $existing,
                    'stock_remaining' => $lockedPromo?->stock
                ];
            }


            $needsStock = false;
            $makeRedeemed = false;

            if ($requestedStatus === 'redeemed') {
                $needsStock   = true;
                $makeRedeemed = true;
            } elseif ($isClaimAction && $authUserId) {
                $needsStock   = true;
                $makeRedeemed = false;
            } else {

                $needsStock   = false;
                $makeRedeemed = false;
            }


            if ($needsStock) {
                $affected = 0;

                if ($lockedPromo && !is_null($lockedPromo->stock)) {

                    $affected = \App\Models\Promo::where('id', $lockedPromo->id)
                        ->where('stock', '>', 0)
                        ->decrement('stock');
                    $lockedPromo->refresh();
                } else {



                    if (!$lockedAd) {
                        $adByCode = \App\Models\Ad::where('code', $lockedPromo?->code)
                            ->lockForUpdate()
                            ->first();
                        if ($adByCode) {
                            $lockedAd = $adByCode;
                        }
                    }

                    if ($lockedAd && !$lockedAd->unlimited_grab) {
                        $affected = \App\Models\Ad::where('id', $lockedAd->id)
                            ->where('max_grab', '>', 0)
                            ->decrement('max_grab');
                        $lockedAd->refresh();
                    }
                }


                if ($affected === 0 && (
                    ($lockedPromo && !is_null($lockedPromo->stock)) ||
                    ($lockedAd && !$lockedAd->unlimited_grab)
                )) {
                    return ['ok' => false, 'reason' => 'Stok promo habis'];
                }
            }


            if ($makeRedeemed) {
                $data['status']      = 'redeemed';
                $data['redeemed_at'] = Carbon::now();
                $data['user_id']     = $authUserId ?: $data['user_id'];
            } elseif ($isClaimAction && $authUserId) {
                $data['status']      = 'reserved';
                $data['reserved_at'] = Carbon::now();
                $data['user_id']     = $authUserId;
            } else {
                $data['status']      = $isActive ? 'available' : 'expired';
            }

            $item = PromoItem::create($data);

            return [
                'ok' => true,
                'already' => false,
                'item' => $item,
                'stock_remaining' => $lockedPromo?->stock
            ];
        }, 3);

        if (!$result['ok']) {
            return response()->json([
                'success' => false,
                'message' => $result['reason'] ?? 'Stok promo habis'
            ], 409);
        }


        if (!empty($result['already'])) {
            return response()->json([
                'success' => true,
                'message' => 'Sudah direbut',
                'data' => [
                    'promo_item_id'   => $result['item']->id,
                    'already_claimed' => true,
                    'stock_remaining' => $result['stock_remaining'] ?? null,
                ]
            ], 200);
        }


        return response()->json([
            'success' => true,
            'message' => $request->input('status') === 'redeemed'
                ? 'Redeem berhasil'
                : ($request->boolean('claim') ? 'Klaim berhasil' : 'Item dibuat'),
            'data' => [
                'promo_item_id'   => $result['item']->id,
                'already_claimed' => false,
                'stock_remaining' => $result['stock_remaining'] ?? null,
            ]
        ], 201);
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


        $inputCode = trim((string) $request->input('code'));

        if (!hash_equals((string) $item->code, $inputCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode unik tidak valid.',
            ], 422);
        }

        $promo = Promo::find($item->promo_id);
        $ad = null;


        if (!$promo) {
            $ad = \App\Models\Ad::find($item->promo_id);
        }

        $result = DB::transaction(function () use ($request, &$item, $promo, &$ad) {

            $shouldReduceStock = $item->status !== 'reserved';

            if ($shouldReduceStock) {
                $affected = 0;


                if ($promo && !is_null($promo->stock)) {
                    $affected = \App\Models\Promo::where('id', $promo->id)
                        ->where('stock', '>', 0)
                        ->decrement('stock');
                }


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


        $request->merge([
            'promo_id' => $promoId,
            'claim' => true,
            'user_id' => Auth::id()
        ]);


        return $this->store($request);
    }

    /**
     * Alternative claim endpoint that matches frontend expectation
     * POST /admin/promo-items dengan payload promo_id dan claim=true
     */
    public function claimDirect(Request $request)
    {

        if (!$request->has('promo_id') || !$request->boolean('claim')) {
            return response()->json(['success' => false, 'message' => 'Missing promo_id or claim flag'], 422);
        }


        $request->merge(['user_id' => Auth::id()]);


        return $this->store($request);
    }
}
