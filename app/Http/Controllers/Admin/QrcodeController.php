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
    public function index()
    {
        $adminId = Auth::id();

        $qrcodes = Qrcode::with(['voucher', 'promo.community'])
            ->where('admin_id', $adminId)
            ->get();

        return response()->json([
            'success' => true,
            'count'   => $qrcodes->count(),
            'data'    => $qrcodes,
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
                'tenant_name'=> $tenantName,
            ]);

            return response()->json([
                'success' => true,
                'format'  => 'svg',
                'path'    => $fileName,
                'qrcode'  => $qrcode->load(['voucher', 'promo.community']),
            ]);
        } catch (Throwable $e) {
            Log::error('QR generate failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
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

            $svg = QrCode::format('svg')
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
                'tenant_name'=> $request->tenant_name,
            ]);

            return response()->json([
                'success' => true,
                'format'  => 'svg',
                'qrcode'  => $qrcode->load(['voucher', 'promo.community']),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'QR code tidak ditemukan'], 404);
        } catch (Throwable $e) {
            Log::error('QR update failed: '.$e->getMessage());
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

    private function buildQrTargetUrl(?int $voucherId, ?int $promoId): string
    {
        $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');

        if ($promoId) {
            $promo = Promo::findOrFail($promoId);
            try {
                $promo->loadMissing('community');
            } catch (Throwable $e) {}
            $communityId = $promo->community->id ?? $promo->community_id ?? 'global';
            return "{$baseUrl}/app/komunitas/promo/detail_promo?promoId={$promoId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        }

        $voucher = Voucher::findOrFail($voucherId);
        return "{$baseUrl}/app/voucher/{$voucher->id}?autoRegister=1&source=qr_scan";
    }
}