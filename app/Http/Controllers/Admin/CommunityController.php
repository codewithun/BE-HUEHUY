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
     * Helper: bentuk payload untuk FE (prefill + readable)
     */
    private function shapeCommunity($community, ?int $viewerUserId = null)
    {
        // pastikan relasi ke-load
        $community->loadMissing(['adminContacts.role']);

        // hitung members (aktif)
        $members = $community->members_count
            ?? (int) DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('status', 'active')
                ->count();

        // isJoined untuk viewer (opsional kalau ada auth)
        $isJoined = false;
        if ($viewerUserId) {
            $isJoined = DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('user_id', $viewerUserId)
                ->where('status', 'active')
                ->exists();
        }

        return [
            'id'            => $community->id,
            'name'          => $community->name,
            'description'   => $community->description,
            'logo'          => $community->logo,         // FE kamu sudah handle /storage/
            'category'      => $community->category ?? null,
            'privacy'       => $community->privacy ?? null,
            'is_verified'   => (bool) ($community->is_verified ?? false),
            'members'       => $members,
            'isJoined'      => (bool) $isJoined,

            // === penting untuk FE ===
            'admin_contact_ids' => $community->adminContacts->pluck('id')->values(),
            'admin_contacts'    => $community->adminContacts->map(function ($u) {
                return [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'role'  => ['name' => optional($u->role)->name],
                ];
            })->values(),

            'created_at'    => $community->created_at,
            'updated_at'    => $community->updated_at,
        ];
    }

    /**
     * List all communities (public/admin)
     */
    public function index(Request $request)
    {
        $query = Community::query()->with('adminContacts.role');

        // Optional: search
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Optional: sorting
        if ($request->filled('sortBy') && $request->filled('sortDirection')) {
            $query->orderBy($request->sortBy, $request->sortDirection);
        } else {
            $query->latest();
        }

        // Pagination optional
        if ($request->has('paginate')) {
            $paginate = (int) ($request->paginate ?? 10);
            $data = $query->paginate($paginate);

            $viewerId = optional($request->user())->id;
            $items = collect($data->items())->map(function ($c) use ($viewerId) {
                return $this->shapeCommunity($c, $viewerId);
            });

            return response()->json([
                'data' => $items,
                'total_row' => $data->total(),
            ]);
        }

        $viewerId = optional($request->user())->id;
        $data = $query->get()->map(function ($c) use ($viewerId) {
            return $this->shapeCommunity($c, $viewerId);
        });

        return response()->json([
            'data' => $data,
            'total_row' => $data->count(),
        ]);
    }

    /**
     * GET /api/communities/with-membership (auth required)
     * Return communities with fields: members (count) & isJoined (bool)
     */
    public function withMembership(Request $request)
    {
        try {
            $user = $request->user();

            $communities = Community::with('adminContacts.role')
                ->select([
                    'communities.*',
                    DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") AS members_count'),
                    DB::raw('CASE WHEN cm.id IS NOT NULL THEN 1 ELSE 0 END AS isJoined_dummy'),
                ])
                ->leftJoin('community_memberships AS cm', function ($join) use ($user) {
                    $join->on('communities.id', '=', 'cm.community_id')
                        ->where('cm.user_id', '=', $user->id)
                        ->where('cm.status', '=', 'active');
                })
                ->get();

            $shaped = $communities->map(function ($c) use ($user) {
                // agar shapeCommunity pakai members_count yang sudah dipilih
                $c->members_count = (int) ($c->members_count ?? 0);
                return $this->shapeCommunity($c, $user->id);
            });

            return response()->json(['data' => $shaped]);
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

            if ($community->hasMember($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah menjadi anggota komunitas ini',
                    'already_joined' => true,
                ], 422);
            }

            $membership = $community->addMember($user);

            $community->load('adminContacts.role');
            return response()->json([
                'success' => true,
                'message' => 'Berhasil bergabung dengan komunitas',
                'data' => [
                    'community'   => $this->shapeCommunity($community->fresh(), $user->id),
                    'membership'  => $membership,
                    'isJoined'    => true,
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

            $communities = Community::with('adminContacts.role')
                ->select([
                    'communities.*',
                    DB::raw('(SELECT COUNT(*) FROM community_memberships WHERE community_id = communities.id AND status = "active") AS members_count'),
                    'cm.joined_at',
                ])
                ->join('community_memberships AS cm', 'communities.id', '=', 'cm.community_id')
                ->where('cm.user_id', $user->id)
                ->where('cm.status', 'active')
                ->orderBy('cm.joined_at', 'desc')
                ->get();

            $shaped = $communities->map(function ($c) use ($user) {
                $c->members_count = (int) ($c->members_count ?? 0);
                $payload = $this->shapeCommunity($c, $user->id);
                $payload['joined_at'] = $c->joined_at;
                $payload['isJoined']  = true;
                return $payload;
            });

            return response()->json([
                'success' => true,
                'data' => $shaped,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user communities: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/communities
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'                => 'required|string|max:255',
                'description'         => 'nullable|string',
                'logo'                => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'category'            => 'nullable|string|max:100',
                'privacy'             => 'nullable|in:public,private',
                'is_verified'         => 'nullable|boolean',
                // === Tambahan: kontak admin ===
                'admin_contact_ids'   => 'nullable|array',
                'admin_contact_ids.*' => 'integer|exists:users,id',
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
            } elseif (is_string($request->input('logo')) && $request->input('logo') !== '') {
                // dukung string path (opsional)
                $validated['logo'] = $request->input('logo');
            }

            DB::beginTransaction();

            $community = Community::create(collect($validated)->except(['admin_contact_ids'])->toArray());

            // Sinkronisasi admin contacts (pivot)
            $ids = collect($validated['admin_contact_ids'] ?? [])->unique()->values();
            if ($ids->isNotEmpty()) {
                $community->adminContacts()->sync($ids->all());
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Community created successfully',
                'data'    => $this->shapeCommunity($community->fresh()),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create community: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/communities/{id} (public)
     */
    public function show(Request $request, $id)
    {
        try {
            $community = Community::with('adminContacts.role')->findOrFail($id);
            $payload = $this->shapeCommunity($community, optional($request->user())->id);

            return response()->json([
                'success' => true,
                'data'    => $payload,
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
                'name'                => 'required|string|max:255',
                'description'         => 'nullable|string',
                'logo'                => 'nullable', // file image ATAU string path
                'category'            => 'nullable|string|max:100',
                'privacy'             => 'nullable|in:public,private',
                'is_verified'         => 'nullable|boolean',
                // === Tambahan: kontak admin ===
                'admin_contact_ids'   => 'nullable|array',
                'admin_contact_ids.*' => 'integer|exists:users,id',
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

            DB::beginTransaction();

            // Handle logo upload / replace
            if ($request->hasFile('logo')) {
                if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                    Storage::disk('public')->delete($community->logo);
                }
                $validated['logo'] = $request->file('logo')->store('communities', 'public');
            } elseif (is_string($request->input('logo'))) {
                $validated['logo'] = $request->input('logo');
            } else {
                // jika tidak mengirim field 'logo', biarkan apa adanya
                unset($validated['logo']);
            }

            // Update fields utama
            $community->update(collect($validated)->except(['admin_contact_ids'])->toArray());

            // Sinkronisasi admin contacts bila ada di request
            if ($request->has('admin_contact_ids')) {
                $ids = collect($validated['admin_contact_ids'] ?? [])->unique()->values();
                $community->adminContacts()->sync($ids->all());
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Community updated successfully',
                'data'    => $this->shapeCommunity($community->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
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

            // bersihkan logo (opsional)
            if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                Storage::disk('public')->delete($community->logo);
            }

            // hapus pivot admin_contacts ikut terhapus oleh FK cascade (jika di-setup),
            // kalau belum, bisa manual:
            // $community->adminContacts()->detach();

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
