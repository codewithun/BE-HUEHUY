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
use Throwable;

// === BaconQrCode (Imagick backend) ===
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrcodeController extends Controller
{
    // Menampilkan semua QR code milik admin
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

    // Generate QR code baru — SIMPAN PNG (Imagick)
    public function generate(Request $request)
    {
        $request->validate([
            'voucher_id'  => 'nullable|integer|exists:vouchers,id',
            'promo_id'    => 'nullable|integer|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (empty($request->voucher_id) && empty($request->promo_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher atau Promo harus diisi salah satu.'
            ], 422);
        }

        try {
            $adminId    = Auth::id();
            $voucherId  = $request->input('voucher_id');
            $promoId    = $request->input('promo_id');
            $tenantName = $request->input('tenant_name');

            // Bangun URL target untuk QR
            $qrData = $this->buildQrTargetUrl($voucherId, $promoId);

            // === Generate PNG via BaconQrCode + Imagick ===
            $pngBinary = $this->makePngWithImagick($qrData, 1024, 1); // size=1024, margin=1

            $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.png';
            Storage::disk('public')->put($fileName, $pngBinary);

            $qrcode = Qrcode::create([
                'admin_id'   => $adminId,
                'qr_code'    => $fileName,   // path PNG relatif di disk 'public'
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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data promo/voucher tidak ditemukan.',
            ], 404);
        } catch (Throwable $e) {
            Log::error('QR generate failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat QR PNG di server.',
            ], 500);
        }
    }

    // Update QR code — regenerate PNG (Imagick)
    public function update(Request $request, $id)
    {
        $request->validate([
            'voucher_id'  => 'nullable|integer|exists:vouchers,id',
            'promo_id'    => 'nullable|integer|exists:promos,id',
            'tenant_name' => 'required|string|max:255',
        ]);

        if (empty($request->voucher_id) && empty($request->promo_id)) {
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

            $voucherId  = $request->input('voucher_id');
            $promoId    = $request->input('promo_id');
            $tenantName = $request->input('tenant_name');

            $qrData = $this->buildQrTargetUrl($voucherId, $promoId);

            $pngBinary = $this->makePngWithImagick($qrData, 1024, 1);

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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'QR code tidak ditemukan / tidak milik Anda.',
            ], 404);
        } catch (Throwable $e) {
            Log::error('QR update failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui QR PNG di server.',
            ], 500);
        }
    }

    // Delete QR code
    public function destroy($id)
    {
        $adminId = Auth::id();

        try {
            $qrcode  = Qrcode::where('id', $id)
                ->where('admin_id', $adminId)
                ->firstOrFail();

            if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
                Storage::disk('public')->delete($qrcode->qr_code);
            }

            $qrcode->delete();

            return response()->json([
                'success' => true,
                'message' => 'QR code deleted',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'QR code tidak ditemukan / tidak milik Anda.',
            ], 404);
        } catch (Throwable $e) {
            Log::error('QR destroy failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus QR code.',
            ], 500);
        }
    }

    /**
     * Bangun URL target untuk ditanam di QR.
     * - Promo: butuh communityId (ambil dari relasi bila ada; fallback ke field, lalu 'global')
     * - Voucher: global, tidak butuh communityId
     */
    private function buildQrTargetUrl(?int $voucherId, ?int $promoId): string
    {
        $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');

        if ($promoId) {
            /** @var Promo $promo */
            $promo = Promo::findOrFail($promoId);
            try {
                $promo->loadMissing('community');
            } catch (Throwable $e) {}

            $communityId = $promo->community->id
                ?? $promo->community_id
                ?? 'global';

            return "{$baseUrl}/app/komunitas/promo/detail_promo?promoId={$promoId}&communityId={$communityId}&autoRegister=1&source=qr_scan";
        }

        /** @var Voucher $voucher */
        $voucher = Voucher::findOrFail($voucherId);
        return "{$baseUrl}/app/voucher/{$voucher->id}?autoRegister=1&source=qr_scan";
    }

    /**
     * Generate PNG binary dengan BaconQrCode + Imagick backend.
     *
     * @param string $data   Data/URL yang akan di-encode
     * @param int    $size   Ukuran sisi (px)
     * @param int    $margin Margin (px)
     * @return string        Binary PNG
     */
    private function makePngWithImagick(string $data, int $size = 1024, int $margin = 1): string
    {
        if (!extension_loaded('imagick')) {
            // Biar errornya jelas kalau imagick belum aktif
            throw new \RuntimeException('PHP extension "imagick" belum aktif. Aktifkan dulu untuk render PNG.');
        }

        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new ImagickImageBackEnd()
        );

        $writer = new Writer($renderer);
        return $writer->writeString($data); // PNG binary
    }
}
