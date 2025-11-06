<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Qrcode;
use App\Models\Promo;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode as QrCodeFacade;
use Throwable;

class QrcodeController extends Controller
{
    public function index(Request $request)
    {
        $adminId       = Auth::id();
        $sortDirection = strtoupper($request->get('sortDirection', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sortBy        = $request->get('sortBy', 'created_at');
        $paginate      = (int) $request->get('paginate', 10);
        $search        = $request->get('search', null);

        // Whitelist kolom agar aman dari SQL injection nama kolom
        $sortable = ['created_at', 'tenant_name', 'id'];
        if (!in_array($sortBy, $sortable, true)) {
            $sortBy = 'created_at';
        }

        $query = \App\Models\Qrcode::with(['voucher', 'promo.community'])
            ->where('admin_id', $adminId)
            ->when($search, function ($q) use ($search) {
                $s = trim($search);
                $q->where(function ($qq) use ($s) {
                    $qq->where('tenant_name', 'like', "%{$s}%")
                        ->orWhereHas('voucher', function ($qv) use ($s) {
                            $qv->where('name', 'like', "%{$s}%")
                                ->orWhere('code', 'like', "%{$s}%");
                        })
                        ->orWhereHas('promo', function ($qp) use ($s) {
                            $qp->where('name', 'like', "%{$s}%")
                                ->orWhere('title', 'like', "%{$s}%");
                        });
                });
            });

        // Mode ambil semua (tanpa pagination)
        if ($paginate <= 0 || $request->boolean('all')) {
            $items = $query->orderBy($sortBy, $sortDirection)->get();

            return response()->json([
                'message'   => 'success',
                'data'      => $items,
                'total_row' => $items->count(),
            ]);
        }

        // Mode paginate (TableSupervision biasanya pakai ini untuk server-side sort)
        $page = $query->orderBy($sortBy, $sortDirection)->paginate($paginate);

        // Kalau kosong, samakan gaya respons Voucher
        if (empty($page->items())) {
            return response()->json([
                'message' => 'empty data',
                'data'    => [],
            ], 200);
        }

        return response()->json([
            'message'   => 'success',
            'data'      => $page->items(),
            'total_row' => $page->total(),
        ]);
    }

    public function generate(Request $request)
    {
        $request->validate([
            'voucher_id'  => 'nullable|integer|exists:vouchers,id',
            'promo_id'    => 'nullable|integer|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (!$request->voucher_id && !$request->promo_id) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher atau Promo harus diisi salah satu.'
            ], 422);
        }

        try {
            $adminId    = Auth::id();
            $voucherId  = $request->voucher_id;
            $promoId    = $request->promo_id;
            $tenantName = $request->tenant_name;

            $qrData = $this->buildQrTargetUrl($voucherId, $promoId);

            // ===== Generate SVG (default simple-qrcode) =====
            $svg = QrCodeFacade::format('svg')
                ->size(512)
                ->errorCorrection('H')
                ->margin(1)
                ->generate($qrData);

            $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.svg';
            Storage::disk('public')->put($fileName, $svg);

            $qrcode = Qrcode::create([
                'admin_id'   => $adminId,
                'qr_code'    => $fileName,
                'voucher_id' => $voucherId,
                'promo_id'   => $promoId,
                'tenant_name' => $tenantName,
            ]);

            return response()->json([
                'success' => true,
                'format'  => 'svg',
                'path'    => $fileName,
                'qrcode'  => $qrcode->load(['voucher', 'promo.community']),
            ]);
        } catch (Throwable $e) {
            Log::error('QR generate failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Gagal membuat QR code'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'voucher_id'  => 'nullable|integer|exists:vouchers,id',
            'promo_id'    => 'nullable|integer|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (!$request->voucher_id && !$request->promo_id) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher atau Promo harus diisi salah satu.'
            ], 422);
        }

        $adminId = Auth::id();

        try {
            $qrcode = Qrcode::where('id', $id)
                ->where('admin_id', $adminId)
                ->firstOrFail();

            $qrData = $this->buildQrTargetUrl($request->voucher_id, $request->promo_id);

            $svg = QrCodeFacade::format('svg') // <-- Ubah QrCode menjadi QrCodeFacade
                ->size(512)
                ->errorCorrection('H')
                ->margin(1)
                ->generate($qrData);

            $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.svg';
            Storage::disk('public')->put($fileName, $svg);

            if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
                Storage::disk('public')->delete($qrcode->qr_code);
            }

            $qrcode->update([
                'qr_code'    => $fileName,
                'voucher_id' => $request->voucher_id,
                'promo_id'   => $request->promo_id,
                'tenant_name' => $request->tenant_name,
            ]);

            return response()->json([
                'success' => true,
                'format'  => 'svg',
                'qrcode'  => $qrcode->load(['voucher', 'promo.community']),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'QR code tidak ditemukan'], 404);
        } catch (Throwable $e) {
            Log::error('QR update failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memperbarui QR code'], 500);
        }
    }

    public function destroy($id)
    {
        $adminId = Auth::id();

        try {
            $qrcode = Qrcode::where('id', $id)
                ->where('admin_id', $adminId)
                ->firstOrFail();

            if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
                Storage::disk('public')->delete($qrcode->qr_code);
            }

            $qrcode->delete();

            return response()->json(['success' => true, 'message' => 'QR code deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'QR code tidak ditemukan'], 404);
        }
    }

    public function file(Request $request, $id)
    {
        $adminId = Auth::id();

        $qrcode = Qrcode::where('id', $id)
            ->where('admin_id', $adminId)
            ->firstOrFail();

        $path = $qrcode->qr_code;
        if (!$path || !Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        // Resolve full filesystem path for the "public" disk (storage/app/public)
        $fullPath = storage_path('app/public/' . ltrim($path, '/'));
        $mime = @mime_content_type($fullPath) ?: 'application/octet-stream';

        return response()->file($fullPath, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    private function buildQrTargetUrl(?int $voucherId, ?int $promoId): string
    {
        $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');

        if ($promoId) {
            $promo = Promo::with('community')->findOrFail($promoId);
            $communityId = $promo->community ? $promo->community->id : null;
            if (!$communityId) {
                throw new \Exception('Promo tidak memiliki komunitas terkait.');
            }
            return $baseUrl . '/app/komunitas/promo/' . $promo->id . '?communityId=' . $communityId . '&autoRegister=1&source=qr_scan';
        }

        $voucher = Voucher::findOrFail($voucherId);
        return "{$baseUrl}/app/voucher/{$voucher->id}?autoRegister=1&source=qr_scan";
    }
}
