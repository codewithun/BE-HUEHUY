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
                    'expires_at' => $verificationCode->expires_at->toISOString()
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
            'qr_data' => 'nullable', // biar bisa redirect khusus (opsional)
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

        // Validasi kode (table email_verification_codes)
        $isValid = EmailVerificationCode::verifyCode($email, $code);

        if (!$isValid) {
            RateLimiter::hit($key, 60); // penalti 1 menit
            Log::warning('Invalid verification code attempt', ['email' => $email, 'code' => $code]);

            return response()->json([
                'success' => false,
                'message' => 'Kode verifikasi tidak valid atau sudah kadaluarsa.'
            ], 400);
        }

        // Berhasil → clear limiter
        RateLimiter::clear($key);

        // Update status user + buat token sanctum
        $user  = User::where('email', $email)->first();
        $token = null;

        if ($user) {
            $now = now();
            if (!$user->verified_at) {
                $user->verified_at = $now;
            }
            if (empty($user->email_verified_at)) {
                $user->email_verified_at = $now; // kalau kolom ini ada di schema-mu
            }
            $user->save();

            // === INI KUNCI: kembalikan token agar FE bisa langsung login ===
            $token = $user->createToken('email-verified')->plainTextToken;
            Log::info('User email verified + token issued', ['user_id' => $user->id, 'email' => $email]);
        } else {
            Log::info('Email verified but user not found (ok for pre-reg flows)', ['email' => $email]);
        }

        // Tentukan redirect_url dari qr_data (opsional) → default /app
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

        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diverifikasi.',
            'data'    => [
                'email'        => $email,
                'verified_at'  => now()->toISOString(),
                'user'         => $user ? [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'verified_at' => optional($user->verified_at)->toISOString(),
                ] : null,

                // === FE (verifikasi.jsx) akan baca dua field ini ===
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
        // Add extra validation for resend
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255|exists:users,email', // Must be existing user
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
                'user_exists' => $user ? true : false,
                'is_verified' => $isVerified,
                'verified_at' => $isVerified ? $user->verified_at->toISOString() : null,
                'has_pending_verification' => $hasPendingCode
            ]
        ]);
    }
}
