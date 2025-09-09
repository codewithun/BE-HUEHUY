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
    // List all communities

public function index(Request $request)
{
    $query = Community::query();

    // Optional: search
    if ($request->search) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    // Optional: sorting
    if ($request->sortBy && $request->sortDirection) {
        $query->orderBy($request->sortBy, $request->sortDirection);
    }

    // Jika ada parameter paginate, gunakan pagination. Jika tidak, ambil semua.
    if ($request->has('paginate')) {
        $paginate = $request->paginate ?? 10;
        $data = $query->paginate($paginate);
        return response()->json([
            'data' => $data->items(),
            'total_row' => $data->total(),
        ]);
    } else {
        $data = $query->get();
        return response()->json([
            'data' => $data,
            'total_row' => $data->count(),
        ]);
    }
}

    /**
     * Get communities with membership status for the authenticated user
     */
    public function withMembership(Request $request)
    {
        try {
            $user = $request->user();
            
            $communities = Community::select([
                'communities.*',
                DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") as members'),
                DB::raw('CASE WHEN community_memberships.id IS NOT NULL THEN 1 ELSE 0 END as isJoined')
            ])
            ->leftJoin('community_memberships', function($join) use ($user) {
                $join->on('communities.id', '=', 'community_memberships.community_id')
                     ->where('community_memberships.user_id', '=', $user->id)
                     ->where('community_memberships.status', '=', 'active');
            })
            ->get()
            ->map(function($community) {
                // Convert isJoined to proper boolean
                $community->isJoined = (bool) $community->isJoined;
                return $community;
            });

            return response()->json($communities);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch communities: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Join a community
     */
    public function join(Request $request, $id)
    {
        try {
            $user = $request->user();
            $community = Community::findOrFail($id);

            // Check if user is already a member
            if ($community->hasMember($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah menjadi anggota komunitas ini',
                    'already_joined' => true
                ], 422);
            }

            // Add user to community
            $membership = $community->addMember($user);

            // Get updated community with member count
            $updatedCommunity = Community::select([
                'communities.*',
                DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") as members')
            ])
            ->where('communities.id', $id)
            ->first();

            return response()->json([
                'success' => true,
                'message' => 'Berhasil bergabung dengan komunitas',
                'data' => [
                    'community' => $updatedCommunity,
                    'membership' => $membership,
                    'isJoined' => true
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal bergabung dengan komunitas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Leave a community
     */
    public function leave(Request $request, $id)
    {
        try {
            $user = $request->user();
            $community = Community::findOrFail($id);

            // Check if user is a member
            if (!$community->hasMember($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda bukan anggota komunitas ini'
                ], 422);
            }

            // Remove user from community
            $removed = $community->removeMember($user);

            if ($removed) {
                return response()->json([
                    'success' => true,
                    'message' => 'Berhasil keluar dari komunitas'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal keluar dari komunitas'
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal keluar dari komunitas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's joined communities
     */
    public function userCommunities(Request $request)
    {
        try {
            $user = $request->user();
            
            $communities = Community::select([
                'communities.*',
                DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") as members'),
                'community_memberships.joined_at'
            ])
            ->join('community_memberships', 'communities.id', '=', 'community_memberships.community_id')
            ->where('community_memberships.user_id', $user->id)
            ->where('community_memberships.status', 'active')
            ->orderBy('community_memberships.joined_at', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $communities
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user communities: ' . $e->getMessage()
            ], 500);
        }
    }

    // Store a new community
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('communities', 'public');
                $validated['logo'] = $logoPath;
            }

            $community = Community::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Community created successfully',
                'data' => $community
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create community: ' . $e->getMessage()
            ], 500);
        }
    }

    // Show a single community
    public function show($id)
    {
        try {
            $community = Community::select([
                'communities.*',
                DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") as members')
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $community
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found'
            ], 404);
        }
    }

    // Update a community
    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $community = Community::findOrFail($id);
            $validated = $validator->validated();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                    Storage::disk('public')->delete($community->logo);
                }
                
                $logoPath = $request->file('logo')->store('communities', 'public');
                $validated['logo'] = $logoPath;
            }

            $community->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Community updated successfully',
                'data' => $community
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update community: ' . $e->getMessage()
            ], 500);
        }
    }

    // Delete a community
    public function destroy($id)
    {
        try {
            $community = Community::findOrFail($id);
            
            // Delete logo file if exists
            if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                Storage::disk('public')->delete($community->logo);
            }
            
            $community->delete();

            return response()->json([
                'success' => true,
                'message' => 'Community deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete community: ' . $e->getMessage()
            ], 500);
        }
    }
}
