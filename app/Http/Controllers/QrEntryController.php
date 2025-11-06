<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\EmailVerificationCode;
use App\Mail\VerificationCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QrEntryController extends Controller
{
    /**
     * Handle QR scan entry registration and verification flow
     * This endpoint is specifically designed for QR-based entry system
     */
    public function qrRegisterAndVerify(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:100|unique:users,phone', // PERBAIKAN: Tambah unique
            'password' => 'required|string|min:8|max:50|confirmed',
            'qr_data' => 'nullable|string', // QR data untuk redirect setelah verifikasi
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validate->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 1) Create user
            $user = new User();
            $user->role_id = 2;
            $user->name = $request->name;
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->password = Hash::make($request->password);
            $user->save();

            Log::info('QR Entry - User created:', ['user_id' => $user->id, 'email' => $user->email]);

            DB::commit();

            // 3) Create verification code (outside transaction)
            try {
                $verificationCode = EmailVerificationCode::createForEmail($user->email);
                
                // 4) Send verification email
                Mail::to($user->email)->send(new VerificationCodeMail($verificationCode->code));
                
                Log::info('QR Entry - Verification email sent:', ['email' => $user->email]);
                
                // 5) Create token for immediate login
                $userToken = $user->createToken('sanctum')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful. Please verify your email.',
                    'data' => [
                        'user' => $user,
                        'user_token' => $userToken,
                        'verification_expires_at' => $verificationCode->expires_at->toISOString(),
                        'qr_data' => $request->qr_data, // Pass QR data for later use
                        'redirect_url' => $this->buildRedirectUrl($request->qr_data), // TAMBAHAN
                    ]
                ], 201);

            } catch (\Throwable $th) {
                Log::error('QR Entry - Email sending failed:', ['error' => $th->getMessage()]);
                
                // User created but email failed - still return success
                $userToken = $user->createToken('sanctum')->plainTextToken;
                
                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful but email failed to send.',
                    'data' => [
                        'user' => $user,
                        'user_token' => $userToken,
                        'email_failed' => true,
                        'qr_data' => $request->qr_data,
                        'redirect_url' => $this->buildRedirectUrl($request->qr_data), // TAMBAHAN
                    ]
                ], 201);
            }

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('QR Entry - Registration failed:', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Verify email after QR registration
     */
    public function qrVerifyEmail(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'qr_data' => 'nullable|string',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validate->errors(),
            ], 422);
        }

        $email = $request->email;
        $code = $request->code;

        // Verify the code
        $isValid = EmailVerificationCode::verifyCode($email, $code);

        if (!$isValid) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code.',
            ], 400);
        }

        // Update user verification status
        $user = User::where('email', $email)->first();
        if ($user && !$user->verified_at) {
            $user->update(['verified_at' => now()]);
            Log::info('QR Entry - Email verified:', ['user_id' => $user->id, 'email' => $email]);
        }

        // Create token for immediate login after verification
        $token = null;
        if ($user) {
            try {
                $token = $user->createToken('sanctum')->plainTextToken;
            } catch (\Throwable $e) {
                Log::warning('QR Entry - Failed to create token on verify', ['user_id' => $user->id, 'err' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
            'data' => [
                'user' => $user,
                'token' => $token,
                'verified_at' => now()->toISOString(),
                'qr_data' => $request->qr_data,
                'redirect_url' => $this->buildRedirectUrl($request->qr_data), // TAMBAHAN
            ]
        ]);
    }

    /**
     * Get QR entry status
     */
    public function qrEntryStatus(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validate->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user_exists' => true,
                'is_verified' => $user->verified_at ? true : false,
                'verified_at' => $user->verified_at ? $user->verified_at->toISOString() : null,
                'created_at' => $user->created_at->toISOString(),
            ]
        ]);
    }

    /**
     * TAMBAHAN: Build redirect URL berdasarkan QR data
     */
    private function buildRedirectUrl($qrData)
    {
        if (!$qrData) {
            return '/app'; // Default redirect
        }

        // Jika qrData adalah URL yang valid, gunakan langsung
        if (filter_var($qrData, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($qrData);
            $baseUrl = config('app.frontend_url', 'https://v2.huehuy.com');
            $baseParsed = parse_url($baseUrl);

            // Pastikan host sama dengan frontend URL untuk keamanan
            if (isset($parsedUrl['host']) && isset($baseParsed['host']) && $parsedUrl['host'] === $baseParsed['host']) {
                // Extract path dan query string
                $path = $parsedUrl['path'] ?? '/app';
                $query = $parsedUrl['query'] ?? '';
                return $path . ($query ? '?' . $query : '');
            }
        }

        // Fallback jika tidak valid
        return '/app';
    }
}
