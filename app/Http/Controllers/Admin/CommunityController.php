<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use App\Models\Promo; // TAMBAH: Import model Promo
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    /**
     * TAMBAH: Method helper untuk hitung promo aktif per komunitas
     */
    private function getActivePromosCount($communityId)
    {
        if (!$communityId) return 0;
        
        return Promo::where('community_id', $communityId)
            ->where(function ($q) {
                // Promo yang always_available = true
                $q->where('always_available', true)
                // ATAU promo yang masih dalam periode aktif
                ->orWhere(function ($qq) {
                    $now = now();
                    $qq->where('always_available', '!=', true)
                       ->where(function ($qqq) use ($now) {
                           // Start date <= now <= end date
                           $qqq->where(function ($qqqq) use ($now) {
                               $qqqq->whereNotNull('start_date')
                                    ->whereNotNull('end_date')
                                    ->where('start_date', '<=', $now)
                                    ->where('end_date', '>=', $now);
                           })
                           // Atau hanya ada start_date dan sudah dimulai
                           ->orWhere(function ($qqqq) use ($now) {
                               $qqqq->whereNotNull('start_date')
                                    ->whereNull('end_date')
                                    ->where('start_date', '<=', $now);
                           })
                           // Atau hanya ada end_date dan belum berakhir
                           ->orWhere(function ($qqqq) use ($now) {
                               $qqqq->whereNull('start_date')
                                    ->whereNotNull('end_date')
                                    ->where('end_date', '>=', $now);
                           })
                           // Atau tidak ada tanggal sama sekali
                           ->orWhere(function ($qqqq) {
                               $qqqq->whereNull('start_date')
                                    ->whereNull('end_date');
                           });
                       });
                });
            })
            // Tambahan filter: promo yang masih ada stok (jika ada kolom stock)
            ->where(function ($q) {
                $q->whereNull('stock')
                  ->orWhere('stock', '>', 0);
            })
            ->count();
    }

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

        // TAMBAH: Hitung activePromos untuk komunitas ini
        $activePromos = $this->getActivePromosCount($community->id);

        return [
            'id'            => $community->id,
            'name'          => $community->name,
            'description'   => $community->description,
            'logo'          => $community->logo,         // FE kamu sudah handle /storage/
            'category'      => $community->category ?? null,
            'privacy'       => $community->privacy ?? null,
            'is_verified'   => (bool) ($community->is_verified ?? false),
            'isVerified'    => (bool) ($community->is_verified ?? false), // TAMBAH: alias untuk compatibility
            'members'       => $members,
            'isJoined'      => (bool) $isJoined,
            'activePromos'  => $activePromos, // TAMBAH: field activePromos

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
     * Return communities with fields: members (count) & isJoined (bool) & activePromos (count)
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
     * GET /api/admin/communities/{id}/members
     * Daftar member untuk admin (auth)
     */
    public function adminMembers(Request $request, $id)
    {
        $community = Community::findOrFail($id);

        $q = CommunityMembership::with(['user.role'])
            ->where('community_id', $community->id);

        // default aktif; boleh override ?status=active|pending|removed
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        } else {
            $q->where('status', 'active');
        }

        // search by user fields
        if ($request->filled('search')) {
            $s = trim($request->search);
            $q->whereHas('user', function ($u) use ($s) {
                $u->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        $sortBy  = $request->get('sortBy', 'joined_at');
        $sortDir = $request->get('sortDirection', 'desc');
        $q->orderBy($sortBy, $sortDir);

        if ($request->has('paginate') && $request->paginate !== 'all') {
            $per = (int) ($request->paginate ?: 10);
            $page = $q->paginate($per);
            $items = collect($page->items())->map(fn($m) => [
                'id'        => $m->user->id,
                'name'      => $m->user->name,
                'email'     => $m->user->email,
                'phone'     => $m->user->phone,
                'role'      => ['name' => optional($m->user->role)->name],
                'joined_at' => $m->joined_at,
                'status'    => $m->status,
            ]);

            return response()->json([
                'data'      => $items->values(),
                'total_row' => $page->total(),
            ]);
        }

        $rows = $q->get()->map(fn($m) => [
            'id'        => $m->user->id,
            'name'      => $m->user->name,
            'email'     => $m->user->email,
            'phone'     => $m->user->phone,
            'role'      => ['name' => optional($m->user->role)->name],
            'joined_at' => $m->joined_at,
            'status'    => $m->status,
        ]);

        return response()->json([
            'data'      => $rows->values(),
            'total_row' => $rows->count(),
        ]);
    }

    /**
     * GET /api/communities/{id}/members
     */
    public function publicMembers(Request $request, $id)
    {
        $community = Community::findOrFail($id);

        $q = CommunityMembership::with(['user.role'])
            ->where('community_id', $community->id)
            ->where('status', 'active');

        if ($request->filled('search')) {
            $s = trim($request->search);
            $q->whereHas('user', function ($u) use ($s) {
                $u->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%");
            });
        }

        $q->orderBy('joined_at', 'desc');

        $rows = $q->get()->map(fn($m) => [
            'id'        => $m->user->id,
            'name'      => $m->user->name,
            'email'     => $m->user->email,
            'role'      => ['name' => optional($m->user->role)->name],
            'joined_at' => $m->joined_at,
        ]);

        return response()->json(['data' => $rows->values()]);
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
                'logo'                => 'nullable',
                'category'            => 'nullable|string|max:100',
                'privacy'             => 'nullable|in:public,private',
                'is_verified'         => 'nullable|boolean',
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
