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
use App\Models\Corporate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CommunityController extends Controller
{
    /**
     * TAMBAH: Method helper untuk hitung promo aktif per komunitas
     */
    private function getActivePromosCount($communityId)
    {
        if (!$communityId) return 0;

        try {
            return DB::table('ads')
                ->join('cubes', 'ads.cube_id', '=', 'cubes.id')
                ->join('dynamic_content_cubes', 'cubes.id', '=', 'dynamic_content_cubes.cube_id')
                ->join('dynamic_contents', 'dynamic_content_cubes.dynamic_content_id', '=', 'dynamic_contents.id')
                ->where('dynamic_contents.community_id', $communityId)
                ->where('ads.status', 'active')
                ->where(function ($q) {
                    $now = now();

                    // Gunakan kolom start_validate dan finish_validate
                    $q->where(function ($qqq) use ($now) {
                        $qqq->whereNotNull('ads.start_validate')
                            ->whereNotNull('ads.finish_validate')
                            ->where('ads.start_validate', '<=', $now)
                            ->where('ads.finish_validate', '>=', $now);
                    })
                        ->orWhere(function ($qqq) use ($now) {
                            $qqq->whereNotNull('ads.start_validate')
                                ->whereNull('ads.finish_validate')
                                ->where('ads.start_validate', '<=', $now);
                        })
                        ->orWhere(function ($qqq) use ($now) {
                            $qqq->whereNull('ads.start_validate')
                                ->whereNotNull('ads.finish_validate')
                                ->where('ads.finish_validate', '>=', $now);
                        })
                        ->orWhere(function ($qqq) {
                            // Kalau tidak punya batas waktu sama sekali, tetap dianggap aktif
                            $qqq->whereNull('ads.start_validate')
                                ->whereNull('ads.finish_validate');
                        });
                })
                ->distinct('ads.id')
                ->count('ads.id');
        } catch (\Throwable $e) {
            Log::error('Failed to count active promos per community', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }


    /**
     * Helper: bentuk payload untuk FE (prefill + readable)
     */
    private function shapeCommunity($community, ?int $viewerUserId = null)
    {
        // pastikan relasi ke-load
        $community->loadMissing(['adminContacts.role', 'corporate']);

        // hitung members (aktif)
        $members = $community->members_count
            ?? (int) DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('status', 'active')
                ->count();

        // isJoined untuk viewer (opsional kalau ada auth)
        $isJoined = false;
        $hasRequested = false;
        if ($viewerUserId) {
            $isJoined = DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('user_id', $viewerUserId)
                ->where('status', 'active')
                ->exists();

            // flag pengajuan pending
            $hasRequested = DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('user_id', $viewerUserId)
                ->where('status', 'pending')
                ->exists();
        }

        // TAMBAH: Hitung activePromos untuk komunitas ini
        $activePromos = $this->getActivePromosCount($community->id);

        return [
            'id'            => $community->id,
            'name'          => $community->name,
            'description'   => $community->description,
            'logo'          => $community->logo,

            // Tambahan untuk FE
            'corporate_id'  => $community->corporate_id,
            'corporate'     => $community->corporate ? [
                'id'   => $community->corporate->id,
                'name' => $community->corporate->name,
            ] : null,
            'bg_color_1'    => $community->bg_color_1,
            'bg_color_2'    => $community->bg_color_2,
            'world_type'    => $community->world_type,
            'is_active'     => (bool) $community->is_active,

            'category'      => $community->category ?? null,
            'privacy'       => $community->privacy ?? null,
            'is_verified'   => (bool) ($community->is_verified ?? false),
            'isVerified'    => (bool) ($community->is_verified ?? false),

            'members'       => $members,
            'isJoined'      => (bool) $isJoined,
            'hasRequested'  => (bool) $hasRequested,
            'activePromos'  => $activePromos,

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
        $query = Community::query()->with(['adminContacts.role', 'corporate']);

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

            // Tambahkan ini 👇
            $privacy = strtolower($community->privacy ?? $community->world_type ?? 'public');
            if ($privacy === 'pribadi' || $privacy === 'private') {
                return response()->json([
                    'success' => false,
                    'message' => 'Komunitas ini bersifat private. Harus mengirim permintaan bergabung terlebih dahulu.',
                    'need_request' => true,
                ], 403);
            }

            $membership = $community->addMember($user);

            \App\Models\MemberHistory::create([
                'community_id' => $community->id,
                'user_id' => $user->id,
                'action' => 'joined',
            ]);

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
     * POST /api/communities/{id}/join-request
     * Untuk komunitas private: buat membership status 'pending'.
     * Untuk public (privacy != 'private'): langsung join agar kompatibel dengan FE.
     */
    public function requestJoin(Request $request, $id)
    {
        try {
            $user = $request->user();
            $community = Community::findOrFail($id);

            // Jika sudah active member
            if ($community->hasMember($user)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Anda sudah menjadi anggota komunitas ini',
                    'data'    => [
                        'community'    => $this->shapeCommunity($community->fresh(), $user->id),
                        'isJoined'     => true,
                        'hasRequested' => false,
                    ],
                ], 200);
            }

            // Determine privacy: prefer explicit privacy, else fall back to world_type/type
            $rawPrivacy   = strtolower((string)($community->privacy ?? ''));
            $rawWorldType = strtolower((string)($community->world_type ?? $community->type ?? ''));
            // Map FE value 'pribadi' -> backend 'private'
            if ($rawWorldType === 'pribadi') {
                $rawWorldType = 'private';
            }
            $privacy = $rawPrivacy !== '' ? $rawPrivacy : ($rawWorldType !== '' ? $rawWorldType : 'public');

            // Jika bukan private, langsung join (fallback behavior)
            if ($privacy !== 'private') {
                $membership = $community->addMember($user);
                return response()->json([
                    'success' => true,
                    'message' => 'Berhasil bergabung dengan komunitas',
                    'data'    => [
                        'community'    => $this->shapeCommunity($community->fresh(), $user->id),
                        'membership'   => $membership,
                        'isJoined'     => true,
                        'hasRequested' => false,
                    ],
                ], 200);
            }

            // Komunitas private → buat/pertahankan status pending
            $membership = $community->memberships()
                ->where('user_id', $user->id)
                ->first();

            if ($membership) {
                if ($membership->status === 'active') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Anda sudah menjadi anggota komunitas ini',
                        'data'    => [
                            'community'    => $this->shapeCommunity($community->fresh(), $user->id),
                            'membership'   => $membership,
                            'isJoined'     => true,
                            'hasRequested' => false,
                        ],
                    ], 200);
                }
                // set ke pending jika belum
                if ($membership->status !== 'pending') {
                    $membership->update([
                        'status' => 'pending',
                        // joined_at dibiarkan null ketika pending
                    ]);
                }
            } else {
                $membership = $community->memberships()->create([
                    'user_id' => $user->id,
                    'status'  => 'pending',
                    // joined_at: null saat pending
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Permintaan bergabung terkirim. Menunggu persetujuan admin.',
                'data'    => [
                    'community'    => $this->shapeCommunity($community->fresh(), $user->id),
                    'membership'   => $membership,
                    'isJoined'     => false,
                    'hasRequested' => true,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim permintaan bergabung: ' . $e->getMessage(),
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

            $membership = CommunityMembership::where('community_id', $community->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$membership) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda bukan anggota komunitas ini',
                ], 422);
            }

            // Jika user sebelumnya hanya pending, anggap membatalkan permintaan
            if ($membership->status === 'pending') {
                $membership->update(['status' => 'removed']);
                return response()->json([
                    'success' => true,
                    'message' => 'Permintaan bergabung dibatalkan',
                ]);
            }

            if ($membership->status === 'active') {
                // Tandai sebagai keluar (biarkan row tetap ada)
                $membership->update([
                    'status' => 'left',
                ]);

                // Tambah history: left (b)
                \App\Models\MemberHistory::create([
                    'community_id' => $community->id,
                    'user_id'      => $user->id,
                    'action'       => 'left',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Berhasil keluar dari komunitas',
                ]);
            }

            // Sudah tidak aktif (left/removed), anggap idempotent
            return response()->json([
                'success' => true,
                'message' => 'Status anda sudah bukan anggota aktif',
            ]);
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
     * POST /api/admin/communities/{id}/members
     * Tambahkan anggota baru ke komunitas oleh admin.
     */
    public function adminAddMember(Request $request, $id)
    {
        $community = Community::findOrFail($id);

        $validated = $request->validate([
            'user_identifier' => 'required|string',
        ]);

        // Bisa pakai ID atau Email
        $user = User::where('email', $validated['user_identifier'])
            ->orWhere('id', $validated['user_identifier'])
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan',
            ], 404);
        }

        $membership = CommunityMembership::updateOrCreate(
            [
                'community_id' => $community->id,
                'user_id' => $user->id,
            ],
            [
                'status' => 'active',
                'joined_at' => now(),
            ]
        );

        // Tambah history: joined (admin menambahkan member)
        \App\Models\MemberHistory::create([
            'community_id' => $community->id,
            'user_id'      => $user->id,
            'action'       => 'joined',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil ditambahkan',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'membership' => $membership,
            ],
        ], 201);
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
                // PERBAIKI: Logo optional untuk create, hanya validasi jika ada file
                'logo'                => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',

                // Tambahan field dari FE
                'corporate_id'        => 'nullable|integer|exists:corporates,id',
                'bg_color_1'          => 'nullable|string|max:16',
                'bg_color_2'          => 'nullable|string|max:16',
                'world_type'          => 'nullable|string|max:50',
                'type'                => 'nullable|string|max:50', // alias
                // PERBAIKI: Gunakan string untuk menerima "0"/"1" dari FormData
                'is_active'           => 'nullable',

                // opsional lama
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

            // NORMALISASI: Konversi is_active ke integer 0/1
            if (array_key_exists('is_active', $validated)) {
                $isActive = $validated['is_active'];
                // Handle berbagai format input dari frontend (string, bool, numeric, array checkbox)
                if (is_array($isActive)) {
                    // Contoh dari checkbox FE: [1] atau ['1']
                    $flat = collect($isActive)
                        ->map(fn($v) => is_bool($v) ? ($v ? '1' : '0') : strtolower(trim((string) $v)))
                        ->filter(fn($v) => $v !== '');
                    $validated['is_active'] = $flat->contains(fn($v) => in_array($v, ['1', 'true', 'on', 'yes'], true)) ? 1 : 0;
                } elseif (is_string($isActive)) {
                    $validated['is_active'] = in_array(strtolower($isActive), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
                } elseif (is_bool($isActive)) {
                    $validated['is_active'] = $isActive ? 1 : 0;
                } elseif (is_numeric($isActive)) {
                    $validated['is_active'] = (int) $isActive > 0 ? 1 : 0;
                } else {
                    $validated['is_active'] = 0;
                }
            } else {
                // Default value jika tidak ada
                $validated['is_active'] = 0;
            }

            // Alias: jika 'type' ada dan world_type belum diisi
            if (empty($validated['world_type']) && !empty($validated['type'])) {
                $validated['world_type'] = $validated['type'];
            }
            unset($validated['type']);

            // Handle logo upload - hanya jika ada file yang diupload
            if ($request->hasFile('logo')) {
                $validated['logo'] = $request->file('logo')->store('communities', 'public');
            } else {
                // Jika tidak ada file, hapus dari validated agar tidak disimpan
                unset($validated['logo']);
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
                // PERBAIKI: Untuk update, logo bisa kosong atau file
                'logo'                => 'nullable',

                // Tambahan field dari FE
                'corporate_id'        => 'nullable|integer|exists:corporates,id',
                'bg_color_1'          => 'nullable|string|max:16',
                'bg_color_2'          => 'nullable|string|max:16',
                'world_type'          => 'nullable|string|max:50',
                'type'                => 'nullable|string|max:50', // alias
                // PERBAIKI: Gunakan string untuk menerima "0"/"1" dari FormData
                'is_active'           => 'nullable',

                // opsional lama
                'category'            => 'nullable|string|max:100',
                'privacy'             => 'nullable|in:public,private',
                'is_verified'         => 'nullable|boolean',

                'admin_contact_ids'   => 'nullable|array',
                'admin_contact_ids.*' => 'integer|exists:users,id',
            ]);

            // Validasi khusus untuk logo jika berupa file upload
            if ($request->hasFile('logo')) {
                $logoValidator = Validator::make($request->all(), [
                    'logo' => 'file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048'
                ]);

                if ($logoValidator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Logo validation failed',
                        'errors'  => $logoValidator->errors(),
                    ], 422);
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $community = Community::findOrFail($id);
            $validated = $validator->validated();

            // NORMALISASI: Konversi is_active ke integer 0/1
            if (array_key_exists('is_active', $validated)) {
                $isActive = $validated['is_active'];
                // Handle berbagai format input dari frontend (string, bool, numeric, array checkbox)
                if (is_array($isActive)) {
                    $flat = collect($isActive)
                        ->map(fn($v) => is_bool($v) ? ($v ? '1' : '0') : strtolower(trim((string) $v)))
                        ->filter(fn($v) => $v !== '');
                    $validated['is_active'] = $flat->contains(fn($v) => in_array($v, ['1', 'true', 'on', 'yes'], true)) ? 1 : 0;
                } elseif (is_string($isActive)) {
                    $validated['is_active'] = in_array(strtolower($isActive), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
                } elseif (is_bool($isActive)) {
                    $validated['is_active'] = $isActive ? 1 : 0;
                } elseif (is_numeric($isActive)) {
                    $validated['is_active'] = (int) $isActive > 0 ? 1 : 0;
                } else {
                    $validated['is_active'] = 0;
                }
            }

            // Alias: map 'type' ke 'world_type' jika perlu
            if (empty($validated['world_type']) && !empty($validated['type'])) {
                $validated['world_type'] = $validated['type'];
            }
            unset($validated['type']);

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

    /**
     * GET /api/admin/communities/{id}/member-requests
     * Daftar membership berstatus pending (butuh approval admin)
     */
    public function adminMemberRequests(Request $request, $id)
    {
        $community = Community::findOrFail($id);

        $rows = CommunityMembership::with(['user.role'])
            ->where('community_id', $community->id)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($m) {
                return [
                    // gunakan id membership sebagai requestId untuk approve/reject
                    'id'         => $m->id,
                    'user'       => [
                        'id'    => $m->user->id,
                        'name'  => $m->user->name,
                        'email' => $m->user->email,
                        'phone' => $m->user->phone,
                        'role'  => ['name' => optional($m->user->role)->name],
                    ],
                    'status'     => $m->status,          // 'pending'
                    'created_at' => $m->created_at,      // dipakai FE untuk "Diminta pada"
                    'message'    => null,                // placeholder bila nanti ada pesan
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows->values(),
            'total_row' => $rows->count(),
        ]);
    }

    /**
     * POST /api/admin/member-requests/{id}/approve
     * Ubah pending -> active, set joined_at
     */
    public function approveMemberRequest(Request $request, $id)
    {
        $membership = CommunityMembership::with('user')->findOrFail($id);

        if ($membership->status === 'active') {
            return response()->json(['success' => true, 'message' => 'Sudah aktif'], 200);
        }

        if ($membership->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Status tidak valid'], 422);
        }

        $membership->update([
            'status'    => 'active',
            'joined_at' => now(),
        ]);

        // Tambah history: joined (d)
        \App\Models\MemberHistory::create([
            'community_id' => $membership->community_id,
            'user_id'      => $membership->user_id,
            'action'       => 'joined',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permintaan disetujui',
        ]);
    }

    /**
     * POST /api/admin/member-requests/{id}/reject
     * Ubah pending -> removed (atau hapus baris jika diinginkan)
     */
    public function rejectMemberRequest(Request $request, $id)
    {
        $membership = CommunityMembership::with('user')->findOrFail($id);

        if ($membership->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Status tidak valid'], 422);
        }

        $membership->update([
            'status' => 'removed',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permintaan ditolak',
        ]);
    }

    /**
     * GET /api/admin/communities/{id}/member-history
     * Histori sederhana dari membership: joined (active.joined_at) dan left (removed.updated_at)
     */
    public function adminMemberHistory(Request $request, $id)
    {
        $community = \App\Models\Community::findOrFail($id);

        $history = \App\Models\MemberHistory::with('user')
            ->where('community_id', $community->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($h) => [
                'user_id'    => $h->user_id,
                'user_name'  => $h->user->name ?? '-',
                'action'     => $h->action,
                'created_at' => $h->created_at,
                'user'       => [
                    'name'  => $h->user->name ?? '-',
                    'email' => $h->user->email ?? '-',
                ],
            ])
            ->values();

        return response()->json([
            'success'   => true,
            'data'      => $history,
            'total_row' => $history->count(),
        ]);
    }



    /**
     * DELETE /api/admin/communities/{community}/members/{user}
     * Hapus anggota komunitas (oleh admin)
     */
    public function adminRemoveMember($communityId, $userId)
    {
        try {
            $community = Community::findOrFail($communityId);

            $membership = CommunityMembership::where('community_id', $communityId)
                ->where('user_id', $userId)
                ->first();

            if (!$membership) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member tidak ditemukan dalam komunitas ini',
                ], 404);
            }

            // ubah status jadi removed atau hapus langsung
            $membership->update([
                'status' => 'removed',
            ]);

            // Tambah history: removed (c)
            \App\Models\MemberHistory::create([
                'community_id' => (int) $communityId,
                'user_id'      => (int) $userId,
                'action'       => 'removed',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Member berhasil dihapus dari komunitas',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus member: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function cubes($communityId)
    {
        try {
            $dynamicContents = \App\Models\DynamicContent::with([
                'dynamic_content_cubes.cube' => function ($q) {
                    // Include status so we can determine active/inactive correctly
                    $q->select('id', 'code', 'status', 'created_at');
                },
                'dynamic_content_cubes.cube.ads'
            ])
                ->where('community_id', $communityId)
                ->get();

            // Flatten cubes dari seluruh dynamic_content komunitas ini
            $cubes = $dynamicContents->flatMap(function ($dc) {
                return $dc->dynamic_content_cubes->map(function ($dcc) use ($dc) {
                    $cube = $dcc->cube;
                    $statusRaw = $cube->status ?? null;
                    $isActive = in_array(strtolower((string)$statusRaw), ['1', 'true', 'active'], true);

                    return [
                        'id'          => $cube->id ?? null,
                        'name'        => $cube->ads->first()->title ?? "Cube #" . ($cube->code ?? '-'),
                        'widget_name' => $dc->name ?? '-',
                        'widget_type' => $dc->type ?? '-',
                        'type'        => $isActive ? 'active' : 'inactive', // ✅ fix di sini
                        'created_at'  => $cube->created_at,
                    ];
                });
            })->filter(fn($c) => $c['id'] !== null)->values();


            return response()->json([
                'message' => $cubes->isEmpty() ? 'empty data' : 'success',
                'data' => $cubes,
                'total_row' => $cubes->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal mengambil kubus komunitas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'server error'], 500);
        }
    }
}
