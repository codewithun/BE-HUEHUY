<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Qrcode;
use SimpleSoftwareIO\QrCode\Facades\QrCode as QrCodeFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class QrcodeController extends Controller
{
    // Menampilkan semua QR code milik admin
    public function index()
    {
        $adminId = Auth::id();

        // Load voucher & promo.community (tanpa voucher.community)
        $qrcodes = Qrcode::with(['voucher', 'promo.community'])
            ->where('admin_id', $adminId)
            ->get();

        return response()->json([
            'success' => true,
            'count'   => $qrcodes->count(),
            'data'    => $qrcodes,
        ]);
    }

    // Generate QR code baru — SIMPAN PNG
    public function generate(Request $request)
    {
        $request->validate([
            'voucher_id'  => 'nullable|exists:vouchers,id',
            'promo_id'    => 'nullable|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (empty($request->voucher_id) && empty($request->promo_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher atau Promo harus diisi salah satu.'
            ], 422);
        }

        $adminId    = Auth::id();
        $voucherId  = $request->input('voucher_id');
        $promoId    = $request->input('promo_id');
        $tenantName = $request->input('tenant_name');

        // URL target untuk ditanam ke QR
        $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');

        if ($promoId) {
            $promo = \App\Models\Promo::find($promoId);

            try {
                $promo->load('community');
                $communityId = $promo->community ? $promo->community->id : ($promo->community_id ?? 'global');
            } catch (\Exception $e) {
                $communityId = $promo->community_id ?? 'global';
            }

            $qrData = "{$baseUrl}/app/komunitas/promo/detail_promo?promoId={$promoId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        } else {
            // voucher global
            $qrData = "{$baseUrl}/app/voucher/{$voucherId}?autoRegister=1&source=qr_scan";
        }

        // === Generate PNG (bukan SVG) ===
        $pngBinary = QrCodeFacade::format('png')
            ->size(1024)            // tajam untuk cetak
            ->errorCorrection('H')  // toleransi tinggi
            ->margin(1)             // margin tipis
            ->generate($qrData);

        $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.png';
        Storage::disk('public')->put($fileName, $pngBinary); // simpan biner PNG ke storage/public

        $qrcode = Qrcode::create([
            'admin_id'   => $adminId,
            'qr_code'    => $fileName,   // path PNG
            'voucher_id' => $voucherId,
            'promo_id'   => $promoId,
            'tenant_name'=> $tenantName,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'QR code berhasil dibuat',
            'path'    => $fileName,
            'qrcode'  => $qrcode->load(['voucher', 'promo.community']),
        ]);
    }

    // Update QR code — regenerate PNG
    public function update(Request $request, $id)
    {
        $request->validate([
            'voucher_id'  => 'nullable|exists:vouchers,id',
            'promo_id'    => 'nullable|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (empty($request->voucher_id) && empty($request->promo_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher atau Promo harus diisi salah satu.'
            ], 422);
        }

        $adminId = Auth::id();
        $qrcode  = Qrcode::where('id', $id)->where('admin_id', $adminId)->firstOrFail();

        $voucherId  = $request->input('voucher_id');
        $promoId    = $request->input('promo_id');
        $tenantName = $request->input('tenant_name');

        $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');

        if ($promoId) {
            $promo = \App\Models\Promo::find($promoId);

            try {
                $promo->load('community');
                $communityId = $promo->community ? $promo->community->id : ($promo->community_id ?? 'global');
            } catch (\Exception $e) {
                $communityId = $promo->community_id ?? 'global';
            }

            $qrData = "{$baseUrl}/app/komunitas/promo/detail_promo?promoId={$promoId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        } else {
            $qrData = "{$baseUrl}/app/voucher/{$voucherId}?autoRegister=1&source=qr_scan";
        }

        // === Regenerate ke PNG ===
        $pngBinary = QrCodeFacade::format('png')
            ->size(1024)
            ->errorCorrection('H')
            ->margin(1)
            ->generate($qrData);

        $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.png';
        Storage::disk('public')->put($fileName, $pngBinary);

        // Hapus file lama bila ada
        if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
            Storage::disk('public')->delete($qrcode->qr_code);
        }

        $qrcode->update([
            'qr_code'    => $fileName,
            'voucher_id' => $voucherId,
            'promo_id'   => $promoId,
            'tenant_name'=> $tenantName,
        ]);

        return response()->json([
            'success' => true,
            'qrcode'  => $qrcode->load(['voucher', 'promo.community']),
        ]);
    }

    // Delete QR code
    public function destroy($id)
    {
        $adminId = Auth::id();
        $qrcode  = Qrcode::where('id', $id)->where('admin_id', $adminId)->firstOrFail();

        // Hapus file dari storage
        if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
            Storage::disk('public')->delete($qrcode->qr_code);
        }

        $qrcode->delete();

        return response()->json([
            'success' => true,
            'message' => 'QR code deleted',
        ]);
    }
}
