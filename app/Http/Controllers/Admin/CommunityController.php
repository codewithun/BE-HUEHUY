<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use App\Models\Promo;
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
     * Helper: Hitung promo aktif per komunitas
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
     * Helper: Shape payload untuk FE
     */
    private function shapeCommunity($community, ?int $viewerUserId = null)
    {
        $community->loadMissing(['adminContacts.role', 'corporate']);

        $members = $community->members_count
            ?? (int) DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('status', 'active')
                ->count();

        $isJoined = false;
        $hasRequested = false;
        if ($viewerUserId) {
            $isJoined = DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('user_id', $viewerUserId)
                ->where('status', 'active')
                ->exists();
            $hasRequested = DB::table('community_memberships')
                ->where('community_id', $community->id)
                ->where('user_id', $viewerUserId)
                ->where('status', 'pending')
                ->exists();
        }

        $activePromos = $this->getActivePromosCount($community->id);

        return [
            'id'            => $community->id,
            'name'          => $community->name,
            'description'   => $community->description,
            'logo'          => $community->logo,
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
     * GET /api/admin/communities
     */
    public function index(Request $request)
    {
        $query = Community::query()->with(['adminContacts.role', 'corporate']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('sortBy') && $request->filled('sortDirection')) {
            $query->orderBy($request->sortBy, $request->sortDirection);
        } else {
            $query->latest();
        }

        if ($request->has('paginate')) {
            $paginate = (int) ($request->paginate ?? 10);
            $data = $query->paginate($paginate);
            $viewerId = optional($request->user())->id;
            $items = collect($data->items())->map(function ($c) use ($viewerId) {
                return $this->shapeCommunity($c, $viewerId);
            });
            return response()->json(['data' => $items, 'total_row' => $data->total()]);
        }

        $viewerId = optional($request->user())->id;
        $data = $query->get()->map(function ($c) use ($viewerId) {
            return $this->shapeCommunity($c, $viewerId);
        });

        return response()->json(['data' => $data, 'total_row' => $data->count()]);
    }

    /**
     * GET /api/communities/with-membership
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
     */
    public function requestJoin(Request $request, $id)
    {
        try {
            $user = $request->user();
            $community = Community::findOrFail($id);

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

            $rawPrivacy   = strtolower((string)($community->privacy ?? ''));
            $rawWorldType = strtolower((string)($community->world_type ?? $community->type ?? ''));
            if ($rawWorldType === 'pribadi') $rawWorldType = 'private';
            $privacy = $rawPrivacy !== '' ? $rawPrivacy : ($rawWorldType !== '' ? $rawWorldType : 'public');

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

            $membership = $community->memberships()->where('user_id', $user->id)->first();
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
                if ($membership->status !== 'pending') {
                    $membership->update(['status' => 'pending']);
                }
            } else {
                $membership = $community->memberships()->create([
                    'user_id' => $user->id,
                    'status'  => 'pending',
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
                ->where('user_id', $user->id)->first();

            if (!$membership) {
                return response()->json(['success' => false, 'message' => 'Anda bukan anggota komunitas ini'], 422);
            }

            if ($membership->status === 'pending') {
                $membership->update(['status' => 'removed']);
                return response()->json(['success' => true, 'message' => 'Permintaan bergabung dibatalkan']);
            }

            if ($membership->status === 'active') {
                $membership->update(['status' => 'left']);
                \App\Models\MemberHistory::create([
                    'community_id' => $community->id,
                    'user_id'      => $user->id,
                    'action'       => 'left',
                ]);
                return response()->json(['success' => true, 'message' => 'Berhasil keluar dari komunitas']);
            }

            return response()->json(['success' => true, 'message' => 'Status anda sudah bukan anggota aktif']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal keluar dari komunitas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/communities/user-communities
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

            return response()->json(['success' => true, 'data' => $shaped]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user communities: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/communities/{id}/members
     */
    public function adminMembers(Request $request, $id)
    {
        $community = Community::findOrFail($id);
        $q = CommunityMembership::with(['user.role'])->where('community_id', $community->id);

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        } else {
            $q->where('status', 'active');
        }

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
                'id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email,
                'phone' => $m->user->phone, 'role' => ['name' => optional($m->user->role)->name],
                'joined_at' => $m->joined_at, 'status' => $m->status,
            ]);
            return response()->json(['data' => $items->values(), 'total_row' => $page->total()]);
        }

        $rows = $q->get()->map(fn($m) => [
            'id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email,
            'phone' => $m->user->phone, 'role' => ['name' => optional($m->user->role)->name],
            'joined_at' => $m->joined_at, 'status' => $m->status,
        ]);

        return response()->json(['data' => $rows->values(), 'total_row' => $rows->count()]);
    }

    /**
     * POST /api/admin/communities/{id}/members
     */
    public function adminAddMember(Request $request, $id)
    {
        $community = Community::findOrFail($id);
        $validated = $request->validate(['user_identifier' => 'required|string']);

        $user = User::where('email', $validated['user_identifier'])
            ->orWhere('id', $validated['user_identifier'])->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);
        }

        $membership = CommunityMembership::updateOrCreate(
            ['community_id' => $community->id, 'user_id' => $user->id],
            ['status' => 'active', 'joined_at' => now()]
        );

        \App\Models\MemberHistory::create([
            'community_id' => $community->id, 'user_id' => $user->id, 'action' => 'joined',
        ]);

        return response()->json([
            'success' => true, 'message' => 'Anggota berhasil ditambahkan',
            'data' => ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email], 'membership' => $membership],
        ], 201);
    }

    /**
     * GET /api/communities/{id}/members
     */
    public function publicMembers(Request $request, $id)
    {
        $community = Community::findOrFail($id);
        $q = CommunityMembership::with(['user.role'])->where('community_id', $community->id)->where('status', 'active');

        if ($request->filled('search')) {
            $s = trim($request->search);
            $q->whereHas('user', function ($u) use ($s) {
                $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%");
            });
        }

        $q->orderBy('joined_at', 'desc');
        $rows = $q->get()->map(fn($m) => [
            'id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email,
            'role' => ['name' => optional($m->user->role)->name], 'joined_at' => $m->joined_at,
        ]);

        return response()->json(['data' => $rows->values()]);
    }

    /**
     * ✅ POST /api/admin/communities - FIXED UPLOAD LOGIC
     */
    public function store(Request $request)
    {
        try {
            // ✅ VALIDASI LEBIH FLEKSIBEL - max 10MB, mime types lengkap
            $validator = Validator::make($request->all(), [
                'name'                => 'required|string|max:255',
                'description'         => 'nullable|string',
                'logo'                => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp,avif|max:10240', // 10MB
                'corporate_id'        => 'nullable|integer|exists:corporates,id',
                'bg_color_1'          => 'nullable|string|max:16',
                'bg_color_2'          => 'nullable|string|max:16',
                'world_type'          => 'nullable|string|max:50',
                'type'                => 'nullable|string|max:50',
                'is_active'           => 'nullable',
                'category'            => 'nullable|string|max:100',
                'privacy'             => 'nullable|in:public,private',
                'is_verified'         => 'nullable|boolean',
                'admin_contact_ids'   => 'nullable|array',
                'admin_contact_ids.*' => 'integer|exists:users,id',
            ]);

            if ($validator->fails()) {
                Log::warning('Community store validation failed', ['errors' => $validator->errors()]);
                return response()->json([
                    'success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            // Normalisasi is_active
            if (array_key_exists('is_active', $validated)) {
                $isActive = $validated['is_active'];
                if (is_array($isActive)) {
                    $flat = collect($isActive)->map(fn($v) => is_bool($v) ? ($v ? '1' : '0') : strtolower(trim((string) $v)))->filter(fn($v) => $v !== '');
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
                $validated['is_active'] = 0;
            }

            if (empty($validated['world_type']) && !empty($validated['type'])) {
                $validated['world_type'] = $validated['type'];
            }
            unset($validated['type']);

            // ✅ HANDLE FILE UPLOAD - ROBUST
            if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
                try {
                    $file = $request->file('logo');
                    $path = $file->store('communities', 'public');
                    if (!$path) {
                        throw new \Exception('Failed to store file');
                    }
                    $validated['logo'] = $path;
                    Log::info('Logo uploaded successfully', ['path' => $path, 'original_name' => $file->getClientOriginalName()]);
                } catch (\Exception $e) {
                    Log::error('File upload failed', ['error' => $e->getMessage(), 'file' => $request->file('logo')?->getClientOriginalName()]);
                    return response()->json([
                        'success' => false, 'message' => 'Failed to upload logo: ' . $e->getMessage(),
                    ], 500);
                }
            } else {
                unset($validated['logo']);
            }

            DB::beginTransaction();
            $community = Community::create(collect($validated)->except(['admin_contact_ids'])->toArray());

            $ids = collect($validated['admin_contact_ids'] ?? [])->unique()->values();
            if ($ids->isNotEmpty()) {
                $community->adminContacts()->sync($ids->all());
            }

            DB::commit();
            Log::info('Community created', ['community_id' => $community->id]);

            return response()->json([
                'success' => true, 'message' => 'Community created successfully',
                'data'    => $this->shapeCommunity($community->fresh()),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Community store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false, 'message' => 'Failed to create community: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/communities/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $community = Community::with('adminContacts.role')->findOrFail($id);
            $payload = $this->shapeCommunity($community, optional($request->user())->id);
            return response()->json(['success' => true, 'data' => $payload]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        }
    }

    /**
     * ✅ PUT /api/admin/communities/{id} - FIXED UPLOAD LOGIC
     */
    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'                => 'required|string|max:255',
                'description'         => 'nullable|string',
                'logo'                => 'nullable', // ✅ Validasi file dilakukan terpisah jika ada upload
                'corporate_id'        => 'nullable|integer|exists:corporates,id',
                'bg_color_1'          => 'nullable|string|max:16',
                'bg_color_2'          => 'nullable|string|max:16',
                'world_type'          => 'nullable|string|max:50',
                'type'                => 'nullable|string|max:50',
                'is_active'           => 'nullable',
                'category'            => 'nullable|string|max:100',
                'privacy'             => 'nullable|in:public,private',
                'is_verified'         => 'nullable|boolean',
                'admin_contact_ids'   => 'nullable|array',
                'admin_contact_ids.*' => 'integer|exists:users,id',
            ]);

            // ✅ Validasi file hanya jika ada upload
            if ($request->hasFile('logo')) {
                $logoValidator = Validator::make($request->all(), [
                    'logo' => 'file|image|mimes:jpeg,png,jpg,gif,svg,webp,avif|max:10240'
                ]);
                if ($logoValidator->fails()) {
                    Log::warning('Logo validation failed on update', ['errors' => $logoValidator->errors()]);
                    return response()->json([
                        'success' => false, 'message' => 'Logo validation failed', 'errors' => $logoValidator->errors(),
                    ], 422);
                }
            }

            if ($validator->fails()) {
                Log::warning('Community update validation failed', ['errors' => $validator->errors()]);
                return response()->json([
                    'success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors(),
                ], 422);
            }

            $community = Community::findOrFail($id);
            $validated = $validator->validated();

            // Normalisasi is_active
            if (array_key_exists('is_active', $validated)) {
                $isActive = $validated['is_active'];
                if (is_array($isActive)) {
                    $flat = collect($isActive)->map(fn($v) => is_bool($v) ? ($v ? '1' : '0') : strtolower(trim((string) $v)))->filter(fn($v) => $v !== '');
                    $validated['is_active'] = $flat->contains(fn($v) => in_array($v, ['1', 'true', 'on', 'yes'], true)) ? 1 : 0;
                } elseif (is_string($isActive)) {
                    $validated['is_active'] = in_array(strtolower($isActive), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
                } elseif (is_bool($isActive)) {
                    $validated['is_active'] = $isActive ? 1 : 0;
                } elseif (is_numeric($isActive)) {
                    $validated['is_active'] = (int) $isActive > 0 ? 1 : 0;
                }
            }

            if (empty($validated['world_type']) && !empty($validated['type'])) {
                $validated['world_type'] = $validated['type'];
            }
            unset($validated['type']);

            DB::beginTransaction();

            // ✅ HANDLE LOGO UPLOAD/REPLACE
            if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
                try {
                    // Delete old logo
                    if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                        Storage::disk('public')->delete($community->logo);
                        Log::info('Old logo deleted', ['path' => $community->logo]);
                    }
                    $file = $request->file('logo');
                    $path = $file->store('communities', 'public');
                    if (!$path) throw new \Exception('Failed to store file');
                    $validated['logo'] = $path;
                    Log::info('Logo updated', ['path' => $path]);
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Logo update failed', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false, 'message' => 'Failed to upload logo: ' . $e->getMessage(),
                    ], 500);
                }
            } elseif (is_string($request->input('logo'))) {
                $validated['logo'] = $request->input('logo');
            } else {
                unset($validated['logo']);
            }

            $community->update(collect($validated)->except(['admin_contact_ids'])->toArray());

            if ($request->has('admin_contact_ids')) {
                $ids = collect($validated['admin_contact_ids'] ?? [])->unique()->values();
                $community->adminContacts()->sync($ids->all());
            }

            DB::commit();
            Log::info('Community updated', ['community_id' => $community->id]);

            return response()->json([
                'success' => true, 'message' => 'Community updated successfully',
                'data'    => $this->shapeCommunity($community->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Community update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false, 'message' => 'Failed to update community: ' . $e->getMessage(),
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
            Log::info('Community deleted', ['community_id' => $id]);
            return response()->json(['success' => true, 'message' => 'Community deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Community delete failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to delete community: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/communities/{id}/member-requests
     */
    public function adminMemberRequests(Request $request, $id)
    {
        $community = Community::findOrFail($id);
        $rows = CommunityMembership::with(['user.role'])
            ->where('community_id', $community->id)->where('status', 'pending')
            ->orderBy('created_at', 'desc')->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email, 'phone' => $m->user->phone, 'role' => ['name' => optional($m->user->role)->name]],
                    'status' => $m->status, 'created_at' => $m->created_at, 'message' => null,
                ];
            });
        return response()->json(['success' => true, 'data' => $rows->values(), 'total_row' => $rows->count()]);
    }

    /**
     * POST /api/admin/member-requests/{id}/approve
     */
    public function approveMemberRequest(Request $request, $id)
    {
        $membership = CommunityMembership::with('user')->findOrFail($id);
        if ($membership->status === 'active') return response()->json(['success' => true, 'message' => 'Sudah aktif'], 200);
        if ($membership->status !== 'pending') return response()->json(['success' => false, 'message' => 'Status tidak valid'], 422);

        $membership->update(['status' => 'active', 'joined_at' => now()]);
        \App\Models\MemberHistory::create(['community_id' => $membership->community_id, 'user_id' => $membership->user_id, 'action' => 'joined']);
        return response()->json(['success' => true, 'message' => 'Permintaan disetujui']);
    }

    /**
     * POST /api/admin/member-requests/{id}/reject
     */
    public function rejectMemberRequest(Request $request, $id)
    {
        $membership = CommunityMembership::with('user')->findOrFail($id);
        if ($membership->status !== 'pending') return response()->json(['success' => false, 'message' => 'Status tidak valid'], 422);
        $membership->update(['status' => 'removed']);
        return response()->json(['success' => true, 'message' => 'Permintaan ditolak']);
    }

    /**
     * GET /api/admin/communities/{id}/member-history
     */
    public function adminMemberHistory(Request $request, $id)
    {
        $community = Community::findOrFail($id);
        $history = \App\Models\MemberHistory::with('user')
            ->where('community_id', $community->id)->orderByDesc('created_at')->get()
            ->map(fn($h) => [
                'user_id' => $h->user_id, 'user_name' => $h->user->name ?? '-', 'action' => $h->action,
                'created_at' => $h->created_at, 'user' => ['name' => $h->user->name ?? '-', 'email' => $h->user->email ?? '-'],
            ])->values();
        return response()->json(['success' => true, 'data' => $history, 'total_row' => $history->count()]);
    }

    /**
     * DELETE /api/admin/communities/{community}/members/{user}
     */
    public function adminRemoveMember($communityId, $userId)
    {
        try {
            $community = Community::findOrFail($communityId);
            $membership = CommunityMembership::where('community_id', $communityId)->where('user_id', $userId)->first();
            if (!$membership) return response()->json(['success' => false, 'message' => 'Member tidak ditemukan dalam komunitas ini'], 404);

            $membership->update(['status' => 'removed']);
            \App\Models\MemberHistory::create(['community_id' => (int) $communityId, 'user_id' => (int) $userId, 'action' => 'removed']);
            return response()->json(['success' => true, 'message' => 'Member berhasil dihapus dari komunitas']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal menghapus member: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/communities/{id}/cubes
     */
    public function cubes($communityId)
    {
        try {
            $dynamicContents = \App\Models\DynamicContent::with([
                'dynamic_content_cubes.cube' => function ($q) { $q->select('id', 'code', 'status', 'created_at'); },
                'dynamic_content_cubes.cube.ads'
            ])->where('community_id', $communityId)->get();

            $cubes = $dynamicContents->flatMap(function ($dc) {
                return $dc->dynamic_content_cubes->map(function ($dcc) use ($dc) {
                    $cube = $dcc->cube;
                    $statusRaw = $cube->status ?? null;
                    $isActive = in_array(strtolower((string)$statusRaw), ['1', 'true', 'active'], true);
                    return [
                        'id' => $cube->id ?? null,
                        'name' => $cube->ads->first()->title ?? "Cube #" . ($cube->code ?? '-'),
                        'widget_name' => $dc->name ?? '-', 'widget_type' => $dc->type ?? '-',
                        'type' => $isActive ? 'active' : 'inactive', 'created_at' => $cube->created_at,
                    ];
                });
            })->filter(fn($c) => $c['id'] !== null)->values();

            return response()->json(['message' => $cubes->isEmpty() ? 'empty data' : 'success', 'data' => $cubes, 'total_row' => $cubes->count()]);
        } catch (\Throwable $e) {
            Log::error('Gagal mengambil kubus komunitas', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'server error'], 500);
        }
    }
}