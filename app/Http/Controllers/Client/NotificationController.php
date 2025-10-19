<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Ad;
use App\Models\Promo;
use App\Models\Voucher;
use App\Models\Grab;

class NotificationController extends Controller
{
    /**
     * Map "tab" UI ke kumpulan nilai kolom notifications.type
     * - hunter   => ad, grab, feed, hunter
     * - merchant => merchant, order, settlement, voucher, promo
     */
    private function mapTabTypes(?string $tab): ?array
    {
        if ($tab === 'hunter') {
            return ['ad', 'grab', 'feed', 'hunter'];
        }
        if ($tab === 'merchant') {
            return ['merchant', 'order', 'settlement', 'voucher', 'promo'];
        }
        return null; // null = tidak pakai mapping tab
    }

    /**
     * GET /api/notification
     * Query:
     * - type/tab : 'hunter' | 'merchant' | 'voucher' | 'promo' | 'all'
     * - unread_only: 0|1
     * - since: 'YYYY-MM-DD' atau ISO datetime (opsional)
     * - sortBy: created_at|id|read_at|type|title (default: created_at)
     * - sortDirection: ASC|DESC (default: DESC)
     * - paginate: 1..100 (default: 10)
     */
    public function index(Request $request)
    {
        $sortDirection = strtoupper($request->get('sortDirection', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $allowedSort = ['created_at', 'id', 'read_at', 'type', 'title'];
        $sortBy = $request->get('sortBy', 'created_at');
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'created_at';
        }

        // ====== PAGINATION CERDAS DENGAN SOFT LIMIT ======
        $paginateRaw = $request->get('paginate', 'smart');
        $paginateAll = ($paginateRaw === 'all' || $request->boolean('all'));
        $smartMode = ($paginateRaw === 'smart');
        $paginate = !$paginateAll && !$smartMode ? (int) $paginateRaw : 0;

        $smartLimit = $smartMode ? (int) $request->get('limit', 100) : 0;
        if ($smartLimit < 10) $smartLimit = 100;
        if ($smartLimit > 500) $smartLimit = 500;

        $tabParam  = $request->get('tab');
        $typeParam = $request->get('type');
        $tab = $tabParam ?: $typeParam;

        $unreadOnly = (bool) $request->boolean('unread_only', false);
        $sinceRaw   = $request->get('since');
        $cursor = $request->get('cursor');

        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        Log::info('NotifIndex', [
            'user_id' => $user->id,
            'tab' => $tab,
            'cursor' => $cursor,
        ]);

        $query = Notification::query()
            ->where('user_id', $user->id)
            ->with([
                'target' => function (MorphTo $morphTo) {
                    $morphTo->morphWith([
                        Ad::class      => [],
                        Promo::class   => ['ad'],
                        Voucher::class => ['ad'],
                        Grab::class    => ['ad'],
                    ]);
                },
            ]);

        // ====== FILTER TAB / TYPE ======
        $typesFromTab = $this->mapTabTypes($tab);
        if ($typesFromTab) {
            $query->whereIn('type', $typesFromTab);
        } elseif ($tab && $tab !== 'all') {
            $query->where('type', $tab);
        }

        // ====== FILTER UNREAD ======
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        // ====== FILTER SINCE ======
        if (!empty($sinceRaw)) {
            try {
                $since = \Carbon\Carbon::parse($sinceRaw);
                $query->where('created_at', '>=', $since);
            } catch (\Exception $e) {
                Log::warning('Invalid since parameter', ['since' => $sinceRaw]);
            }
        }

        // ====== CURSOR PAGINATION (untuk infinite scroll) ======
        if ($cursor && $smartMode) {
            if ($sortDirection === 'DESC') {
                $query->where('id', '<', $cursor);
            } else {
                $query->where('id', '>', $cursor);
            }
        }

        try {
            $query->orderBy($sortBy, $sortDirection);

            if ($smartMode) {
                $items = $query->take($smartLimit)->get();
                $hasMore = count($items) >= $smartLimit;
                $nextCursor = $hasMore && $items->isNotEmpty() ? $items->last()->id : null;
            } elseif ($paginateAll) {
                $items = $query->get();
                $hasMore = false;
                $nextCursor = null;
            } else {
                $paginated = $query->paginate($paginate ?: 10);
                $items = collect($paginated->items());
                $hasMore = $paginated->hasMorePages();
                $nextCursor = null;
            }

            // ====== INJECT STATUS LIVE VOUCHER ======
            $items = $this->injectLiveVoucherStatus($items);

            $responseData = [
                'success' => true,
                'data' => $items->values(),
                'total_row' => $items->count(),
            ];

            if ($smartMode) {
                $responseData['meta'] = [
                    'has_more' => $hasMore,
                    'next_cursor' => $nextCursor,
                    'limit' => $smartLimit,
                ];
            }

            return response()->json($responseData)->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            Log::error('NotificationController@index error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'error' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil notifikasi',
                'data' => [],
                'total_row' => 0,
            ], 500)->header('Cache-Control', 'no-store');
        }
    }

