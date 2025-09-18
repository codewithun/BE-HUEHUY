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
        $query = PromoItem::with(['promo', 'user']);

        // TAMBAHKAN FILTER INI - hanya tampilkan promo items milik user yang login
        $query->where('user_id', Auth::id());

        if ($request->has('promo_id')) {
            $query->where('promo_id', $request->input('promo_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $items = $query->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function indexByPromo($promoId)
    {
        $items = PromoItem::with('user')->where('promo_id', $promoId)->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function show($id)
    {
        $item = PromoItem::with(['promo', 'user'])->find($id);
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Promo item not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'promo_id' => 'required|exists:promos,id',
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

        // load promo to decide code and status
        $promo = Promo::find($data['promo_id']);

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
            $baseCode = strtoupper($promo->code);
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

        $result = DB::transaction(function () use ($promo, $requestedStatus, $isClaimAction, $authUserId, &$data, $isActive) {
            if ($requestedStatus === 'redeemed') {
                // redeem saat create -> kurangi stok jika dikelola
                if ($promo && !is_null($promo->stock)) {
                    $affected = Promo::where('id', $promo->id)
                        ->where('stock', '>', 0)
                        ->decrement('stock');

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

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $promo = Promo::find($item->promo_id);

        $result = DB::transaction(function () use ($request, $item, $promo) {
            if ($promo && !is_null($promo->stock)) {
                $affected = Promo::where('id', $promo->id)
                    ->where('stock', '>', 0)
                    ->decrement('stock');

                if ($affected === 0) {
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
}
