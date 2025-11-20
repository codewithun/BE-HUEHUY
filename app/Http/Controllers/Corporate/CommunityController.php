<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\MemberHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    public function index()
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;
        $data = Community::where('corporate_id', $corporateId)->get();
        return response()->json($data);
    }

    public function store(Request $request)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'bg_color_1' => 'nullable|string|max:10',
            'bg_color_2' => 'nullable|string|max:10',
            'world_type' => 'required|in:public,private',
            'logo' => 'nullable|image|max:2048',
        ]);

        $validated['corporate_id'] = $corporateId;
        $validated['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('community_logos', 'public');
        }

        $community = Community::create($validated);
        return response()->json($community, 201);
    }

    public function show($id)
    {
        $community = Community::findOrFail($id);
        return response()->json($community);
    }

    public function update(Request $request, $id)
    {
        $community = Community::findOrFail($id);
        $community->update($request->all());
        return response()->json($community);
    }

    public function destroy($id)
    {
        $community = Community::findOrFail($id);
        $community->delete();
        return response()->json(['message' => 'Community deleted successfully']);
    }

    // Member Management Methods
    public function getMembers($id)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;
        $community = Community::where('id', $id)->where('corporate_id', $corporateId)->firstOrFail();

        $members = CommunityMembership::with(['user.role'])
            ->where('community_id', $community->id)
            ->where('status', 'active')
            ->orderBy('joined_at', 'desc')
            ->get()
            ->map(function ($m) {
                $u = $m->user; // guard null user
                $roleName = $u && $u->role ? $u->role->name : null;
                return [
                    'id' => $u->id ?? null,
                    'name' => $u->name ?? null,
                    'full_name' => $u->full_name ?? null,
                    'email' => $u->email ?? null,
                    'phone' => $u->phone ?? null,
                    'role' => ['name' => $roleName],
                    'joined_at' => $m->joined_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $members,
            'total_row' => $members->count(),
        ]);
    }

    public function addMember(Request $request, $id)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;
        $community = Community::where('id', $id)->where('corporate_id', $corporateId)->firstOrFail();

        $validated = $request->validate([
            'user_id' => 'required|string|email',
        ]);

        $user = User::where('email', $validated['user_id'])->first();
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 422);
        }

        $existing = CommunityMembership::where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'User sudah menjadi anggota'], 422);
        }

        CommunityMembership::create([
            'community_id' => $community->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return response()->json(['message' => 'Anggota berhasil ditambahkan'], 201);
    }

    public function getMemberRequests($id)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;
        $community = Community::where('id', $id)->where('corporate_id', $corporateId)->firstOrFail();

        $requests = CommunityMembership::with(['user.role'])
            ->where('community_id', $community->id)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($m) {
                $u = $m->user; // guard null user
                $roleName = $u && $u->role ? $u->role->name : null;
                return [
                    'id' => $m->id,
                    'user' => $u ? [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'phone' => $u->phone,
                        'role' => ['name' => $roleName],
                    ] : null,
                    'status' => $m->status,
                    'created_at' => $m->created_at,
                    'message' => null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $requests,
            'total_row' => $requests->count(),
        ]);
    }

    public function approveMemberRequest(Request $request, $id)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;

        $membership = CommunityMembership::with(['user', 'community'])->findOrFail($id);

        // Verify community belongs to this corporate
        if ($membership->community->corporate_id !== $corporateId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($membership->status === 'active') {
            return response()->json(['success' => true, 'message' => 'Sudah aktif'], 200);
        }

        if ($membership->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Status tidak valid'], 422);
        }

        $membership->update([
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Add history
        MemberHistory::create([
            'community_id' => $membership->community_id,
            'user_id' => $membership->user_id,
            'action' => 'joined',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permintaan disetujui',
        ]);
    }

    public function rejectMemberRequest(Request $request, $id)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;

        $membership = CommunityMembership::with(['user', 'community'])->findOrFail($id);

        // Verify community belongs to this corporate
        if ($membership->community->corporate_id !== $corporateId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($membership->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Status tidak valid'], 422);
        }

        // Add history before deletion
        MemberHistory::create([
            'community_id' => $membership->community_id,
            'user_id' => $membership->user_id,
            'user_name' => $membership->user->name ?? 'Unknown',
            'action' => 'rejected',
        ]);

        $membership->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permintaan ditolak',
        ]);
    }

    public function getMemberHistory($id)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;
        $community = Community::where('id', $id)->where('corporate_id', $corporateId)->firstOrFail();

        $history = MemberHistory::with('user')
            ->where('community_id', $community->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($h) {
                return [
                    'id' => $h->id,
                    'user' => $h->user ? [
                        'id' => $h->user->id,
                        'name' => $h->user->name,
                        'email' => $h->user->email,
                    ] : null,
                    'user_name' => $h->user_name ?? $h->user?->name,
                    'status' => $h->action, // map action to status for compatibility
                    'created_at' => $h->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $history,
            'total_row' => $history->count(),
        ]);
    }

    /**
     * DELETE /api/corporate/communities/{communityId}/members/{userId}
     * Remove an active member from a community owned by the current corporate.
     */
    public function removeMember($communityId, $userId)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;

        // Ensure the community belongs to this corporate
        $community = Community::where('id', $communityId)
            ->where('corporate_id', $corporateId)
            ->firstOrFail();

        // Find membership
        $membership = CommunityMembership::where('community_id', $community->id)
            ->where('user_id', $userId)
            ->first();

        if (!$membership) {
            return response()->json([
                'success' => false,
                'message' => 'Membership tidak ditemukan',
            ], 404);
        }

        if ($membership->status === 'removed') {
            return response()->json([
                'success' => true,
                'message' => 'Anggota sudah dihapus',
            ]);
        }

        // Update status to removed (avoid referencing non-existent columns like removed_at)
        $membership->status = 'removed';
        $membership->save();

        // Add history
        MemberHistory::create([
            'community_id' => $membership->community_id,
            'user_id' => $membership->user_id,
            'action' => 'removed',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil dihapus',
        ]);
    }
}
