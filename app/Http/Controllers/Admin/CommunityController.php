<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    /**
     * List all communities (public/admin)
     */
    public function index(Request $request)
    {
        $query = Community::query();

        // Optional: search
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Optional: sorting
        if ($request->filled('sortBy') && $request->filled('sortDirection')) {
            $query->orderBy($request->sortBy, $request->sortDirection);
        }

        // Pagination optional
        if ($request->has('paginate')) {
            $paginate = (int) ($request->paginate ?? 10);
            $data = $query->paginate($paginate);
            return response()->json([
                'data' => $data->items(),
                'total_row' => $data->total(),
            ]);
        }

        $data = $query->get();
        return response()->json([
            'data' => $data,
            'total_row' => $data->count(),
        ]);
    }

    /**
     * GET /api/communities/with-membership (auth required)
     * Return communities with fields: members (count) & isJoined (bool)
     * Response shape: { data: [...] }
     */
    public function withMembership(Request $request)
    {
        try {
            $user = $request->user();

            $communities = Community::select([
                    'communities.*',
                    DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") AS members'),
                    DB::raw('CASE WHEN cm.id IS NOT NULL THEN 1 ELSE 0 END AS isJoined'),
                ])
                ->leftJoin('community_memberships AS cm', function ($join) use ($user) {
                    $join->on('communities.id', '=', 'cm.community_id')
                        ->where('cm.user_id', '=', $user->id)
                        ->where('cm.status', '=', 'active');
                })
                ->get()
                ->map(function ($c) {
                    $c->isJoined = (bool) $c->isJoined;
                    return $c;
                });

            return response()->json(['data' => $communities]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch communities: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/communities/{id}/join
     */
    public function join(Request $request, $id)
    {
        try {
            $user = $request->user();
            $community = Community::findOrFail($id);

            // Already a member?
            if ($community->hasMember($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah menjadi anggota komunitas ini',
                    'already_joined' => true,
                ], 422);
            }

            // Add user to community (helper on model)
            $membership = $community->addMember($user);

            // Refresh community with updated members count
            $updatedCommunity = Community::select([
                    'communities.*',
                    DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") AS members'),
                ])
                ->where('communities.id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Berhasil bergabung dengan komunitas',
                'data' => [
                    'community' => $updatedCommunity,
                    'membership' => $membership,
                    'isJoined' => true,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal bergabung dengan komunitas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/communities/{id}/leave
     */
    public function leave(Request $request, $id)
    {
        try {
            $user = $request->user();
            $community = Community::findOrFail($id);

            if (!$community->hasMember($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda bukan anggota komunitas ini',
                ], 422);
            }

            $removed = $community->removeMember($user);

            if ($removed) {
                return response()->json([
                    'success' => true,
                    'message' => 'Berhasil keluar dari komunitas',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal keluar dari komunitas',
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal keluar dari komunitas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/communities/user-communities (auth)
     * Return only communities joined by user
     */
    public function userCommunities(Request $request)
    {
        try {
            $user = $request->user();

            $communities = Community::select([
                    'communities.*',
                    DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") AS members'),
                    'cm.joined_at',
                ])
                ->join('community_memberships AS cm', 'communities.id', '=', 'cm.community_id')
                ->where('cm.user_id', $user->id)
                ->where('cm.status', 'active')
                ->orderBy('cm.joined_at', 'desc')
                ->get()
                ->map(function ($c) {
                    $c->isJoined = true;
                    return $c;
                });

            return response()->json([
                'success' => true,
                'data' => $communities,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user communities: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/communities (or as you wired)
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'        => 'required|string|max:255',
                'description' => 'nullable|string',
                'logo'        => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'category'    => 'nullable|string|max:100',
                'privacy'     => 'nullable|in:public,private',
                'is_verified' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                $validated['logo'] = $request->file('logo')->store('communities', 'public');
            }

            $community = Community::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Community created successfully',
                'data'    => $community,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create community: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/communities/{id} (public)
     */
    public function show($id)
    {
        try {
            $community = Community::select([
                    'communities.*',
                    DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") AS members'),
                ])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $community,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found',
            ], 404);
        }
    }

    /**
     * PUT /api/admin/communities/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'        => 'required|string|max:255',
                'description' => 'nullable|string',
                'logo'        => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'category'    => 'nullable|string|max:100',
                'privacy'     => 'nullable|in:public,private',
                'is_verified' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $community = Community::findOrFail($id);
            $validated = $validator->validated();

            // Handle logo upload (replace)
            if ($request->hasFile('logo')) {
                if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                    Storage::disk('public')->delete($community->logo);
                }
                $validated['logo'] = $request->file('logo')->store('communities', 'public');
            }

            $community->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Community updated successfully',
                'data'    => $community,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update community: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/communities/{id}
     */
    public function destroy($id)
    {
        try {
            $community = Community::findOrFail($id);

            if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                Storage::disk('public')->delete($community->logo);
            }

            $community->delete();

            return response()->json([
                'success' => true,
                'message' => 'Community deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete community: ' . $e->getMessage(),
            ], 500);
        }
    }
}
