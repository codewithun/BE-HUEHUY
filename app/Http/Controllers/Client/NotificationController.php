<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        // Params
        $sortDirection = $request->get('sortDirection', 'DESC');
        $sortBy        = $request->get('sortBy', 'created_at');
        $paginate      = (int) $request->get('paginate', 10);
        $filter        = $request->get('filter');
        $typeParam     = $request->get('type'); // 'hunter' | 'merchant' | 'all' | null

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Base query + eager loads
        $query = Notification::with(
            'user',
            'cube', 'cube.ads', 'cube.cube_type',
            'ad', 'ad.cube', 'ad.cube.cube_type',
            'grab', 'grab.ad', 'grab.ad.cube', 'grab.ad.cube.cube_type'
        )->where('user_id', $user->id);

        /**
         * Mapping tab -> tipe notifikasi yang di-include:
         * - hunter   : konten konsumsi user (iklan/grab/feed) — voucher/promo TIDAK dimasukkan di sini
         * - merchant : merchant ops + voucher + promo (SESUAi PERMINTAAN)
         * - all/NULL : tidak difilter (semua tipe)
         *
         * Catatan: tipe native di DB tetap 'voucher' / 'promo', tapi ikut tab 'merchant'.
         */
        if ($typeParam === 'hunter') {
            $query->whereIn('type', ['ad', 'grab', 'feed']);
        } elseif ($typeParam === 'merchant') {
            $query->whereIn('type', ['merchant', 'order', 'settlement', 'voucher', 'promo']);
        } elseif ($typeParam && $typeParam !== 'all') {
            // fallback untuk request spesifik (mis. type=voucher)
            $query->where('type', $typeParam);
        }
        // jika 'all' atau null → tanpa filter type

        // Pencarian (jika ada helper search() di base controller)
        if ($request->filled('search')) {
            $query = $this->search($request->get('search'), new Notification(), $query);
        }

        // Filter kolom tambahan (jika ada helper filter())
        if ($filter) {
            $filters = json_decode($filter, true) ?: [];
            foreach ($filters as $column => $value) {
                $query = $this->filter($column, $value, new Notification(), $query);
            }
        }

        // Urut & paginate
        $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($paginate);

        return response()->json([
            'message'   => $paginator->isEmpty() ? 'empty data' : 'success',
            'data'      => $paginator->items(),   // FE kamu baca dari data.data
            'total_row' => $paginator->total(),
        ], 200);
    }
}
