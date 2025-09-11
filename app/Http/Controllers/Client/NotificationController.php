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
     * GET /api/notification
     * Query params:
     * - type: 'hunter' | 'merchant' | 'voucher' | 'promo' | 'all' | null
     * - search: string (optional, jika ada helper search())
     * - filter: json string (optional, jika ada helper filter())
     * - sortBy: default 'created_at'
     * - sortDirection: 'ASC'|'DESC' (default 'DESC')
     * - paginate: int (default 10, max 100)
     */
    public function index(Request $request)
    {
        // Params
        $sortDirection = strtoupper($request->get('sortDirection', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sortBy        = $request->get('sortBy', 'created_at');
        $paginate      = (int) $request->get('paginate', 10);
        $filter        = $request->get('filter');
        $typeParam     = $request->get('type'); // 'hunter' | 'merchant' | 'voucher' | 'promo' | 'all' | null

        // Safety limit paginate
        if ($paginate <= 0 || $paginate > 100) {
            $paginate = 10;
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Log awal
        Log::info('NotifIndex', [
            'user_id' => $user->id,
            'type'    => $typeParam,
            'sortBy'  => $sortBy,
            'dir'     => $sortDirection,
            'paginate'=> $paginate,
        ]);

        /**
         * Penting:
         * - TIDAK eager-load legacy relasi (cube/ad/grab) karena kolom FK-nya tidak ada di tabel notifications.
         * - Tetap eager-load 'target' (morph) kalau kamu sudah set Morph Map di AppServiceProvider.
         */
        $query = Notification::with([
            'user',
            'target', // voucher/promo/... via polymorph (pastikan Morph Map sudah diset)
        ])->where('user_id', $user->id);

        /**
         * Mapping tab → tipe notifikasi:
         * - hunter   : konsumsi user (iklan/grab/feed)
         * - merchant : merchant ops + voucher + promo
         * - voucher/promo : filter spesifik kalau diminta eksplisit
         * - all/null : tanpa filter tipe
         */
        if ($typeParam === 'hunter') {
            $query->whereIn('type', ['ad', 'grab', 'feed']);
        } elseif ($typeParam === 'merchant') {
            $query->whereIn('type', ['merchant', 'order', 'settlement', 'voucher', 'promo']);
        } elseif ($typeParam && $typeParam !== 'all') {
            $query->where('type', $typeParam);
        }

        // Optional: search/filter helper jika tersedia di BaseController kamu
        if ($request->filled('search') && method_exists($this, 'search')) {
            $query = $this->search($request->get('search'), new Notification(), $query);
        }
        if ($filter && method_exists($this, 'filter')) {
            $filters = json_decode($filter, true) ?: [];
            if (is_array($filters)) {
                foreach ($filters as $column => $value) {
                    $query = $this->filter($column, $value, new Notification(), $query);
                }
            }
        }

        try {
            // Order + paginate
            $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($paginate);

            $items = $paginator->items();

            // Log hasil ringkas
            Log::info('NotifIndexResult', [
                'user_id' => $user->id,
                'total'   => $paginator->total(),
                'types'   => collect($items)->pluck('type')->unique()->values(),
            ]);

            return response()->json([
                'message'   => (count($items) === 0) ? 'empty data' : 'success',
                'data'      => $items,
                'total_row' => $paginator->total(),
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
     * Mark a single notification as read
     * POST /api/notification/{id}/read
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $notification = Notification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], 404);
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data'    => $notification,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('NotificationController@markAsRead error: '.$e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read',
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     * POST /api/notification/read-all
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

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
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read',
            ], 500);
        }
    }

    /**
     * Get unread notification count
     * GET /api/notification/unread-count
     */
    public function unreadCount(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $count = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();

            return response()->json([
                'success'      => true,
                'unread_count' => $count,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('NotificationController@unreadCount error: '.$e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get unread count',
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $deleted = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
            'deleted' => $deleted,
        ]);
    }

/**
 * DELETE /api/notification?type=merchant|hunter|voucher|promo|all
 * Hapus SEMUA notifikasi milik user. Bisa difilter per tab/type.
 */
    public function destroyAll(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $type = $request->get('type'); // optional
        $q = Notification::where('user_id', $user->id);

        // mapping tab ke type set
        if ($type === 'hunter') {
            $q->whereIn('type', ['ad', 'grab', 'feed']);
        } elseif ($type === 'merchant') {
            $q->whereIn('type', ['merchant', 'order', 'settlement', 'voucher', 'promo']);
        } elseif ($type && $type !== 'all') {
            // spesifik: voucher / promo / dsb
            $q->where('type', $type);
        }
        $deleted = $q->delete();

        return response()->json([
            'success' => true,
            'message' => "Deleted {$deleted} notifications",
            'deleted' => $deleted,
        ]);
    }
}
