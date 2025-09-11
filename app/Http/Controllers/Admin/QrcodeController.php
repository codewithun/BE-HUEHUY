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
    // Jangan load voucher.community, hanya load voucher dan promo.community
    $qrcodes = Qrcode::with(['voucher', 'promo.community'])->where('admin_id', $adminId)->get();
    
    return response()->json([
        'success' => true,
        'count' => $qrcodes->count(),
        'data' => $qrcodes
    ]);
    }

    // Generate QR code baru
    public function generate(Request $request)
    {
        $request->validate([
            'voucher_id' => 'nullable|exists:vouchers,id',
            'promo_id' => 'nullable|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (empty($request->voucher_id) && empty($request->promo_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher atau Promo harus diisi salah satu.'
            ], 422);
        }

        $adminId = Auth::id();
        $voucherId = $request->input('voucher_id');
        $promoId = $request->input('promo_id');
        $tenantName = $request->input('tenant_name');

        // Build proper URL for QR code instead of JSON
        $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');
        
        if ($promoId) {
            $promo = \App\Models\Promo::find($promoId);
            
            // Try to load community relationship if it exists
            try {
                $promo->load('community');
                $communityId = $promo->community ? $promo->community->id : ($promo->community_id ?? 'global');
            } catch (\Exception $e) {
                // If community relationship doesn't exist, use global or promo's community_id
                $communityId = $promo->community_id ?? 'global';
            }
            
            $qrData = "{$baseUrl}/app/komunitas/promo/detail_promo?promoId={$promoId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        } else if ($voucherId) {
            $voucher = \App\Models\Voucher::find($voucherId);
            
            // Langsung gunakan community_id field, jangan load relationship
            $communityId = $voucher->community_id ?? 'global';
            
            $qrData = "{$baseUrl}/app/voucher/detail_voucher?voucherId={$voucherId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        }

        $qrSvg = QrCodeFacade::format('svg')->size(300)->generate($qrData);
        $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.svg';
        Storage::disk('public')->put($fileName, $qrSvg);
        
        $qrcode = Qrcode::create([
            'admin_id' => $adminId,
            'qr_code' => $fileName,
            'voucher_id' => $voucherId,
            'promo_id' => $promoId,
            'tenant_name' => $tenantName,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'QR code berhasil dibuat',
            'path' => $fileName, 
            'qrcode' => $qrcode->load(['voucher', 'promo.community'])
        ]);
    }

    // Edit QR code (update data dan file)
    public function update(Request $request, $id)
    {
        $request->validate([
            'voucher_id' => 'nullable|exists:vouchers,id',
            'promo_id' => 'nullable|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (empty($request->voucher_id) && empty($request->promo_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher atau Promo harus diisi salah satu.'
            ], 422);
        }

        $adminId = Auth::id();
        $qrcode = Qrcode::where('id', $id)->where('admin_id', $adminId)->firstOrFail();

        $voucherId = $request->input('voucher_id');
        $promoId = $request->input('promo_id');
        $tenantName = $request->input('tenant_name');

        // Build proper URL for QR code instead of JSON
        $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');
        
        if ($promoId) {
            $promo = \App\Models\Promo::find($promoId);
            
            // Try to load community relationship if it exists
            try {
                $promo->load('community');
                $communityId = $promo->community ? $promo->community->id : ($promo->community_id ?? 'global');
            } catch (\Exception $e) {
                // If community relationship doesn't exist, use global or promo's community_id
                $communityId = $promo->community_id ?? 'global';
            }
            
            $qrData = "{$baseUrl}/app/komunitas/promo/detail_promo?promoId={$promoId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        } else if ($voucherId) {
            $voucher = \App\Models\Voucher::find($voucherId);
            
            // Langsung gunakan community_id field, jangan load relationship
            $communityId = $voucher->community_id ?? 'global';
            
            $qrData = "{$baseUrl}/app/voucher/detail_voucher?voucherId={$voucherId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        }

        $qrSvg = QrCodeFacade::format('svg')->size(300)->generate($qrData);
        $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.svg';
        Storage::disk('public')->put($fileName, $qrSvg);

        // Hapus file lama
        if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
            Storage::disk('public')->delete($qrcode->qr_code);
        }

        $qrcode->update([
            'qr_code' => $fileName,
            'voucher_id' => $voucherId,
            'promo_id' => $promoId,
            'tenant_name' => $tenantName,
        ]);

        return response()->json(['success' => true, 'qrcode' => $qrcode->load(['voucher', 'promo.community'])]);
    }

    // Delete QR code
    public function destroy($id)
    {
        $adminId = Auth::id();
        $qrcode = Qrcode::where('id', $id)->where('admin_id', $adminId)->firstOrFail();

        // Hapus file dari storage
        if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
            Storage::disk('public')->delete($qrcode->qr_code);
        }

        $qrcode->delete();

        return response()->json(['success' => true, 'message' => 'QR code deleted']);
    }
}
