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
     * - paginate: int (default 10)
     */
    public function index(Request $request)
    {
        // Params aman & default
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

        // TAMBAHAN: Log awal untuk debugging
        Log::info('NotifIndex', [
            'user_id' => Auth::id(),
            'type'    => $request->get('type'),
        ]);

        // Base query + eager loads (keep sama seperti sebelumnya)
        $query = Notification::with([
            'user',
            'cube', 'cube.ads', 'cube.cube_type',
            'ad', 'ad.cube', 'ad.cube.cube_type',
            'grab', 'grab.ad', 'grab.ad.cube', 'grab.ad.cube.cube_type',
            // TAMBAHAN: eager load untuk target polymorphic
            'target'
        ])->where('user_id', $user->id);

        /**
         * PERBAIKAN MAPPING TYPE:
         * - hunter   : konsumsi user (iklan/grab/feed) — voucher/promo TIDAK dimasukkan.
         * - merchant : merchant ops + voucher + promo (SESUAI PERMINTAAN).
         * - voucher/promo: filter spesifik kalau diminta eksplisit.
         * - all/null : tanpa filter tipe.
         *
         * Catatan: tipe di DB tetap 'voucher' / 'promo', tapi ikut tab 'merchant'.
         */
        if ($typeParam === 'hunter') {
            $query->whereIn('type', ['hunter', 'ad', 'grab', 'feed']);
        } elseif ($typeParam === 'merchant') {
            // PERBAIKAN: TAMBAHKAN 'voucher' dan 'promo' ke merchant
            $query->whereIn('type', ['merchant', 'order', 'settlement', 'voucher', 'promo']);
        } elseif ($typeParam && $typeParam !== 'all') {
            // filter persis untuk type lain (mis. 'voucher' saja)
            $query->where('type', $typeParam);
        }
        // jika 'all' atau null → tidak difilter by type

        // TAMBAHAN: LOGGING DEBUG UNTUK MELIHAT QUERY
        Log::info('NotificationQuery Debug', [
            'user_id' => $user->id,
            'type_param' => $typeParam,
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);

        // Pencarian (hanya jika helper ada)
        if ($request->filled('search') && method_exists($this, 'search')) {
            $query = $this->search($request->get('search'), new Notification(), $query);
        }

        // Filter kolom tambahan via helper (jika ada)
        if ($filter) {
            $filters = json_decode($filter, true) ?: [];
            if (is_array($filters) && method_exists($this, 'filter')) {
                foreach ($filters as $column => $value) {
                    $query = $this->filter($column, $value, new Notification(), $query);
                }
            }
        }

        // Order + paginate
        $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($paginate);

        // TAMBAHAN: Log hasil untuk debugging
        Log::info('NotifIndexResult', [
            'user_id' => Auth::id(),
            'total'   => $paginator->total(),
            'types'   => collect($paginator->items())->pluck('type')->unique()->values(),
        ]);

        // IMPORTANT: LengthAwarePaginator tidak punya isEmpty()
        $items = $paginator->items();
        $message = (count($items) === 0) ? 'empty data' : 'success';

        return response()->json([
            'message'   => $message,
            'data'      => $items,              // FE kamu baca dari data.data
            'total_row' => $paginator->total(), // total seluruh data (bukan cuma halaman ini)
        ], 200);
    }

    /**
     * TAMBAHAN: Mark notification as read
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
                    'message' => 'Notification not found'
                ], 404);
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => $notification
            ]);

        } catch (\Exception $e) {
            Log::error('Error marking notification as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read'
            ], 500);
        }
    }

    /**
     * TAMBAHAN: Mark all notifications as read
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
                'count' => $count
            ]);

        } catch (\Exception $e) {
            Log::error('Error marking all notifications as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read'
            ], 500);
        }
    }

    /**
     * TAMBAHAN: Get unread notification count
     */
    public function unreadCount(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $count = Notification::getUnreadCount($user->id);

            return response()->json([
                'success' => true,
                'unread_count' => $count
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting unread count: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get unread count'
            ], 500);
        }
    }
}
