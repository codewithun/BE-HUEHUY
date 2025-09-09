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
            'email' => 'required|email|max:255',
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $email = $request->email;
        $code = $request->code;

        // Rate limiting: 5 attempts per minute per email
        $key = 'verify-code:' . $email;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik."
            ], 429);
        }

        // Verify the code
        $isValid = EmailVerificationCode::verifyCode($email, $code);

        if (!$isValid) {
            // Hit rate limiter for failed attempts
            RateLimiter::hit($key, 60);

            Log::warning('Invalid verification code attempt', [
                'email' => $email,
                'code' => $code
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Kode verifikasi tidak valid atau sudah kadaluarsa.'
            ], 400);
        }

        // Clear rate limiter on successful verification
        RateLimiter::clear($key);

        // Update user verification status if user exists
        $user = User::where('email', $email)->first();
        if ($user && !$user->verified_at) {
            $user->update(['verified_at' => now()]);
            Log::info('User email verified', ['user_id' => $user->id, 'email' => $email]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diverifikasi.',
            'data' => [
                'email' => $email,
                'verified_at' => now()->toISOString(),
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'verified_at' => $user->verified_at?->toISOString()
                ] : null
            ]
        ]);
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
