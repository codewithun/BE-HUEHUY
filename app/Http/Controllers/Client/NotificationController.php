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
     * Query:
     * - type: 'hunter' | 'merchant' | 'voucher' | 'promo' | 'all' | null   (dipakai sbg "tab")
     * - tab:  'hunter' | 'merchant' (opsional, alternatif yg lebih eksplisit)
     * - search, filter, sortBy, sortDirection, paginate
     */
    public function index(Request $request)
    {
        $sortDirection = strtoupper($request->get('sortDirection', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sortBy        = $request->get('sortBy', 'created_at');
        $paginate      = (int) $request->get('paginate', 10);
        $filter        = $request->get('filter');

        // FE lama kirim ?type=merchant|hunter, tapi kita juga sediakan ?tab=
        $tabParam      = $request->get('tab');   // opsional
        $typeParam     = $request->get('type');  // dipakai sbg tab juga

        if ($paginate <= 0 || $paginate > 100) {
            $paginate = 10;
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::info('NotifIndex', [
            'user_id' => $user->id,
            'type'    => $typeParam,
            'tab'     => $tabParam,
            'sortBy'  => $sortBy,
            'dir'     => $sortDirection,
            'paginate'=> $paginate,
        ]);

        $query = Notification::query()
            ->with(['user','target'])
            ->where('user_id', $user->id)
            ->select('notifications.*');

        /**
         * ====== FILTERING STRATEGY ======
         * "tab" (hunter/merchant) = pengelompokan UI.
         * Kita mapping tab -> kumpulan tipe baris, plus ikutkan literal 'hunter'/'merchant' (kalau ada).
         */
        $tab = $tabParam ?: $typeParam; // kompatibel
        if ($tab === 'hunter') {
            $query->where(function($q) {
                $q->whereIn('type', ['ad', 'grab', 'feed'])
                  ->orWhere('type', 'hunter'); // <== tambahkan literal hunter
            });
        } elseif ($tab === 'merchant') {
            $query->where(function($q) {
                $q->whereIn('type', ['merchant', 'order', 'settlement', 'voucher', 'promo'])
                  ->orWhere('type', 'merchant'); // literal merchant tetap ikut
            });
        } elseif ($tab && $tab !== 'all') {
            // Kalau FE kirim spesifik type (voucher/promo/dll)
            $query->where('type', $tab);
        }
        // Catatan: jika kamu ingin dukung "type" row yang lain (mis. 'notification' dsb),
        // tambahkan di mapping atas atau kirim ?tab=all di FE untuk melihat keseluruhan.

        // Search/filter opsional
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
            // Buat meta ringkasan per-type (untuk debug cepat di FE)
            $typesCount = Notification::where('user_id', $user->id)
                ->selectRaw('type, COUNT(*) as cnt')
                ->groupBy('type')
                ->pluck('cnt','type');

            $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($paginate);
            $items     = $paginator->items();

            Log::info('NotifIndexResult', [
                'user_id' => $user->id,
                'total'   => $paginator->total(),
                'types'   => collect($items)->pluck('type')->unique()->values(),
            ]);

            return response()->json([
                'message'     => (count($items) === 0) ? 'empty data' : 'success',
                'data'        => $items,
                'total_row'   => $paginator->total(),
                'meta'        => [
                    'page'      => $paginator->currentPage(),
                    'per_page'  => $paginator->perPage(),
                    'types_cnt' => $typesCount, // { "voucher": 5, "merchant": 2, ... }
                    'applied_tab' => $tab ?? 'all',
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
     */
    public function destroyAll(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $type = $request->get('type'); // dipakai sbg "tab"
        $q = Notification::where('user_id', $user->id);

        if ($type === 'hunter') {
            $q->where(function($w){
                $w->whereIn('type', ['ad','grab','feed'])->orWhere('type','hunter');
            });
        } elseif ($type === 'merchant') {
            $q->where(function($w){
                $w->whereIn('type', ['merchant','order','settlement','voucher','promo'])->orWhere('type','merchant');
            });
        } elseif ($type && $type !== 'all') {
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
