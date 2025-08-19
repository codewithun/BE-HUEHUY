<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

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
                'message' => 'Failed to retrieve promos'
            ], 500);
        }
    }

    public function show($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $promo
        ]);
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->except('image');
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('promos', 'public');
                $data['image'] = $path;
            }

            $promo = Promo::create($data);
            return response()->json([
                'success' => true,
                'message' => 'Promo created successfully',
                'data' => $promo
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create promo'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo not found'
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->except('image');
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
                'message' => 'Promo updated successfully',
                'data' => $promo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update promo'
            ], 500);
        }
    }

    public function destroy($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo not found'
            ], 404);
        }

        try {
            if (!empty($promo->image)) {
                Storage::disk('public')->delete($promo->image);
            }
            $promo->delete();
            return response()->json([
                'success' => true,
                'message' => 'Promo deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete promo'
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
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $promo = Promo::findOrFail($request->input('promo_id'));

        // optional: prevent assigning if already assigned to other community
        if ($promo->community_id && $promo->community_id != $communityId) {
            return response()->json([
                'success' => false,
                'message' => 'Promo is already assigned to another community'
            ], 409);
        }

        $promo->community_id = $communityId;
        $promo->save();

        return response()->json([
            'success' => true,
            'message' => 'Promo assigned to community',
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
                'message' => 'Promo not found or not assigned to this community'
            ], 404);
        }

        $promo->community_id = null;
        $promo->save();

        return response()->json([
            'success' => true,
            'message' => 'Promo detached from community',
            'data' => $promo
        ]);
    }
}
