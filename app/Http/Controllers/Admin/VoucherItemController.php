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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class VoucherItemController extends Controller
{
    /**
     * List voucher items (punya user atau all kalau admin).
     */
    public function index(Request $request)
    {
        $q = \App\Models\VoucherItem::with(['voucher', 'user']);




        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('voucher_id')) {
            $q->where('voucher_id', (int) $request->voucher_id);
        }


        if ($request->filled('voucher_code')) {
            $code = trim($request->input('voucher_code'));
            $q->whereHas('voucher', function ($v) use ($code) {
                $v->where('code', $code);
            });
        }


        if ($request->filled('validation_type_filter')) {
            $filterType = $request->input('validation_type_filter');
            if ($filterType === 'qr_only') {
                $q->whereHas('voucher', function ($v) {
                    $v->where('validation_type', 'auto');
                });
            } elseif ($filterType === 'manual_only') {
                $q->whereHas('voucher', function ($v) {
                    $v->where('validation_type', 'manual');
                });
            }
        }

        if ($request->filled('search')) {
            $s = $request->input('search');
            $q->where(function ($w) use ($s) {
                $w->where('code', 'like', "%{$s}%")
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('voucher', fn($v) => $v->where('name', 'like', "%{$s}%"));
            });
        }

        $sortBy  = $request->get('sortBy', 'created_at');
        $sortDir = strtolower($request->get('sortDirection', 'desc')) === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sortBy, $sortDir);

        $paginate = (int) ($request->get('paginate', 15));
        if ($paginate <= 0) {
            $rows = $q->get();
            return response()->json(['success' => true, 'data' => $rows, 'total_row' => $rows->count()]);
        }

        $pg = $q->paginate($paginate);
        return response()->json([
            'success'      => true,
            'data'         => $pg->items(),
            'total_row'    => $pg->total(),
            'current_page' => $pg->currentPage(),
            'last_page'    => $pg->lastPage(),
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
        Log::info('CLAIM_PATH=VoucherItemController@claim', [
            'voucher_id' => $voucherId,
            'user_id' => $request->user()?->id,
            'at' => now()->toDateTimeString(),
        ]);
        $userId = $request->user()?->id ?? Auth::id();
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // quick hit log to identify which endpoint is invoked from FE
        Log::info('CLAIM VI hit', [
            'voucher_id' => $voucherId,
            'user_id' => $userId ?? null,
        ]);

        $voucher = Voucher::find($voucherId);
        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher tidak ditemukan'
            ], 404);
        }

        if ($voucher->stock <= 0) {

            $deleted = $this->cleanupNotifications($userId, $voucherId, $request->input('notification_id'));
            return response()->json([
                'success' => false,
                'message' => 'Stok voucher habis',
                'notification_deleted' => $deleted,
            ], 400);
        }


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

            Log::info('VOUCHER_ITEM_DEC_AFTER', [
                'id' => $voucher->id,
                'stock_db' => DB::table('vouchers')->where('id', $voucher->id)->value('stock'),
            ]);

            // --- Sync ke ads.max_grab (jika voucher terhubung ke ads) ---
            if (!is_null($voucher->ad_id)) {
                $affected = DB::table('ads')
                    ->where('id', $voucher->ad_id)
                    ->whereNotNull('max_grab')
                    ->where('max_grab', '>', 0)
                    ->decrement('max_grab', 1);

                if ($affected === 0) {
                    Log::warning('Skip decrement max_grab: tidak memenuhi syarat', [
                        'ad_id' => $voucher->ad_id,
                    ]);
                } else {
                    DB::table('ads')->where('id', $voucher->ad_id)->update(['updated_at' => now()]);
                    Log::info('DEC ads.max_grab after VI-claim', [
                        'ad_id' => $voucher->ad_id,
                        'max_grab_now' => DB::table('ads')->where('id', $voucher->ad_id)->value('max_grab'),
                    ]);
                }
            }

            $deleted = $this->cleanupNotifications($userId, $voucher->id, $request->input('notification_id'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil diklaim',
                'notification_deleted' => $deleted,
                'data'    => $item->load(['voucher', 'voucher.community'])
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
        try {
            $item = VoucherItem::with('voucher')->find($id);
            if (! $item) {
                return response()->json(['success' => false, 'message' => 'Voucher item tidak ditemukan'], 404);
            }

            if (!is_null($item->used_at) || ($item->status ?? null) === 'redeemed' || ($item->status ?? null) === 'used') {
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

            $inputCode = trim($request->input('code'));

            $inputCode = trim((string) $request->input('code'));

            if (!hash_equals((string) $item->code, $inputCode)) {
                return response()->json(['success' => false, 'message' => 'Kode unik tidak valid.'], 422);
            }

            $result = DB::transaction(function () use ($item) {


                if (Schema::hasColumn('voucher_items', 'used_at')) {
                    $item->used_at = Carbon::now();
                }
                if (Schema::hasColumn('voucher_items', 'status')) {
                    $item->status = 'used';
                }
                $item->save();

                return ['ok' => true, 'item' => $item];
            });

            if (! $result['ok']) {
                return response()->json(['success' => false, 'message' => 'Gagal memproses voucher'], 409);
            }

            return response()->json(['success' => true, 'data' => $result['item']]);
        } catch (\Throwable $e) {
            Log::error('Error redeem voucher item: ' . $e->getMessage(), ['id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses voucher: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hapus/tandai-baca notifikasi terkait voucher ini dan kembalikan jumlah yang terhapus.
     */
    protected function cleanupNotifications(int $userId, int $voucherId, $notificationId = null): int
    {
        $deleted = 0;


        if (!empty($notificationId)) {
            $deleted += Notification::where('id', $notificationId)
                ->where('user_id', $userId)
                ->delete();
        }


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
