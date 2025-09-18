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
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

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
            // stok habis → tetap coba bersihkan notif agar tidak mengganggu FE
            $deleted = $this->cleanupNotifications($userId, $voucherId, $request->input('notification_id'));
            return response()->json([
                'success' => false,
                'message' => 'Stok voucher habis',
                'notification_deleted' => $deleted,
            ], 400);
        }

        // Cegah double-claim
        $already = VoucherItem::where('user_id', $userId)
            ->where('voucher_id', $voucher->id)
            ->exists();

        if ($already) {
            $deleted = $this->cleanupNotifications($userId, $voucher->id, $request->input('notification_id'));
            return response()->json([
                'success' => false,
                'message' => 'Kamu sudah pernah klaim voucher ini',
                'notification_deleted' => $deleted,
            ], 409);
        }

        try {
            DB::beginTransaction();

            $item = new VoucherItem();
            $item->user_id    = $userId;
            $item->voucher_id = $voucher->id;
            $item->code       = $voucher->code ?: (Str::upper(Str::random(6)) . now()->format('mdHis'));
            $item->save();

            $voucher->decrement('stock');

            $deleted = $this->cleanupNotifications($userId, $voucher->id, $request->input('notification_id'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diklaim',
                'notification_deleted' => $deleted,
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
     * Redeem voucher item by code
     * POST /admin/voucher-items/{id}/redeem
     */
    public function redeem(Request $request, $id)
    {
        $item = VoucherItem::with('voucher')->find($id);
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Voucher item tidak ditemukan'], 404);
        }

        // Sudah pernah digunakan
        if (!is_null($item->used_at) || $item->status === 'redeemed' || $item->status === 'used') {
            return response()->json(['success' => false, 'message' => 'Voucher sudah digunakan'], 409);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
        ], [
            'code.required' => 'Kode unik wajib diisi.',
            'code.string'   => 'Kode unik tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first() ?? 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Cek kode voucher item
        $inputCode = trim($request->input('code'));
        if (strcasecmp($inputCode, (string) $item->code) !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'Kode unik tidak valid.',
            ], 422);
        }

        $voucher = $item->voucher ?? Voucher::find($item->voucher_id);

        $result = DB::transaction(function () use ($item, $voucher) {
            // Decrement stok jika stok dikelola (tidak null)
            if ($voucher && !is_null($voucher->stock)) {
                $affected = Voucher::where('id', $voucher->id)
                    ->where('stock', '>', 0)
                    ->decrement('stock');

                if ($affected === 0) {
                    return ['ok' => false, 'reason' => 'Stok voucher habis'];
                }
            }

            // Tandai sebagai digunakan
            $item->used_at = Carbon::now();
            // Opsional jika ada kolom status
            if (property_exists($item, 'status')) {
                $item->status = 'used';
            }
            $item->save();

            return ['ok' => true, 'item' => $item];
        });

        if (! $result['ok']) {
            return response()->json(['success' => false, 'message' => $result['reason'] ?? 'Stok voucher habis'], 409);
        }

        return response()->json(['success' => true, 'data' => $result['item']]);
    }

    /**
     * Hapus/tandai-baca notifikasi terkait voucher ini dan kembalikan jumlah yang terhapus.
     */
    protected function cleanupNotifications(int $userId, int $voucherId, $notificationId = null): int
    {
        $deleted = 0;

        // 1) spesifik by id jika dikirim FE
        if (!empty($notificationId)) {
            $deleted += Notification::where('id', $notificationId)
                ->where('user_id', $userId)
                ->delete(); // kalau mau mark read: ->update(['read_at' => now()]);
        }

        // 2) guard: hapus semua notifikasi voucher yg menarget voucher ini
        $deleted += Notification::where('user_id', $userId)
            ->where(function ($q) use ($voucherId) {
                $q->where(function ($q1) use ($voucherId) {
                    $q1->where('type', 'voucher')->where('target_id', $voucherId);
                })->orWhere(function ($q2) use ($voucherId) {
                    $q2->where('target_type', 'voucher')->where('target_id', $voucherId);
                });
            })
            ->delete();

        return $deleted;
    }
}