    /**
     * TAMBAH: Method untuk inject status live voucher ke notifikasi
     */
    public function injectLiveVoucherStatus($notifications)
    {
        try {
            Log::info('🔍 Starting live voucher status injection', [
                'total_items' => count($notifications)
            ]);

            // Ambil hanya notifikasi voucher
            $voucherNotifications = $notifications->filter(fn($n) => $n->type === 'voucher');
            if ($voucherNotifications->isEmpty()) return $notifications;

            $voucherIds = $voucherNotifications->pluck('target_id')->unique()->values();
            Log::info('🎯 Found voucher notifications', ['voucher_ids' => $voucherIds]);

            // Ambil data dari tabel ADS (bukan voucher)
            $ads = \App\Models\Ad::whereIn('id', $voucherIds)
                ->select([
                    'id',
                    'title',
                    'image_1',
                    'image_updated_at',
                    'finish_validate',
                    'max_grab',      // 🔥 pakai ini ganti 'stock'
                    'is_daily_grab',
                    'unlimited_grab',
                    'status'
                ])
                ->get()
                ->keyBy('id');

            Log::info('📊 Vouchers fetched from database', [
                'requested_ids' => $voucherIds,
                'found_vouchers' => $ads->keys()
            ]);

            foreach ($voucherNotifications as $notif) {
                try {
                    $ad = $ads->get($notif->target_id);
                    if (!$ad) continue;

                    // Waktu validasi
                    $validUntil = $ad->finish_validate ?? null;
                    $expired = $validUntil ? now('Asia/Jakarta')->greaterThan($validUntil) : false;

                    // Image handling
                    $imageUrl = null;
                    if ($ad->image_1) {
                        $imageUrl = \Illuminate\Support\Facades\Storage::url($ad->image_1);
                        if ($ad->image_updated_at) {
                            $imageUrl .= '?v=' . \Carbon\Carbon::parse($ad->image_updated_at)->timestamp;
                        }
                    }

                    // Inject data baru ke notifikasi
                    $notif->live_status = [
                        'ad_id'        => $ad->id,
                        'title'        => $ad->title,
                        'image_url'    => $imageUrl,
                        'max_grab'     => $ad->max_grab,
                        'is_unlimited' => (bool)$ad->unlimited_grab,
                        'is_expired'   => $expired,
                        'valid_until'  => $ad->finish_validate,
                        'status'       => $ad->status,
                    ];
                } catch (\Throwable $e) {
                    Log::error('❌ Error processing notification for live status', [
                        'notification_id' => $notif->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('✅ Injected live voucher status successfully', [
                'total_notifications' => count($notifications),
                'voucher_notifications' => $voucherNotifications->count(),
                'vouchers_found' => $ads->count(),
            ]);

            return $notifications;
        } catch (\Throwable $e) {
            Log::error('💥 injectLiveVoucherStatus fatal error', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);
            return $notifications;
        }
    }

    /**
     * POST /api/notification/{id}/read
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

            $notification = Notification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$notification) {
                return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data'    => $notification,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('NotificationController@markAsRead error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to mark notification as read'], 500);
        }
    }

    /**
     * POST /api/notification/read-all
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

            $count = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => "Marked {$count} notifications as read",
                'count'   => $count,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('NotificationController@markAllAsRead error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to mark all notifications as read'], 500);
        }
    }

    /**
     * GET /api/notification/unread-count
     */
    public function unreadCount(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

            $count = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();

            return response()->json(['success' => true, 'unread_count' => $count], 200);
        } catch (\Throwable $e) {
            Log::error('NotificationController@unreadCount error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to get unread count'], 500);
        }
    }

    /**
     * DELETE /api/notification/{id}
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $deleted = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Notification deleted', 'deleted' => $deleted]);
    }

    /**
     * DELETE /api/notification?type=merchant|hunter|voucher|promo|all
     * (atau pakai ?tab=...)
     */
    public function destroyAll(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $tabParam  = $request->get('tab');
        $typeParam = $request->get('type');
        $tab = $tabParam ?: $typeParam;

        $q = Notification::where('user_id', $user->id);

        $typesFromTab = $this->mapTabTypes($tab);
        if ($typesFromTab) {
            $q->whereIn('type', $typesFromTab);
        } elseif ($tab && $tab !== 'all') {
            $q->where('type', $tab);
        }

        $deleted = $q->delete();

        return response()->json([
            'success' => true,
            'message' => "Deleted {$deleted} notifications",
            'deleted' => $deleted,
        ]);
    }
}
