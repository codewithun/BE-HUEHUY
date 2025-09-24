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

        // ====== PAGINATION FLEXIBLE ======
        $paginateRaw = $request->get('paginate', 25); // default lebih besar dari 10 biar terasa “muncul banyak”
        $paginateAll = ($paginateRaw === 'all' || (int)$paginateRaw === 0 || $request->boolean('all'));
        $paginate    = $paginateAll ? 0 : (int) $paginateRaw;

        if (! $paginateAll) {
            if ($paginate < 1)   $paginate = 25;
            if ($paginate > 200) $paginate = 200; // ceiling aman untuk mobile
        }

        $tabParam  = $request->get('tab');   // opsional
        $typeParam = $request->get('type');  // kompat FE lama
        $tab = $tabParam ?: $typeParam;      // pakai salah satu

        $unreadOnly = (bool) $request->boolean('unread_only', false);
        $sinceRaw   = $request->get('since'); // string tanggal opsional

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::info('NotifIndex', [
            'user_id' => $user->id,
            'tab'     => $tab,
            'sortBy'  => $sortBy,
            'dir'     => $sortDirection,
            'paginate'=> $paginateAll ? 'all' : $paginate,
            'unread'  => $unreadOnly,
            'since'   => $sinceRaw,
        ]);

        $query = Notification::query()
            ->with(['user','target'])
            ->where('user_id', $user->id)
            ->select('notifications.*');

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

        try {
            // Ringkasan tipe (tanpa filter tab) & unread count
            $typesCount = Notification::where('user_id', $user->id)
                ->selectRaw('type, COUNT(*) as cnt')
                ->groupBy('type')
                ->pluck('cnt','type');

            $unreadCount = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();

            // ====== AMBIL SEMUA ======
            if ($paginateAll) {
                $items = $query->orderBy($sortBy, $sortDirection)->get();

                return response()->json([
                    'message'   => ($items->isEmpty() ? 'empty data' : 'success'),
                    'data'      => $items,
                    'total_row' => $items->count(),
                    'meta'      => [
                        'applied_tab'   => $tab ?? 'all',
                        'applied_types' => $typesFromTab ?: ($tab && $tab !== 'all' ? [$tab] : null),
                        'types_cnt'     => $typesCount,
                        'unread_cnt'    => $unreadCount,
                        'pagination'    => null,
                        'sorting'       => ['by'=>$sortBy,'dir'=>$sortDirection],
                        'filters'       => ['unread_only'=>$unreadOnly,'since'=>$sinceRaw],
                    ],
                ], 200);
            }

            // ====== PAGINATE BIASA ======
            $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($paginate);
            $items     = $paginator->items();

            Log::info('NotifIndexResult', [
                'user_id' => $user->id,
                'total'   => $paginator->total(),
                'types'   => collect($items)->pluck('type')->unique()->values(),
            ]);

            return response()->json([
                'message'   => (count($items) === 0) ? 'empty data' : 'success',
                'data'      => $items,
                'total_row' => $paginator->total(),
                'meta'      => [
                    'applied_tab'   => $tab ?? 'all',
                    'applied_types' => $typesFromTab ?: ($tab && $tab !== 'all' ? [$tab] : null),
                    'types_cnt'     => $typesCount,
                    'unread_cnt'    => $unreadCount,
                    'pagination'    => [
                        'current_page' => $paginator->currentPage(),
                        'per_page'     => $paginator->perPage(),
                        'last_page'    => $paginator->lastPage(),
                        'total'        => $paginator->total(),
                        'next_page_url'=> $paginator->nextPageUrl(),
                        'prev_page_url'=> $paginator->previousPageUrl(),
                    ],
                    'sorting' => ['by'=>$sortBy,'dir'=>$sortDirection],
                    'filters' => ['unread_only'=>$unreadOnly,'since'=>$sinceRaw],
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('NotificationController@index error', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error loading notifications',
                'error'   => $e->getMessage(),
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
