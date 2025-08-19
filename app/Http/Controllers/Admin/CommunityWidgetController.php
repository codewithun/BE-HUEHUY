<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityCategory;
use App\Models\Promo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CommunityWidgetController extends Controller
{
    // List all categories for a community, include promos
    public function index($communityId)
    {
        $categories = CommunityCategory::where('community_id', $communityId)
            ->with(['promos'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    // Store a new category (optionally create promos/vouchers OR attach existing ones)
    public function store(Request $request, $communityId)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',

            // create new promos
            'promos' => 'nullable|array',
            'promos.*.title' => 'required_with:promos|string|max:255',
            'promos.*.description' => 'nullable|string',
            'promos.*.detail' => 'nullable|string',
            'promos.*.promo_distance' => 'nullable|numeric',
            'promos.*.start_date' => 'nullable|date',
            'promos.*.end_date' => 'nullable|date',
            'promos.*.always_available' => 'nullable|boolean',
            'promos.*.stock' => 'nullable|integer',
            'promos.*.promo_type' => 'nullable|string',
            'promos.*.location' => 'nullable|string',
            'promos.*.owner_name' => 'nullable|string',
            'promos.*.owner_contact' => 'nullable|string',
            'promos.*.image' => 'nullable|string',

            // attach existing promos by id
            'attach_promo_ids' => 'nullable|array',
            'attach_promo_ids.*' => 'integer|exists:promos,id',

            // create new vouchers (optional) - will attempt only if Voucher model exists
            'vouchers' => 'nullable|array',
            'vouchers.*.code' => 'required_with:vouchers|string',
            'vouchers.*.type' => 'nullable|string',
            'vouchers.*.amount' => 'nullable|numeric',
            'vouchers.*.expiry_date' => 'nullable|date',
            'vouchers.*.stock' => 'nullable|integer',
            'vouchers.*.description' => 'nullable|string',

            // attach existing vouchers by id (no table check here; processed if model exists)
            'attach_voucher_ids' => 'nullable|array',
            'attach_voucher_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        return DB::transaction(function () use ($request, $communityId) {
            $data = $request->only(['title', 'description']);
            $category = CommunityCategory::create(array_merge($data, ['community_id' => $communityId]));

            $attachedPromos = [];
            $createdPromos = [];

            // Attach existing promos
            $attachPromoIds = $request->input('attach_promo_ids', []);
            if (!empty($attachPromoIds)) {
                $promosToAttach = Promo::whereIn('id', $attachPromoIds)->get();
                foreach ($promosToAttach as $p) {
                    $p->community_id = $communityId;
                    $p->category_id = $category->id;
                    $p->save();
                    $attachedPromos[] = $p;
                }
            }

            // Create new promos if provided
            $promos = $request->input('promos', []);
            if (!empty($promos) && is_array($promos)) {
                foreach ($promos as $p) {
                    $promoData = [
                        'community_id' => $communityId,
                        'category_id' => $category->id,
                        'title' => $p['title'] ?? ('Promo ' . now()->timestamp),
                        'description' => $p['description'] ?? null,
                        'detail' => $p['detail'] ?? null,
                        'promo_distance' => $p['promo_distance'] ?? 0,
                        'start_date' => $p['start_date'] ?? null,
                        'end_date' => $p['end_date'] ?? null,
                        'always_available' => $p['always_available'] ?? false,
                        'stock' => $p['stock'] ?? 0,
                        'promo_type' => $p['promo_type'] ?? 'offline',
                        'location' => $p['location'] ?? null,
                        'owner_name' => $p['owner_name'] ?? null,
                        'owner_contact' => $p['owner_contact'] ?? null,
                        'image' => $p['image'] ?? null,
                    ];
                    $createdPromos[] = Promo::create($promoData);
                }
            }

            $attachedVouchers = [];
            $createdVouchers = [];

            // If Voucher model exists, process vouchers
            if (class_exists(\App\Models\Voucher::class)) {
                $voucherClass = \App\Models\Voucher::class;

                $attachVoucherIds = $request->input('attach_voucher_ids', []);
                if (!empty($attachVoucherIds)) {
                    $vouchersToAttach = $voucherClass::whereIn('id', $attachVoucherIds)->get();
                    foreach ($vouchersToAttach as $v) {
                        if (isset($v->community_id)) $v->community_id = $communityId;
                        if (isset($v->category_id)) $v->category_id = $category->id;
                        $v->save();
                        $attachedVouchers[] = $v;
                    }
                }

                $vouchers = $request->input('vouchers', []);
                if (!empty($vouchers) && is_array($vouchers)) {
                    foreach ($vouchers as $v) {
                        $voucherData = array_merge($v, [
                            'community_id' => $communityId,
                            'category_id' => $category->id,
                        ]);
                        $createdVouchers[] = $voucherClass::create($voucherData);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'category' => $category,
                    'attached_promos' => $attachedPromos,
                    'created_promos' => $createdPromos,
                    'attached_vouchers' => $attachedVouchers,
                    'created_vouchers' => $createdVouchers,
                ],
            ], 201);
        });
    }

    // Attach an existing promo or voucher to an existing category
    // POST /communities/{communityId}/categories/{categoryId}/attach
    // body: { type: 'promo'|'voucher', id: int }
    public function attachExisting(Request $request, $communityId, $categoryId)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:promo,voucher',
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $type = $request->input('type');
        $id = $request->input('id');

        if ($type === 'promo') {
            $promo = Promo::find($id);
            if (!$promo) {
                return response()->json(['success' => false, 'message' => 'Promo not found'], 404);
            }
            $promo->community_id = $communityId;
            $promo->category_id = $categoryId;
            $promo->save();

            return response()->json(['success' => true, 'data' => $promo]);
        }

        // voucher
        if (!class_exists(\App\Models\Voucher::class)) {
            return response()->json(['success' => false, 'message' => 'Voucher model not available'], 400);
        }
        $voucherClass = \App\Models\Voucher::class;
        $voucher = $voucherClass::find($id);
        if (!$voucher) {
            return response()->json(['success' => false, 'message' => 'Voucher not found'], 404);
        }
        if (isset($voucher->community_id)) $voucher->community_id = $communityId;
        if (isset($voucher->category_id)) $voucher->category_id = $categoryId;
        $voucher->save();

        return response()->json(['success' => true, 'data' => $voucher]);
    }

    // Show a single category with promos
    public function showCategory($communityId, $id)
    {
        $category = CommunityCategory::where('community_id', $communityId)->with('promos')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $category]);
    }

    // Update a category (basic)
    public function update(Request $request, $communityId, $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = CommunityCategory::where('community_id', $communityId)->findOrFail($id);
        $category->update($validated);

        return response()->json(['success' => true, 'data' => $category]);
    }

    // Delete a category
    public function destroy($communityId, $id)
    {
        $category = CommunityCategory::where('community_id', $communityId)->findOrFail($id);
        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted']);
    }
}
