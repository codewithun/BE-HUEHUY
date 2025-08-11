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
        $qrcodes = Qrcode::where('admin_id', $adminId)->get();
        return response()->json([
            'success' => true,
            'count' => $qrcodes->count(),
            'data' => $qrcodes
        ]);
    }

    // Generate QR code baru
    public function generate(Request $request)
    {
        $adminId = Auth::id();
        $data = $request->input('data', 'default');
        $qrSvg = QrCodeFacade::format('svg')->size(300)->generate($data);
        $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.svg';
        Storage::disk('public')->put($fileName, $qrSvg);
        $qrcode = Qrcode::create([
            'admin_id' => $adminId,
            'qr_code' => $fileName,
        ]);
        return response()->json(['path' => $fileName, 'qrcode' => $qrcode]);
    }

    // Edit QR code (update data dan file)
    public function update(Request $request, $id)
    {
        $adminId = Auth::id();
        $qrcode = Qrcode::where('id', $id)->where('admin_id', $adminId)->firstOrFail();

        $data = $request->input('data', 'default');
        $qrSvg = QrCodeFacade::format('svg')->size(300)->generate($data);
        $fileName = 'qr_codes/admin_' . $adminId . '_' . time() . '.svg';
        Storage::disk('public')->put($fileName, $qrSvg);

        // Hapus file lama
        if ($qrcode->qr_code && Storage::disk('public')->exists($qrcode->qr_code)) {
            Storage::disk('public')->delete($qrcode->qr_code);
        }

        $qrcode->update([
            'qr_code' => $fileName,
        ]);

        return response()->json(['success' => true, 'qrcode' => $qrcode]);
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
