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

        // Base query + eager loads
        $query = Notification::with(
            'user',
            'cube', 'cube.ads', 'cube.cube_type',
            'ad', 'ad.cube', 'ad.cube.cube_type',
            'grab', 'grab.ad', 'grab.ad.cube', 'grab.ad.cube.cube_type'
        )->where('user_id', $user->id);

        // Filter type (hanya jika dikirim & bukan 'all')
        if ($typeParam && in_array($typeParam, ['hunter', 'merchant'], true)) {
            $query->where('type', $typeParam);
        }

        // Pencarian (kalau kamu punya helper search() di base controller)
        if ($request->filled('search')) {
            $query = $this->search($request->get('search'), new Notification(), $query);
        }

        // Filter kolom tambahan (kalau kamu pakai helper filter())
        if ($filter) {
            $filters = json_decode($filter, true) ?: [];
            foreach ($filters as $column => $value) {
                $query = $this->filter($column, $value, new Notification(), $query);
            }
        }

        // Urut & paginate
        $paginator = $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($paginate);

        // Response konsisten utk FE (FE kamu map dari dataNotifications.data)
        return response()->json([
            'message'   => $paginator->isEmpty() ? 'empty data' : 'success',
            'data'      => $paginator->items(),   // <— penting: gunakan items()
            'total_row' => $paginator->total(),
        ], 200);
    }
}
