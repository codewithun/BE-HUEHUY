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

class PromoItemController extends Controller
{
    public function index(Request $request)
    {
        $query = PromoItem::with(['promo', 'user']);

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

        // Decide final item status:
        // - 'redeemed' only when explicitly requested
        // - 'reserved' only when frontend signals a claim (claim=true) AND there's an authenticated user
        // - otherwise status follows promo active state (available / expired)
        $requestedStatus = $request->input('status');
        $isClaimAction = $request->boolean('claim');

        if ($requestedStatus === 'redeemed') {
            $data['status'] = 'redeemed';
            $data['redeemed_at'] = Carbon::now();
            if ($data['user_id']) {
                $data['user_id'] = $data['user_id'];
            }
        } elseif ($isClaimAction && $authUserId) {
            // treat as a claim action coming from FE (e.g. { claim: true })
            $data['status'] = 'reserved';
            $data['reserved_at'] = Carbon::now();
            // ensure claimed item is linked to authenticated user
            $data['user_id'] = $authUserId;
        } else {
            // ignore frontend-sent 'reserved' to avoid accidental reserved state
            $data['status'] = $isActive ? 'available' : 'expired';
        }

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

        $item = PromoItem::create($data);

        return response()->json(['success' => true, 'data' => $item], 201);
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

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $item->status = 'redeemed';
        $item->redeemed_at = Carbon::now();
        if ($request->filled('user_id')) {
            $item->user_id = $request->input('user_id');
        }
        $item->save();

        return response()->json(['success' => true, 'data' => $item]);
    }
}
