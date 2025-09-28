<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Voucher; // TAMBAH: Import model Voucher
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

        $allowedSort = ['created_at','id','read_at','type','title'];
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
            ->where('user_id', $user->id);

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
    private function injectLiveVoucherStatus($items)
    {
        try {
            if ($items->isEmpty()) {
                return $items;
            }

            Log::info('🔍 Starting live voucher status injection', [
                'total_items' => $items->count()
            ]);

            // Ambil semua voucher_id dari notifikasi type=voucher
            $voucherIds = $items->where('type', 'voucher')
                ->where('target_type', 'voucher')
                ->pluck('target_id')
                ->filter()
                ->unique()
                ->values();

            Log::info('🎯 Found voucher notifications', [
                'voucher_ids' => $voucherIds->toArray()
            ]);

            if ($voucherIds->isEmpty()) {
                Log::info('✅ No voucher notifications found, skipping injection');
                return $items;
            }

            // PERBAIKAN: Hanya ambil kolom yang pasti ada
            try {
                $vouchers = Voucher::whereIn('id', $voucherIds)
                    ->select(['id', 'valid_until', 'stock', 'name']) // HAPUS 'status' yang tidak ada
                    ->get()
                    ->keyBy('id');

                Log::info('📊 Vouchers fetched from database', [
                    'requested_ids' => $voucherIds->toArray(),
                    'found_vouchers' => $vouchers->keys()->toArray()
                ]);
            } catch (\Throwable $e) {
                Log::error('❌ Failed to fetch vouchers', [
                    'error' => $e->getMessage(),
                    'voucher_ids' => $voucherIds->toArray()
                ]);
                // Return original items jika gagal fetch voucher
                return $items;
            }

            // Inject field live_* ke setiap notifikasi voucher
            $items = $items->map(function ($notification) use ($vouchers) {
                try {
                    // Hanya proses notifikasi voucher
                    if ($notification->type !== 'voucher' || 
                        $notification->target_type !== 'voucher' || 
                        !$notification->target_id) {
                        return $notification;
                    }

                    // Ambil data voucher live
                    $voucher = $vouchers->get($notification->target_id);
                    if (!$voucher) {
                        // Voucher sudah dihapus
                        $notification->live_expired = true;
                        $notification->live_available = false;
                        $notification->live_stock = 0;
                        $notification->live_status = 'deleted';
                        return $notification;
                    }

                    // Cek status kadaluwarsa
                    $validUntil = $voucher->valid_until;
                    $expired = $validUntil ? now()->greaterThan($validUntil) : false;

                    // Cek stok
                    $stock = $voucher->stock ?? 0;
                    $outOfStock = !is_null($voucher->stock) && $stock <= 0;

                    // PERBAIKAN: Default status 'active' karena kolom status tidak ada
                    $status = 'active'; // Semua voucher yang ada dianggap aktif
                    $inactive = false;  // Tidak ada status inactive jika kolom tidak ada

                    // Inject field live ke notifikasi
                    $notification->live_valid_until = $validUntil;
                    $notification->live_expired = $expired;
                    $notification->live_stock = $stock;
                    $notification->live_status = $status;
                    $notification->live_out_of_stock = $outOfStock;
                    $notification->live_inactive = $inactive;
                    $notification->live_available = !$expired && !$outOfStock && !$inactive;
                    $notification->live_voucher_name = $voucher->name;

                    // Log untuk debugging hanya jika tidak tersedia
                    if ($expired || $outOfStock) {
                        Log::info('🔴 Voucher not available in live status', [
                            'notification_id' => $notification->id,
                            'voucher_id' => $notification->target_id,
                            'expired' => $expired,
                            'out_of_stock' => $outOfStock,
                            'valid_until' => $validUntil,
                            'stock' => $stock,
                        ]);
                    }

                    return $notification;
                } catch (\Throwable $e) {
                    Log::error('❌ Error processing notification for live status', [
                        'notification_id' => $notification->id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    return $notification;
                }
            });

            Log::info('✅ Injected live voucher status successfully', [
                'total_notifications' => $items->count(),
                'voucher_notifications' => $voucherIds->count(),
                'vouchers_found' => $vouchers->count(),
            ]);

            return $items;

        } catch (\Throwable $e) {
            Log::error('❌ Critical error in injectLiveVoucherStatus', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return original items untuk mencegah crash
            return $items;
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
            Log::error('NotificationController@markAsRead error: '.$e->getMessage());
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
            Log::error('NotificationController@markAllAsRead error: '.$e->getMessage());
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
            Log::error('NotificationController@unreadCount error: '.$e->getMessage());
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
