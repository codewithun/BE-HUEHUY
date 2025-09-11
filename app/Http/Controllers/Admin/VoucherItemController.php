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

class VoucherItemController extends Controller
{
    /**
     * List voucher items (punya user atau all kalau admin).
     */
    public function index(Request $request)
    {
        $query = VoucherItem::with(['voucher', 'voucher.community', 'user']);

        // Filter by user_id (opsional)
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Default: kalau tidak ada user_id, dan user login ada → filter ke user tsb
        if (!$request->filled('user_id') && Auth::check()) {
            $query->where('user_id', Auth::id());
        }

        // Search by voucher name
        if ($request->filled('search')) {
            $query->whereHas('voucher', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });
        }

        // Sort
        $sortBy = $request->get('sortBy', 'created_at');
        $sortDirection = $request->get('sortDirection', 'DESC');
        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $paginate = (int) $request->get('paginate', 15);
        $result = $query->paginate($paginate);

        return response([
            'success'      => true,
            'data'         => $result->items(),
            'total_row'    => $result->total(),
            'current_page' => $result->currentPage(),
            'last_page'    => $result->lastPage(),
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
        $userId = $request->user()?->id ?? Auth::id();
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $voucher = Voucher::find($voucherId);
        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher tidak ditemukan'
            ], 404);
        }

        if ($voucher->stock <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Stok voucher habis'
            ], 400);
        }

        // Cegah double-claim
        $already = VoucherItem::where('user_id', $userId)
            ->where('voucher_id', $voucher->id)
            ->exists();
        if ($already) {
            // Bila pernah klaim, tetap bersihkan notifikasi agar tidak muncul lagi
            $this->cleanupNotifications(
                $userId,
                $voucher->id,
                $request->input('notification_id')
            );

            return response()->json([
                'success' => false,
                'message' => 'Kamu sudah pernah klaim voucher ini'
            ], 409);
        }

        try {
            DB::beginTransaction();

            $item = new VoucherItem();
            $item->user_id    = $userId;
            $item->voucher_id = $voucher->id;
            // pakai kode voucher jika sudah ada, atau generate baru
            $item->code = $voucher->code ?: (Str::upper(Str::random(6)) . now()->format('mdHis'));
            $item->save();

            $voucher->decrement('stock');

            // Bersihkan notifikasi terkait (hapus atau mark-as-read)
            $this->cleanupNotifications(
                $userId,
                $voucher->id,
                $request->input('notification_id')
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diklaim',
                'data'    => $item->load(['voucher','voucher.community'])
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
     * Hapus / tandai-baca notifikasi yang berkaitan dengan voucher ini.
     * - Jika $notificationId ada, hapus entry spesifik itu (agar aman).
     * - Tambahan guard: hapus semua notifikasi voucher yg menunjuk target voucher tsb (type/target_type).
     */
    protected function cleanupNotifications(int $userId, int $voucherId, $notificationId = null): void
    {
        // 1) Hapus (atau tandai-baca) notifikasi spesifik bila diberikan oleh FE
        if (!empty($notificationId)) {
            Notification::where('id', $notificationId)
                ->where('user_id', $userId)
                ->delete(); // kalau mau mark-as-read: ->update(['read_at' => now()]);
        }

        // 2) Guard: hapus semua notifikasi voucher yg mengarah ke target voucher ini
        Notification::where('user_id', $userId)
            ->where(function ($q) use ($voucherId) {
                $q->where(function ($q1) use ($voucherId) {
                    $q1->where('type', 'voucher')->where('target_id', $voucherId);
                })->orWhere(function ($q2) use ($voucherId) {
                    $q2->where('target_type', 'voucher')->where('target_id', $voucherId);
                });
            })
            ->delete(); // atau ->update(['read_at' => now()]);
    }
}
