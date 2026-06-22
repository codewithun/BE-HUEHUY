<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Voucher;
use App\Models\VoucherItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class VoucherItemController extends Controller
{
    /**
     * List voucher items (punya user atau all kalau admin).
     */
    private function notificationAllowsUser(int $userId, string $targetName, int $targetId, ?string $code = null): bool
    {
        try {
            if (!Schema::hasTable('notifications')) {
                return false;
            }

            $hasTargetType = Schema::hasColumn('notifications', 'target_type');
            $hasType       = Schema::hasColumn('notifications', 'type');
            $hasData       = Schema::hasColumn('notifications', 'data');

            $query = DB::table('notifications')
                ->where('user_id', $userId)
                ->where('target_id', $targetId);

            if ($hasTargetType || $hasType) {
                $query->where(function ($q) use ($targetName, $hasTargetType, $hasType) {
                    if ($hasTargetType) {
                        $q->where('target_type', $targetName);
                    }

                    if ($hasType) {
                        if ($hasTargetType) {
                            $q->orWhere('type', $targetName);
                        } else {
                            $q->where('type', $targetName);
                        }
                    }
                });
            }

            if ($query->exists()) {
                return true;
            }

            if ($code && $hasData) {
                return DB::table('notifications')
                    ->where('user_id', $userId)
                    ->where('data', 'like', '%' . $code . '%')
                    ->exists();
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('Voucher claim notification check failed', [
                'user_id' => $userId,
                'target_name' => $targetName,
                'target_id' => $targetId,
                'code' => $code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function canUserClaimVoucherTarget(Voucher $voucher, int $userId): bool
    {
        $ad = null;

        try {
            if (!empty($voucher->ad_id)) {
                $ad = \App\Models\Ad::find($voucher->ad_id);
            }

            if (!$ad && !empty($voucher->code)) {
                $ad = \App\Models\Ad::where('code', $voucher->code)->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Voucher claim related ad lookup failed', [
                'voucher_id' => $voucher->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        $voucherTargetType = '';

        if (Schema::hasColumn('vouchers', 'target_type')) {
            $voucherTargetType = strtolower((string) ($voucher->target_type ?? ''));
        }

        $adTargetType = $ad ? strtolower((string) ($ad->target_type ?? '')) : '';

        $targetType = $voucherTargetType ?: $adTargetType ?: 'all';

        // Umum / kosong = boleh
        if ($targetType === '' || $targetType === 'all' || $targetType === 'null') {
            return true;
        }

        // Target user tertentu
        if ($targetType === 'user') {
            // 1) Kolom target_user_id di vouchers
            try {
                if (Schema::hasColumn('vouchers', 'target_user_id')) {
                    $targetUserId = (int) ($voucher->target_user_id ?? 0);
                    if ($targetUserId > 0 && $targetUserId === (int) $userId) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Voucher claim voucher.target_user_id check failed', [
                    'voucher_id' => $voucher->id,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            // 2) Kolom target_user_id di ads
            try {
                if ($ad && Schema::hasColumn('ads', 'target_user_id')) {
                    $targetUserId = (int) ($ad->target_user_id ?? 0);
                    if ($targetUserId > 0 && $targetUserId === (int) $userId) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Voucher claim ad.target_user_id check failed', [
                    'ad_id' => $ad->id ?? null,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            // 3) Relasi target_users di Ad
            try {
                if ($ad && method_exists($ad, 'target_users')) {
                    $allowedIds = $ad->target_users()
                        ->pluck('users.id')
                        ->map(fn($v) => (int) $v)
                        ->toArray();

                    if (in_array((int) $userId, $allowedIds, true)) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Voucher claim ad.target_users relation check failed', [
                    'ad_id' => $ad->id ?? null,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            // 4) Pivot ad_target_users
            try {
                if ($ad && Schema::hasTable('ad_target_users')) {
                    $exists = DB::table('ad_target_users')
                        ->where('ad_id', $ad->id)
                        ->where('user_id', $userId)
                        ->exists();

                    if ($exists) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Voucher claim ad_target_users check failed', [
                    'ad_id' => $ad->id ?? null,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            // 5) Notification target voucher
            if ($this->notificationAllowsUser($userId, 'voucher', (int) $voucher->id, $voucher->code ?? null)) {
                return true;
            }

            // 6) Notification target ad
            if ($ad && $this->notificationAllowsUser($userId, 'ad', (int) $ad->id, $ad->code ?? null)) {
                return true;
            }

            return false;
        }

        // Target komunitas
        if ($targetType === 'community') {
            $communityId = $voucher->community_id ?? $ad->community_id ?? null;

            if (!$communityId) {
                return false;
            }

            try {
                if (!Schema::hasTable('community_memberships')) {
                    return false;
                }

                $q = DB::table('community_memberships')
                    ->where('community_id', $communityId)
                    ->where('user_id', $userId);

                if (Schema::hasColumn('community_memberships', 'status')) {
                    $q->where('status', 'active');
                }

                return $q->exists();
            } catch (\Throwable $e) {
                Log::warning('Voucher claim community membership check failed', [
                    'voucher_id' => $voucher->id,
                    'ad_id' => $ad->id ?? null,
                    'community_id' => $communityId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        return false;
    }

    public function index(Request $request)
    {
        $q = \App\Models\VoucherItem::with(['voucher', 'user']);




        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('voucher_id')) {
            $q->where('voucher_id', (int) $request->voucher_id);
        }


        if ($request->filled('voucher_code')) {
            $code = trim($request->input('voucher_code'));
            $q->whereHas('voucher', function ($v) use ($code) {
                $v->where('code', $code);
            });
        }


        if ($request->filled('validation_type_filter')) {
            $filterType = $request->input('validation_type_filter');
            if ($filterType === 'qr_only') {
                $q->whereHas('voucher', function ($v) {
                    $v->where('validation_type', 'auto');
                });
            } elseif ($filterType === 'manual_only') {
                $q->whereHas('voucher', function ($v) {
                    $v->where('validation_type', 'manual');
                });
            }
        }

        if ($request->filled('search')) {
            $s = $request->input('search');
            $q->where(function ($w) use ($s) {
                $w->where('code', 'like', "%{$s}%")
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('voucher', fn($v) => $v->where('name', 'like', "%{$s}%"));
            });
        }

        $sortBy  = $request->get('sortBy', 'created_at');
        $sortDir = strtolower($request->get('sortDirection', 'desc')) === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sortBy, $sortDir);

        $paginate = (int) ($request->get('paginate', 15));
        if ($paginate <= 0) {
            $rows = $q->get();
            return response()->json(['success' => true, 'data' => $rows, 'total_row' => $rows->count()]);
        }

        $pg = $q->paginate($paginate);
        return response()->json([
            'success'      => true,
            'data'         => $pg->items(),
            'total_row'    => $pg->total(),
            'current_page' => $pg->currentPage(),
            'last_page'    => $pg->lastPage(),
        ]);
    }

    /**
     * Detail voucher item.
     */
    public function show(string $id)
    {
        $voucherItem = VoucherItem::with(['voucher', 'voucher.community', 'user'])
            ->findOrFail($id);

        return response([
            'success' => true,
            'data'    => $voucherItem
        ]);
    }

    /**
     * Update voucher item (mis. set used_at).
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'used_at' => 'nullable|date',
        ]);

        $voucherItem = VoucherItem::findOrFail($id);
        $voucherItem->fill($request->only(['used_at']));
        $voucherItem->save();

        return response([
            'success' => true,
            'data'    => $voucherItem
        ]);
    }

    /**
     * Hapus voucher item.
     */
    public function destroy(string $id)
    {
        $voucherItem = VoucherItem::findOrFail($id);
        $voucherItem->delete();

        return response([
            'success' => true,
            'data'    => $voucherItem
        ]);
    }

    /**
     * Klaim voucher → create VoucherItem untuk user login & kurangi stock.
     * Opsional: hapus/tandai-baca notifikasi sumber klaim (via notification_id).
     */
    public function claim(Request $request, $voucherId)
    {
        Log::info('CLAIM_PATH=VoucherItemController@claim', [
            'voucher_id' => $voucherId,
            'user_id' => $request->user()?->id,
            'at' => now()->toDateTimeString(),
        ]);
        $userId = $request->user()?->id ?? Auth::id();
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // quick hit log to identify which endpoint is invoked from FE
        Log::info('CLAIM VI hit', [
            'voucher_id' => $voucherId,
            'user_id' => $userId ?? null,
        ]);

        $voucher = Voucher::find($voucherId);
        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher tidak ditemukan'
            ], 404);
        }

        /**
         * Validasi voucher khusus penerima.
         * Jika voucher dikirim melalui notifikasi ke user tertentu,
         * maka hanya user yang punya notifikasi voucher tersebut yang boleh klaim.
         */
        // 👥 Validasi target user / komunitas
        if (!$this->canUserClaimVoucherTarget($voucher, (int) $userId)) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher ini hanya dapat diklaim oleh penerima yang ditentukan.'
            ], 403);
        }
        
        if ($voucher->stock <= 0) {
            $deleted = $this->cleanupNotifications($userId, $voucherId, $request->input('notification_id'));
        
            return response()->json([
                'success' => false,
                'message' => 'Stok voucher habis',
                'notification_deleted' => $deleted,
            ], 400);
        }

        $already = VoucherItem::where('user_id', $userId)
            ->where('voucher_id', $voucher->id)
            ->exists();

        if ($already) {
            $deleted = $this->cleanupNotifications($userId, $voucher->id, $request->input('notification_id'));
            return response()->json([
                'success' => false,
                'message' => 'Kamu sudah pernah klaim voucher ini',
                'notification_deleted' => $deleted,
            ], 409);
        }

        try {
            DB::beginTransaction();

            $item = new VoucherItem();
            $item->user_id    = $userId;
            $item->voucher_id = $voucher->id;
            $item->code       = $voucher->code ?: (Str::upper(Str::random(6)) . now()->format('mdHis'));
            $item->save();

            $voucher->decrement('stock');

            Log::info('VOUCHER_ITEM_DEC_AFTER', [
                'id' => $voucher->id,
                'stock_db' => DB::table('vouchers')->where('id', $voucher->id)->value('stock'),
            ]);

            // --- Sync ke ads.max_grab (jika voucher terhubung ke ads) ---
            if (!is_null($voucher->ad_id)) {
                $affected = DB::table('ads')
                    ->where('id', $voucher->ad_id)
                    ->whereNotNull('max_grab')
                    ->where('max_grab', '>', 0)
                    ->decrement('max_grab', 1);

                if ($affected === 0) {
                    Log::warning('Skip decrement max_grab: tidak memenuhi syarat', [
                        'ad_id' => $voucher->ad_id,
                    ]);
                } else {
                    DB::table('ads')->where('id', $voucher->ad_id)->update(['updated_at' => now()]);
                    Log::info('DEC ads.max_grab after VI-claim', [
                        'ad_id' => $voucher->ad_id,
                        'max_grab_now' => DB::table('ads')->where('id', $voucher->ad_id)->value('max_grab'),
                    ]);
                }
            }

            $deleted = $this->cleanupNotifications($userId, $voucher->id, $request->input('notification_id'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diklaim',
                'notification_deleted' => $deleted,
                'data'    => $item->load(['voucher', 'voucher.community'])
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal klaim voucher: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Redeem voucher item by code
     * POST /admin/voucher-items/{id}/redeem
     */
    public function redeem(Request $request, $id)
    {
        try {
            $item = VoucherItem::with('voucher')->find($id);
            if (! $item) {
                return response()->json(['success' => false, 'message' => 'Voucher item tidak ditemukan'], 404);
            }

            if (!is_null($item->used_at) || ($item->status ?? null) === 'redeemed' || ($item->status ?? null) === 'used') {
                return response()->json(['success' => false, 'message' => 'Voucher sudah digunakan'], 409);
            }

            $validator = Validator::make($request->all(), [
                'code' => 'required|string',
            ], [
                'code.required' => 'Kode unik wajib diisi.',
                'code.string'   => 'Kode unik tidak valid.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first() ?? 'Validasi gagal',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $inputCode = trim($request->input('code'));

            $inputCode = trim((string) $request->input('code'));

            if (!hash_equals((string) $item->code, $inputCode)) {
                return response()->json(['success' => false, 'message' => 'Kode unik tidak valid.'], 422);
            }

            $result = DB::transaction(function () use ($item) {


                if (Schema::hasColumn('voucher_items', 'used_at')) {
                    $item->used_at = Carbon::now();
                }
                if (Schema::hasColumn('voucher_items', 'status')) {
                    $item->status = 'used';
                }
                $item->save();

                return ['ok' => true, 'item' => $item];
            });

            if (! $result['ok']) {
                return response()->json(['success' => false, 'message' => 'Gagal memproses voucher'], 409);
            }

            return response()->json(['success' => true, 'data' => $result['item']]);
        } catch (\Throwable $e) {
            Log::error('Error redeem voucher item: ' . $e->getMessage(), ['id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hapus/tandai-baca notifikasi terkait voucher ini dan kembalikan jumlah yang terhapus.
     */
    protected function cleanupNotifications(int $userId, int $voucherId, $notificationId = null): int
    {
        $deleted = 0;


        if (!empty($notificationId)) {
            $deleted += Notification::where('id', $notificationId)
                ->where('user_id', $userId)
                ->delete();
        }


        $deleted += Notification::where('user_id', $userId)
            ->where(function ($q) use ($voucherId) {
                $q->where(function ($q1) use ($voucherId) {
                    $q1->where('type', 'voucher')->where('target_id', $voucherId);
                })->orWhere(function ($q2) use ($voucherId) {
                    $q2->where('target_type', 'voucher')->where('target_id', $voucherId);
                });
            })
            ->delete();

        return $deleted;
    }
}
