<?php

namespace App\Http\Controllers;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Mail\VerificationCodeMail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class EmailVerificationController extends Controller
{
    /**
     * Send verification code to email
     */
    public function sendCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $email = $request->email;

        // Rate limiting: 1 request per minute per email
        $key = 'send-verification-code:' . $email;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak permintaan. Coba lagi dalam {$seconds} detik."
            ], 429);
        }

        try {
            // Create verification code
            $verificationCode = EmailVerificationCode::createForEmail($email);

            // Send email
            Mail::to($email)->send(new VerificationCodeMail($verificationCode->code));

            // Hit rate limiter
            RateLimiter::hit($key, 60); // 60 seconds

            Log::info('Verification code sent', ['email' => $email]);

            return response()->json([
                'success' => true,
                'message' => 'Kode verifikasi telah dikirim ke email Anda.',
                'data' => [
                    'email' => $email,
                    'expires_at' => $this->iso($verificationCode->expires_at),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send verification code', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim kode verifikasi. Silakan coba lagi.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Verify the email verification code
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'   => 'required|email|max:255',
            'code'    => 'required|string|size:6',
            'qr_data' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        $email = $request->email;
        $code  = $request->code;

        // Rate limiting: 5 attempts / menit / email
        $key = 'verify-code:' . $email;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik."
            ], 429);
        }

        // Validasi kode
        $isValid = EmailVerificationCode::verifyCode($email, $code);

        if (!$isValid) {
            RateLimiter::hit($key, 60);
            Log::warning('Invalid verification code attempt', ['email' => $email, 'code' => $code]);

            return response()->json([
                'success' => false,
                'message' => 'Kode verifikasi tidak valid atau sudah kadaluarsa.'
            ], 400);
        }

        // Sukses → bersihkan limiter
        RateLimiter::clear($key);

        // Update status user + buat token sanctum
        $user  = User::where('email', $email)->first();
        $token = null;

        if ($user) {
            $now = now();

            // selalu isi verified_at (kolom yang kamu pakai)
            if (!$user->verified_at) {
                $user->verified_at = $now;
            }

            // hanya isi email_verified_at kalau kolomnya memang ada
            if (Schema::hasColumn('users', 'email_verified_at')) {
                if (empty($user->email_verified_at)) {
                    $user->email_verified_at = $now;
                }
            }

            $user->save();

            // token sanctum
            $token = $user->createToken('email-verified')->plainTextToken;
            Log::info('User email verified + token issued', ['user_id' => $user->id, 'email' => $email]);
        } else {
            Log::info('Email verified but user not found (ok for pre-reg flows)', ['email' => $email]);
        }

        // Redirect URL dari qr_data (opsional) → default /app
        $redirectUrl = '/app';
        $qrData = $request->input('qr_data');

        if ($qrData) {
            try {
                $decoded = is_string($qrData) ? json_decode($qrData, true) : $qrData;
                if (is_array($decoded) && isset($decoded['type'])) {
                    if ($decoded['type'] === 'voucher' && !empty($decoded['voucherId'])) {
                        $redirectUrl = "/app/voucher/{$decoded['voucherId']}";
                    } elseif ($decoded['type'] === 'promo' && !empty($decoded['promoId'])) {
                        $redirectUrl = "/app/promo/{$decoded['promoId']}";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('verifyCode qr_data parse fail', ['qr_data' => $qrData, 'err' => $e->getMessage()]);
            }
        }

        // Siapkan verified_at user dengan aman
        $userVerifiedIso = $user ? $this->iso($user->verified_at) : null;

        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diverifikasi.',
            'data'    => [
                'email'        => $email,
                'verified_at'  => $this->iso(now()),
                'user'         => $user ? [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'verified_at' => $userVerifiedIso,
                ] : null,
                // FE verifikasi.jsx baca 2 field ini:
                'token'        => $token,
                'redirect_url' => $redirectUrl,
            ]
        ], 200);
    }

    /**
     * Resend verification code
     */
    public function resendCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        return $this->sendCode($request);
    }

    /**
     * Check verification status
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $email = $request->email;
        $user = User::where('email', $email)->first();

        $isVerified = $user && $user->verified_at;
        $hasPendingCode = EmailVerificationCode::hasPendingVerification($email);

        return response()->json([
            'success' => true,
            'data' => [
                'email' => $email,
                'user_exists' => (bool) $user,
                'is_verified' => (bool) $isVerified,
                'verified_at' => $isVerified ? $this->iso($user->verified_at) : null,
                'has_pending_verification' => $hasPendingCode
            ]
        ]);
    }

    /**
     * Helper: amanin konversi ke ISO string (Carbon|string|null)
     */
    private function iso($value): ?string
    {
        if (!$value) return null;
        try {
            return ($value instanceof Carbon)
                ? $value->toISOString()
                : Carbon::parse((string) $value)->toISOString();
        } catch (\Throwable $e) {
            return is_string($value) ? $value : null;
        }
    }
}
