<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Notification;
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

        // ====== PERBAIKAN: PAGINATION CERDAS DENGAN SOFT LIMIT ======
        $paginateRaw = $request->get('paginate', 'smart'); // Default 'smart' pagination
        $paginateAll = ($paginateRaw === 'all' || $request->boolean('all'));
        $smartMode = ($paginateRaw === 'smart');
        $paginate = !$paginateAll && !$smartMode ? (int) $paginateRaw : 0;

        // Smart pagination: Ambil batch pertama dengan limit wajar
        $smartLimit = $smartMode ? (int) $request->get('limit', 100) : 0; // Default 100 untuk smart mode
        if ($smartLimit < 10) $smartLimit = 100;
        if ($smartLimit > 500) $smartLimit = 500; // Max 500 per request untuk performa

        $tabParam  = $request->get('tab');
        $typeParam = $request->get('type');
        $tab = $tabParam ?: $typeParam;

        $unreadOnly = (bool) $request->boolean('unread_only', false);
        $sinceRaw   = $request->get('since');

        // Pagination cursor untuk infinite scroll
        $cursor = $request->get('cursor'); // ID terakhir dari request sebelumnya

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::info('NotifIndex', [
            'user_id' => $user->id,
            'tab' => $tab,
            'mode' => $paginateAll ? 'all' : ($smartMode ? 'smart' : 'paginate'),
            'smart_limit' => $smartLimit,
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
            $ts = strtotime($sinceRaw);
            if ($ts !== false) {
                $query->where('created_at', '>=', date('Y-m-d H:i:s', $ts));
            }
        }

        // ====== CURSOR PAGINATION (untuk infinite scroll) ======
        if ($cursor && $smartMode) {
            if ($sortBy === 'created_at' && $sortDirection === 'DESC') {
                $query->where('created_at', '<', $cursor);
            } elseif ($sortBy === 'id' && $sortDirection === 'DESC') {
                $query->where('id', '<', $cursor);
            }
            // Tambah kondisi lain sesuai kebutuhan
        }

        try {
            // ====== COUNT TOTAL & UNREAD (OPTIMIZED) ======
            $totalCount = $query->count(); // Count dengan filter yang sama

            $unreadCount = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->when($typesFromTab, fn($q) => $q->whereIn('type', $typesFromTab))
                ->when(!$typesFromTab && $tab && $tab !== 'all', fn($q) => $q->where('type', $tab))
                ->count();

            // ====== SMART MODE: AMBIL BATCH TERBATAS ======
            if ($smartMode) {
                $items = $query->orderBy($sortBy, $sortDirection)
                    ->select([
                        'id', 'type', 'title', 'message', 'image_url', 
                        'target_type', 'target_id', 'action_url', 'meta', 
                        'read_at', 'created_at'
                    ])
                    ->limit($smartLimit)
                    ->get();

                // Tentukan cursor untuk request berikutnya
                $nextCursor = null;
                $hasMore = false;
                
                if ($items->count() === $smartLimit) {
                    $lastItem = $items->last();
                    $nextCursor = $sortBy === 'created_at' ? $lastItem->created_at : $lastItem->id;
                    
                    // Cek apakah masih ada data setelah cursor ini
                    $hasMore = $query->where($sortBy, $sortDirection === 'DESC' ? '<' : '>', $nextCursor)->exists();
                }

                Log::info('NotifIndexResult (SMART)', [
                    'user_id' => $user->id,
                    'returned' => $items->count(),
                    'total_available' => $totalCount,
                    'has_more' => $hasMore,
                    'next_cursor' => $nextCursor,
                ]);

                return response()->json([
                    'message' => $items->isEmpty() ? 'empty data' : 'success',
                    'data' => $items,
                    'total_row' => $totalCount,
                    'meta' => [
                        'mode' => 'smart',
                        'limit' => $smartLimit,
                        'returned' => $items->count(),
                        'has_more' => $hasMore,
                        'next_cursor' => $nextCursor,
                        'applied_tab' => $tab ?? 'all',
                        'applied_types' => $typesFromTab ?: ($tab && $tab !== 'all' ? [$tab] : null),
                        'unread_cnt' => $unreadCount,
                        'sorting' => ['by' => $sortBy, 'dir' => $sortDirection],
                        'filters' => ['unread_only' => $unreadOnly, 'since' => $sinceRaw],
                    ],
                ], 200);
            }

            // ====== MODE LAMA: ALL atau PAGINATE ======
            if ($paginateAll) {
                // WARNING: Hanya untuk debugging atau user dengan notif sedikit
                if ($totalCount > 1000) {
                    return response()->json([
                        'message' => 'Too many notifications. Use smart mode instead.',
                        'total_count' => $totalCount,
                        'suggestion' => 'Add ?paginate=smart&limit=100 to your request'
                    ], 413); // Payload Too Large
                }

                $items = $query->orderBy($sortBy, $sortDirection)
                    ->select([
                        'id', 'type', 'title', 'message', 'image_url', 
                        'target_type', 'target_id', 'action_url', 'meta', 
                        'read_at', 'created_at'
                    ])
                    ->get();

                return response()->json([
                    'message' => $items->isEmpty() ? 'empty data' : 'success',
                    'data' => $items,
                    'total_row' => $items->count(),
                    'meta' => [
                        'mode' => 'all',
                        'applied_tab' => $tab ?? 'all',
                        'unread_cnt' => $unreadCount,
                    ],
                ], 200);
            }

            // ====== PAGINATE BIASA ======
            if ($paginate < 1) $paginate = 50;
            if ($paginate > 200) $paginate = 200; // Batasi untuk performa

            $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($paginate);
            $items = $paginator->items();

            return response()->json([
                'message' => count($items) === 0 ? 'empty data' : 'success',
                'data' => $items,
                'total_row' => $paginator->total(),
                'meta' => [
                    'mode' => 'paginate',
                    'pagination' => [
                        'current_page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'last_page' => $paginator->lastPage(),
                        'total' => $paginator->total(),
                    ],
                    'applied_tab' => $tab ?? 'all',
                    'unread_cnt' => $unreadCount,
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('NotificationController@index error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error loading notifications',
                'error' => $e->getMessage(),
            ], 500);
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
