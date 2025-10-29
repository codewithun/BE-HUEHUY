<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Mail\UserRegisterMail;
use App\Mail\VerificationCodeMail;
use App\Models\PasswordReset;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\CorporateUser;
use App\Models\UserTokenVerify;
use App\Models\EmailVerificationCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    /**
     * API for login to get user access token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function login(Request $request)
    {
        // Log request masuk untuk debugging
        Log::info('Login attempt:', [
            'request_data' => $request->all(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type')
        ]);

        // =========================>
        // ## validate request
        // =========================>
        $validate = Validator::make($request->all(), [
            "email" => 'required|string|email|max:255',
            "password" => 'required',
            "scope" => ['nullable', 'string', Rule::in(['admin', 'corporate', 'user'])] // Make scope optional
        ]);

        if ($validate->fails()) {
            Log::error('Login validation failed:', [
                'errors' => $validate->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => "Error: Validation Error!",
                'errors' => $validate->errors(),
            ], 422);
        }

        // Set default scope if not provided
        $scope = $request->scope ?? 'user';

        // =========================>
        // ## get user request
        // =========================>
        Log::info('Login attempt:', [
            'email' => $request->email,
            'scope' => $scope,
            'request_id' => uniqid()
        ]);

        switch ($scope) {
            case 'admin':
                $user = User::where('email', $request->email)
                    ->where('role_id', 1)
                    ->first();
                break;
            case 'corporate':
                // * PERBAIKAN: Cari user berdasarkan corporate_user dan role corporate
                $user = User::where('email', $request->email)
                    ->whereHas('corporate_user', function($query) {
                        $query->whereNotNull('corporate_id')
                              ->whereNotNull('role_id');
                    })
                    ->with(['corporate_user', 'corporate_user.role', 'corporate_user.corporate'])
                    ->first();
                break;
            default: // 'user' scope - terima user biasa (role_id: 2) dan manager tenant (role_id: 6)
                $user = User::where('email', $request->email)
                    ->whereIn('role_id', [2, 6]) // 2 = User, 6 = Manager Tenant
                    ->first();
                break;
        }

        Log::info('User search result:', [
            'email' => $request->email,
            'user_found' => $user ? true : false,
            'user_id' => $user ? $user->id : null,
            'user_email' => $user ? $user->email : null,
            'user_role_id' => $user ? $user->role_id : null,
            'has_corporate_user' => $user && $user->corporate_user ? true : false,
            'corporate_id' => $user && $user->corporate_user ? $user->corporate_user->corporate_id : null
        ]);

        if (!$user) {
            Log::warning('User not found for login:', [
                'email' => $request->email, 
                'scope' => $scope
            ]);
            return response([
                'message' => 'User not found or not registered as corporate member',
                'errors' => [
                    'email' => ['User tidak ditemukan atau tidak terdaftar sebagai member corporate untuk scope ' . $scope]
                ]
            ], 422);
        }

        // =========================>
        // ## check password
        // =========================>
        Log::info('Checking password for user:', [
            'email' => $user->email,
            'hash_prefix' => substr($user->password, 0, 20) . '...'
        ]);

        if (!Hash::check($request->password, $user->password)) {
            Log::warning('Password check failed for user:', ['email' => $user->email]);
            return response()->json([
                'message' => 'Wrong username or password in our records',
                'errors' => ['password' => ['Password salah!']],
            ], 422);
        }

        Log::info('Password check successful for user:', ['email' => $user->email]);

        // * TAMBAHAN: Untuk corporate, pastikan ada corporate_user
        if ($scope === 'corporate' && !$user->corporate_user) {
            Log::warning('User found but no corporate access:', [
                'email' => $user->email,
                'user_id' => $user->id
            ]);
            return response([
                'message' => 'User tidak memiliki akses corporate',
                'errors' => [
                    'email' => ['User tidak terdaftar sebagai member corporate']
                ]
            ], 422);
        }

        // =========================>
        // ## verification gate (WAJIB)
        // =========================>
        $isVerified = !empty($user->verified_at);

        if (!$isVerified) {
            try {
                app(\App\Http\Controllers\EmailVerificationController::class)
                    ->resendCode(new \Illuminate\Http\Request(['email' => $user->email]));
            } catch (\Throwable $th) {
                Log::warning('Auto-resend verification failed', [
                    'email' => $user->email,
                    'err'   => $th->getMessage()
                ]);
            }

            // >>> UBAH: pakai 200, dan semua info unverified taruh di "data"
            return response()->json([
                'message' => 'Success',               // <-- kebanyakan hook cek ini
                'data' => [
                    'status'             => 'unverified',
                    'reason'             => 'unverified',
                    'need_verification'  => true,     // <-- flag jelas
                    'email'              => $user->email,
                    'redirect_url'       => '/verifikasi?email=' . urlencode($user->email),
                    'http_status'        => 202,      // info saja, bukan HTTP beneran
                ],
            ], 200);
        }

        // =========================>
        // ## create token (verified saja)
        // =========================>
        $user_token = $user->createToken('sanctum')->plainTextToken;

        $roleId = (int) ($user->role_id ?? 0);

        return response([
            'message' => 'Success',
            'data' => [
                "user" => [
                    'id'      => (int) $user->id,
                    'name'    => (string) $user->name,
                    'email'   => (string) $user->email,
                    'role_id' => $roleId,
                ],
                "token"   => $user_token,
                "role_id" => $roleId,
                "scope"   => $scope
            ],
            'user_token' => $user_token
        ], 200);
    }

    /**
     * Registering user account.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        Log::info('Registration attempt:', [
            'request_data' => $request->all(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type')
        ]);

        // 1) Validasi input
        $validate = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:100|unique:users,phone',
            'password' => 'required|string|min:8|max:50|confirmed',
            'image' => 'nullable',
            'role_id' => [
                'nullable',
                'integer',
                Rule::in([1, 2, 6])
            ],
        ]);

        if ($validate->fails()) {
            Log::error('Registration validation failed:', [
                'errors' => $validate->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => "Error: Unprocessable Entity! Validation Error",
                'errors' => $validate->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 2) Buat user - SESUAI DATABASE SCHEMA
            $user = new User();
            $user->role_id = $request->role_id ?? 2;
            $user->name = $request->name;
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->password = Hash::make($request->password);

            // Upload foto jika ada - GUNAKAN picture_source SESUAI DATABASE
            if ($request->hasFile('image')) {
                try {
                    $user->picture_source = $this->upload_file($request->file('image'), 'profile');
                } catch (\Throwable $th) {
                    DB::rollBack();
                    Log::error('Image upload failed:', ['error' => $th->getMessage()]);
                    return response()->json([
                        'message' => "Error: failed to upload image",
                    ], 500);
                }
            }

            $user->save();

            // 3) Create verification code
            try {
                $verificationCode = EmailVerificationCode::createForEmail($user->email);

                // 4) Kirim email verifikasi
                Mail::to($user->email)->send(new VerificationCodeMail($verificationCode->code));
                Log::info('Email sent successfully to:', ['email' => $user->email]);
                $emailStatus = 'Verification email sent';
            } catch (\Throwable $th) {
                Log::error('Email sending failed (ignored):', ['error' => $th->getMessage()]);
                $emailStatus = 'Registered, but failed to send verification email';
            }

            DB::commit();

            $userToken = $user->createToken('registration')->plainTextToken;

            return response()->json([
                'message' => 'Registration successful, please verify your email',
                'data' => [
                    'user' => $user,
                    'token' => $userToken,
                    'email' => $user->email,
                    'verification_required' => true
                ],
                'role' => $user->role,
                'user_token' => $userToken,
                'email_status' => $emailStatus,
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Registration failed:', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);

            return response()->json([
                'message' => "Error: Registration failed",
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Re-send email verification for specific user.
     * Uses new verification system with 6-digit codes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function resendMail(Request $request)
    {
        // PERBAIKAN: Gunakan email dari request, bukan Auth::user()
        $validate = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);

        if ($validate->fails()) {
            return response()->json([
                'message' => "Validation Error",
                'errors' => $validate->errors(),
            ], 422);
        }

        // PERBAIKAN: Cari user berdasarkan email dari request
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User tidak ditemukan',
                'errors' => ['email' => ['User tidak ditemukan']]
            ], 404);
        }

        try {
            // Create new verification code using the new system
            $verificationCode = EmailVerificationCode::createForEmail($user->email);

            // Send email verification
            Mail::to($user->email)->send(new VerificationCodeMail($verificationCode->code));

            Log::info('Verification email resent successfully to:', ['email' => $user->email]);

            return response()->json([
                'message' => 'Kode verifikasi telah dikirim ulang',
                'email' => $user->email,
                'success' => true
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to resend verification email:', ['error' => $th->getMessage()]);

            return response()->json([
                'message' => "Error: Failed to send verification email",
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Verify token verification from email.
     * Supports both old (5-digit hashed) and new (6-digit plain) verification systems.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function mailVerify(Request $request)
    {
        Log::info('mailVerify called with request data:', [
            'all_data' => $request->all(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method()
        ]);

        $rules = [];

        if ($request->has('email')) {
            $rules['email'] = 'required|email';
        }

        if ($request->has('token')) {
            $rules['token'] = 'required|string';
        } elseif ($request->has('code')) {
            $rules['code'] = 'required|string';
        } else {
            Log::error('No token or code field found in request');
            return response()->json([
                'success' => false,
                'message' => "Validation failed",
                'errors' => [
                    'token' => ['Token atau code wajib diisi'],
                    'code' => ['Token atau code wajib diisi']
                ]
            ], 422);
        }

        $validate = Validator::make($request->all(), $rules);

        if ($validate->fails()) {
            Log::error('Mail verification validation failed:', [
                'errors' => $validate->errors()->toArray(),
                'request_data' => $request->all(),
                'validation_rules' => $rules
            ]);
            return response()->json([
                'success' => false,
                'message' => "Validation failed",
                'errors' => $validate->errors(),
            ], 422);
        }

        $email = $request->email;

        if (!$email) {
            Log::error('No email provided in request');
            return response()->json([
                'success' => false,
                'message' => "Email is required",
                'errors' => [
                    'email' => ['Email wajib diisi']
                ]
            ], 422);
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            Log::warning('Mail verification attempted for non-existent user:', ['email' => $email]);
            return response()->json([
                'success' => false,
                'message' => "User not found",
                'errors' => [
                    'email' => ['User dengan email ini tidak ditemukan!']
                ]
            ], 404);
        }

        $token = $request->token ?? $request->code;

        DB::beginTransaction();

        try {
            // Try new system first (6-digit plain code)
            if (strlen($token) === 6 && ctype_digit($token)) {
                $isValid = EmailVerificationCode::verifyCode($user->email, $token);

                if ($isValid) {
                    $user->verified_at = now();
                    $user->save();

                    $userToken = $user->createToken('email-verified')->plainTextToken;

                    DB::commit();
                    Log::info('Email verification successful for user:', ['email' => $user->email]);

                    // PERBAIKAN: Build redirect URL berdasarkan QR data
                    $redirectUrl = $this->buildRedirectUrl($request->input('qr_data'));

                    return response()->json([
                        'success' => true,
                        'message' => 'Email verified successfully',
                        'data' => [
                            'user' => $user,
                            'token' => $userToken,
                            'qr_data' => $request->input('qr_data'),
                            'redirect_url' => $redirectUrl, // TAMBAHAN
                        ]
                    ], 200);
                }
            }

            // Fallback to old system (5-digit hashed)
            $userTokenVerify = UserTokenVerify::where('user_id', $user->id)
                ->whereNull('used_at')
                ->latest()
                ->first();

            if ($userTokenVerify && Hash::check($token, $userTokenVerify->token)) {
                $userTokenVerify->used_at = now();
                $userTokenVerify->save();

                $user->verified_at = now();
                $user->save();

                $userToken = $user->createToken('email-verified')->plainTextToken;

                DB::commit();
                Log::info('Email verification successful (old system) for user:', ['email' => $user->email]);

                // PERBAIKAN: Build redirect URL berdasarkan QR data
                $redirectUrl = $this->buildRedirectUrl($request->input('qr_data'));

                return response()->json([
                    'success' => true,
                    'message' => 'Email verified successfully',
                    'data' => [
                        'user' => $user,
                        'token' => $userToken,
                        'qr_data' => $request->input('qr_data'),
                        'redirect_url' => $redirectUrl, // TAMBAHAN
                    ]
                ], 200);
            }

            DB::rollBack();
            Log::warning('Invalid verification token provided for user:', ['email' => $user->email]);

            return response()->json([
                'success' => false,
                'message' => "Token Invalid!",
                'errors' => [
                    'token' => ['Token tidak valid atau sudah kadaluarsa!']
                ]
            ], 422);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Mail verification failed:', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => "Error: Verification failed",
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * TAMBAHAN: Build redirect URL berdasarkan QR data
     */
    private function buildRedirectUrl($qrData)
    {
        if (!$qrData) {
            return '/app'; // Default redirect
        }

        try {
            $decoded = json_decode($qrData, true);

            if ($decoded && isset($decoded['type'])) {
                switch ($decoded['type']) {
                    case 'voucher':
                        if (isset($decoded['voucherId'])) {
                            return "/app/voucher/{$decoded['voucherId']}";
                        }
                        break;
                    case 'promo':
                        if (isset($decoded['promoId'])) {
                            return "/app/promo/{$decoded['promoId']}";
                        }
                        break;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to parse QR data for redirect:', ['qr_data' => $qrData, 'error' => $e->getMessage()]);
        }

        return '/app'; // Fallback
    }

    public function editProfile(Request $request)
    {
        // ? Initial
        DB::beginTransaction();

        $model = User::findOrFail(Auth::id());
        $oldPicture = $model->picture_source;

        // =========================>
        // ## validate request
        // =========================>
        $validation = $this->validation($request->all(), [
            "name" => 'nullable|string|max:255',
            "phone" => 'nullable|string|max:100',
            'image' => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        // * Check if has upload file
        if ($request->hasFile('image')) {
            try {
                $model->picture_source = $this->upload_file($request->file('image'), 'profile');
            } catch (\Throwable $th) {
                DB::rollback();
                Log::error('Image upload failed:', ['error' => $th->getMessage()]);
                return response([
                    'message' => "Error: failed to upload image",
                    'error' => config('app.debug') ? $th->getMessage() : null
                ], 500);
            }

            if ($oldPicture) {
                $this->delete_file($oldPicture ?? '');
            }
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Failed to save user:', ['error' => $th->getMessage()]);
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ]);
    }

    /**
     * Update the user password in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function changePassword(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'old_password' => 'required',
            'password' => 'required|min:3'
        ]);

        if ($validate->fails()) {
            Log::error('Password change validation failed:', [
                'errors' => $validate->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => "Error: Unprocessable Entity! Validation Error",
                'errors' => $validate->errors(),
            ], 422);
        }

        $user = User::findOrFail(Auth::id());

        $cekPassword = Hash::check($request->old_password, $user->password);

        if ($cekPassword == true) {
            $user->update([
                // 'password' => bcrypt($request->password)
                'password' => Hash::make($request->password)
            ]);
        } else {
            Log::error('Invalid old password:', ['old_password' => $request->old_password]);
            return response()->json([
                'message' => "Error: validation error, wrong old password",
                'errors' => [
                    'old_password' => ['Wrong old password']
                ]
            ], 422);
        }


        return response()->json([
            'message' => 'Success',
            'data' => $user
        ]);
    }

    /**
     * Forgot Password Send OTP
     */
    public function forgotPasswordSendEmail(Request $request)
    {
        // request validation
        $validate = Validator::make($request->all(), [
            'email' => 'required|string|email'
        ]);

        if ($validate->fails()) {
            Log::error('Forgot password validation failed:', [
                'errors' => $validate->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => "Error: Unprocessable Entity! Validation Error",
                'errors' => $validate->errors(),
            ], 422);
        }

        DB::beginTransaction();

        // check
        $user = User::where('email', $request->email)
            ->whereNotNull('verified_at')
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email tidak terdaftar pada sistem',
                'errors' => [
                    'email' => ['Email tidak terdaftar pada sistem Huehuy']
                ]
            ], 422);
        }

        // insert token
        $token = random_int(10000, 99999);

        // check and create or update reset password
        $resetPassword = PasswordReset::where('email', $user->email)->whereNull('used_at')->first();

        if (!$resetPassword) {

            $passwordReset = new PasswordReset();
            $passwordReset->email = $user->email;
            $passwordReset->token = $token;
            try {
                $passwordReset->save();
            } catch (\Throwable $th) {
                DB::rollback();
                Log::error('Failed to create new reset password record:', ['error' => $th->getMessage()]);
                return response([
                    'message' => "Error: failed to create new reset password redord",
                ], 500);
            }
        } else {

            try {
                $resetPassword->update([
                    'token' => $token
                ]);
            } catch (\Throwable $th) {
                DB::rollback();
                Log::error('Failed to update reset password record:', ['error' => $th->getMessage()]);
                return response([
                    'message' => "Error: failed to update reset password redord",
                ], 500);
            }
        }

        // send mail reset password
        try {
            Mail::to($user->email)->send(new ResetPasswordMail($token));
        } catch (\Throwable $th) {
            return response([
                'message' => "Error: failed to send email",
            ], 500);
        }

        DB::commit();

        return response()->json([
            'message' => 'Kode verifikasi telah dikirim',
            'email' => $user->email
        ]);
    }

    /**
     * Verify forgot password token
     */
    public function forgotPasswordTokenVerify(Request $request)
    {
        // make validator
        $validator = Validator::make($request->all(), [
            "token" => "required",
        ]);

        // check validate
        if ($validator->fails()) {
            Log::error('Forgot password token validation failed:', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => "Error: Unprocessable Entity! Validation Error",
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        // check token
        $resetPassword = PasswordReset::where('token', $request->token)->first();

        if (!$resetPassword) {
            return response()->json(['message' => 'Token salah'], 422);
        }

        if (Carbon::parse($resetPassword->created_at)->addHours(2) <= Carbon::now()) {
            return response()->json([
                'message' => 'Token telah kadaluarsa'
            ], 422);
        }

        // update used at
        $resetPassword->used_at = Carbon::now();
        try {
            $resetPassword->save();
        } catch (\Throwable $th) {
            DB::rollback();
            Log::error('Failed to update reset password record:', ['error' => $th->getMessage()]);
            return response([
                'message' => "Error: failed to update reset password redord",
            ], 500);
        }

        DB::commit();

        return response()->json([
            'message' => 'Token telah terverifikasi',
            'email' => $resetPassword->email
        ], 200);
    }

    /**
     * New Password
     */
    public function forgotPasswordNewPassword(Request $request)
    {
        // make validator
        $validator = Validator::make($request->all(), [
            "email" => "required|string|email|exists:users,email",
            "token" => "required",
            "password" => "required|string|min:3"
        ]);

        if ($validator->fails()) {
            Log::error('New password validation failed:', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => "Error: Unprocessable Entity! Validation Error",
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        // check reset password
        $resetPassword = PasswordReset::where('email', $request->email)
            ->where('token', $request->token)
            ->whereNotNull('used_at')
            ->latest()
            ->first();

        if (!$resetPassword) {
            return response()->json([
                'message' => 'Token tidak ditemukan'
            ], 422);
        }

        // check user
        $user = User::where('email', $request->email)
            ->whereNotNull('verified_at')
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'User tidak ditemukan'
            ], 422);
        }

        // update user password
        try {
            $user->update([
                'password' => Hash::make($request->password)
            ]);
        } catch (\Throwable $th) {
            DB::rollback();
            Log::error('Failed to update user password:', ['error' => $th->getMessage()]);
            return response([
                'message' => "Error: failed to update reset password redord",
            ], 500);
        }

        DB::commit();

        return response()->json([
            'message' => 'Password berhasil diubah'
        ]);
    }

    public function account()
    {
        try {
            // PERBAIKAN: Load user dengan relasi corporate_user dan role
            $user = User::with(['corporate_user.corporate', 'corporate_user.role', 'role'])
                ->where('id', Auth::id())
                ->first();

            if (!$user) {
                return response()->json([
                    'message' => 'Authentication required',
                    'error' => 'No authenticated user found'
                ], 401);
            }

            Log::info('Account endpoint called for user:', [
                'user_id' => $user->id, 
                'email' => $user->email,
                'role_id' => $user->role_id,
                'has_corporate_user' => $user->corporate_user ? 'yes' : 'no',
                'corporate_id' => $user->corporate_user ? $user->corporate_user->corporate_id : 'none'
            ]);

            // PERBAIKAN: Include corporate_user data dengan proper structure
            $corporateUserData = null;
            if ($user->corporate_user) {
                $corporateUserData = [
                    'id' => $user->corporate_user->id,
                    'user_id' => $user->corporate_user->user_id,
                    'corporate_id' => $user->corporate_user->corporate_id,
                    'role_id' => $user->corporate_user->role_id,
                    'created_at' => $user->corporate_user->created_at,
                    'corporate' => $user->corporate_user->corporate ? [
                        'id' => $user->corporate_user->corporate->id,
                        'name' => $user->corporate_user->corporate->name,
                        'description' => $user->corporate_user->corporate->description,
                        'address' => $user->corporate_user->corporate->address,
                        'phone' => $user->corporate_user->corporate->phone,
                    ] : null,
                    'role' => $user->corporate_user->role ? [
                        'id' => $user->corporate_user->role->id,
                        'name' => $user->corporate_user->role->name,
                    ] : null,
                ];
            }

            $userArray = [
                'id' => (int) $user->id,
                'name' => (string) ($user->name ?? 'User'),
                'email' => (string) ($user->email ?? ''),
                'phone' => $user->phone ?? null,
                'picture_source' => $user->picture_source ?? null,
                'verified_at' => $user->verified_at ?? null,
                'role_id' => (int) ($user->role_id ?? 2),
                'code' => 'HUEHUY-' . str_pad($user->id, 6, '0', STR_PAD_LEFT), // Generate code untuk QR
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                ] : null,
                'cubes' => [],
                'corporate_user' => $corporateUserData // PERBAIKAN: Include corporate_user data
            ];

            $isVerified = !empty($user->verified_at);

            Log::info('Account response prepared:', [
                'user_id' => $user->id,
                'has_corporate_user_in_response' => $corporateUserData ? 'yes' : 'no',
                'corporate_id_in_response' => $corporateUserData ? $corporateUserData['corporate_id'] : 'none'
            ]);

            return response()->json([
                'message' => 'Success',
                'data' => [
                    'profile' => $userArray,
                    'verification_status' => [
                        'is_verified' => $isVerified,
                        'verified_at' => $user->verified_at,
                        'requires_verification' => !$isVerified
                    ]
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Account endpoint critical error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id() ?? 'not_authenticated',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'message' => 'Success',
                'data' => [
                    'profile' => [
                        'id' => Auth::id() ?? 0,
                        'name' => 'User',
                        'email' => '',
                        'code' => 'HUEHUY-' . str_pad(Auth::id() ?? 0, 6, '0', STR_PAD_LEFT),
                        'role' => null,
                        'cubes' => [],
                        'corporate_user' => null
                    ],
                    'verification_status' => [
                        'is_verified' => false,
                        'verified_at' => null,
                        'requires_verification' => true
                    ]
                ]
            ], 200);
        }
    }

    public function account_unverified(Request $request)
    {
        try {
            // PERBAIKAN: Jika ada email di query parameter
            $email = $request->query('email');

            if ($email) {
                $user = User::where('email', $email)->first();

                if ($user) {
                    return response()->json([
                        'message' => 'Success',
                        'data' => [
                            'profile' => [
                                'id' => $user->id,
                                'name' => $user->name,
                                'email' => $user->email,
                                'phone' => $user->phone ?? null,
                                'picture_source' => $user->picture_source ?? null, // SESUAI DATABASE
                                'verified_at' => $user->verified_at,
                                'status' => $user->verified_at ? 'verified' : 'pending_verification'
                            ]
                        ],
                        'verification_required' => !$user->verified_at
                    ]);
                }
            }

            // FALLBACK: Jika ada auth user - load dengan relasi
            $user = User::with([
                'role', 
                'corporate_user', 
                'corporate_user.corporate', 
                'corporate_user.role'
            ])->find(Auth::id());

            if ($user) {
                return response()->json([
                    'message' => 'Success',
                    'data' => [
                        'profile' => $user // Gunakan user object lengkap dengan relasi
                    ],
                    'verification_required' => !$user->verified_at
                ]);
            }

            // DEFAULT: Return empty response
            return response()->json([
                'message' => 'Please provide email parameter or authenticate',
                'data' => ['profile' => null],
                'verification_required' => true
            ], 200);
        } catch (\Exception $e) {
            Log::error('account_unverified error:', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Internal server error',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
                'data' => ['profile' => null]
            ], 200); // Return 200 bukan 500
        }
    }

    public function login_firebase(Request $request)
    {
        // Validate JSON body explicitly (works for application/json)
        $data = $request->validate([
            'idToken' => ['required', 'string'],
        ]);

        // --- Debug: log token claims (iss/aud) to validate project match ---
        try {
            [$h, $p, $s] = array_pad(explode('.', $data['idToken']), 3, null);
            $payload = $p ? json_decode(base64_decode(strtr($p, '-_', '+/')), true) : null;
            Log::debug('🔥 Firebase Token Claims', [
                'iss' => $payload['iss'] ?? null,
                'aud' => $payload['aud'] ?? null,
                'sub' => $payload['sub'] ?? null,
                'email' => $payload['email'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::debug('Failed to parse Firebase token payload for debugging', ['error' => $e->getMessage()]);
        }

        // Use configured Firebase Auth (no hardcoded credentials)
        $auth = app('firebase.auth');

        try {
            // Set second param to true if you want to check revoked tokens
            $verified = $auth->verifyIdToken($data['idToken'], false);
        } catch (FailedToVerifyToken $e) {
            return response()->json([
                'message' => 'invalid idToken'
            ], 422);
        }

        $claims = $verified->claims()->all();
        $uid    = $claims['sub'] ?? null;
        $email  = $claims['email'] ?? null;
        $name   = $claims['name'] ?? 'User';

        if (!$uid) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        // Adapt to existing schema: authenticate by email; mark verified
        $user = null;
        if ($email) {
            $user = User::where('email', $email)->first();
        }

        if (!$user) {
            $user = new User();
            $user->email = $email;
            $user->name = $name;
            $user->verified_at = now();
            $user->role_id = 2; // default role

            try {
                $user->save();
            } catch (\Throwable $th) {
                return response()->json([
                    'message' => 'Failed to create user',
                    'error' => config('app.debug') ? $th->getMessage() : 'Server error'
                ], 500);
            }
        } else {
            // Ensure verified flag is set for returning users logging in via Firebase
            if (empty($user->verified_at)) {
                $user->verified_at = now();
                $user->save();
            }
        }

        // Issue Sanctum token for session management
        $user_token = $user->createToken('sanctum')->plainTextToken;

        // Keep response shape close to existing behavior for compatibility
        return response()->json([
            'message' => 'Success',
            'token'   => $user_token,
            'data'    => [
                'uid'   => $uid,
                'email' => $user->email,
                'name'  => $user->name,
            ]
        ], 200);
    }

    /**
     * Firebase login using ID Token from client SDKs (web/mobile).
     * - Verifies the token using Firebase Admin SDK
     * - Creates or finds a local user
     * - Returns a Sanctum token
     *
     * Request JSON: { idToken: string }
     * Response JSON: { success: bool, token: string, user: object }
     */
    public function loginFirebase(Request $request)
    {
        $request->validate(['idToken' => 'required|string']);

        // Check credential file existence to avoid opaque 500s
        $credEnv = env('FIREBASE_CREDENTIALS');
        $credPath = $credEnv ? base_path($credEnv) : null;
        $credExists = $credPath ? file_exists($credPath) : false;
        $gacEnv = env('GOOGLE_APPLICATION_CREDENTIALS');
        $gacExists = $gacEnv ? file_exists($gacEnv) : false;

        // In development, allow mock authentication if Firebase credentials are missing
        if (!$credExists && !$gacExists && app()->environment(['local', 'development'])) {
            Log::warning('Firebase credentials missing in local/dev environment - using mock authentication', [
                'FIREBASE_CREDENTIALS' => $credEnv,
                'resolved_path' => $credPath,
                'GOOGLE_APPLICATION_CREDENTIALS' => $gacEnv,
            ]);

            // Mock authentication for development - extract email from idToken if it's a development token
            // In a real scenario, you might want to decode the JWT manually or use a different approach
            return $this->handleDevelopmentFirebaseAuth($request);
        }

        try {
            $auth = app('firebase.auth');
        } catch (\Throwable $e) {
            Log::error('Firebase Auth init failed', [
                'error' => $e->getMessage(),
                'cred_env' => $credEnv,
                'cred_path' => $credPath,
                'cred_exists' => $credExists,
                'gac_env' => $gacEnv,
                'gac_exists' => $gacExists,
            ]);
            
            // In development, provide helpful error message
            if (app()->environment(['local', 'development'])) {
                return response()->json([
                    'message' => 'Firebase configuration error',
                    'code' => 'FIREBASE_CONFIG',
                    'hint' => 'Download your Firebase service account JSON file and place it at ./firebase/huehuy-63c16.json, then uncomment FIREBASE_CREDENTIALS in your .env file',
                    'expected_path' => './firebase/huehuy-63c16.json',
                ], 500);
            }
            
            return response()->json([
                'message' => 'Authentication service unavailable',
                'code' => 'AUTH_SERVICE_ERROR',
            ], 500);
        }

        try {
            $verified = $auth->verifyIdToken($request->idToken, false);
        } catch (FailedToVerifyToken $e) {
            return response()->json(['message' => 'Invalid Firebase token'], 422);
        } catch (\Throwable $e) {
            Log::error('Firebase token verification failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Firebase verification failed',
                'code' => 'FIREBASE_VERIFY_FAIL',
            ], 500);
        }

        $claims = $verified->claims()->all();
        $uid = $claims['sub'] ?? null;
        $email = $claims['email'] ?? 'noemail@unknown.com';
        $name = $claims['name'] ?? 'User';
        $picture = $claims['picture'] ?? null;

        if (!$uid) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        return $this->createOrFindFirebaseUser($uid, $email, $name, $picture);
    }

    /**
     * Handle Firebase authentication in development environment when credentials are missing
     */
    private function handleDevelopmentFirebaseAuth(Request $request)
    {
        // For development only - this is NOT secure for production
        Log::info('Using development Firebase auth mock');

        try {
            // Decode the JWT token manually (without verification for development)
            $idToken = $request->idToken;
            $tokenParts = explode('.', $idToken);
            
            if (count($tokenParts) !== 3) {
                throw new \Exception('Invalid token format');
            }
            
            // Decode the payload (second part of JWT)
            $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1]));
            $claims = json_decode($payload, true);
            
            if (!$claims) {
                throw new \Exception('Invalid token payload');
            }
            
            Log::info('Decoded Firebase token claims:', $claims);
            
            // Extract user information from the token
            $uid = $claims['sub'] ?? $claims['user_id'] ?? ('dev_' . time());
            $email = $claims['email'] ?? 'unknown@dev.local';
            $name = $claims['name'] ?? $claims['display_name'] ?? 'Development User';
            $picture = $claims['picture'] ?? null;
            
            Log::info('Extracted user info from token:', [
                'uid' => $uid,
                'email' => $email,
                'name' => $name,
                'picture' => $picture
            ]);
            
            return $this->createOrFindFirebaseUser($uid, $email, $name, $picture);
            
        } catch (\Exception $e) {
            Log::warning('Failed to decode Firebase token in development mode:', [
                'error' => $e->getMessage(),
                'token_preview' => substr($request->idToken, 0, 50) . '...'
            ]);
            
            // Fallback to mock user if token decoding fails
            $devEmail = 'dev@huehuy.com';
            $devName = 'Development User';
            $devUid = 'dev_' . time();
            
            return $this->createOrFindFirebaseUser($devUid, $devEmail, $devName);
        }
    }

    /**
     * Create or find user from Firebase authentication
     */
    private function createOrFindFirebaseUser($uid, $email, $name, $picture = null)
    {
        // Prefer firebase_uid if the column exists. Otherwise, fall back to email.
        if (Schema::hasColumn('users', 'firebase_uid')) {
            $user = User::firstOrCreate(
                ['firebase_uid' => $uid],
                [
                    'email' => $email,
                    'name' => $name,
                    'verified_at' => now(),
                    'role_id' => 2,
                    'picture_source' => $picture,
                ]
            );
        } else {
            // Fallback to email-only schema (no firebase_uid column)
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'verified_at' => now(),
                    'role_id' => 2,
                    'picture_source' => $picture,
                ]
            );
        }

        // Update existing user's information if needed
        if ($user->wasRecentlyCreated === false) {
            $updated = false;
            
            if ($user->name !== $name) {
                $user->name = $name;
                $updated = true;
            }
            
            if ($picture && $user->picture_source !== $picture) {
                $user->picture_source = $picture;
                $updated = true;
            }
            
            if ($updated) {
                $user->save();
            }
        }

        // Ensure verified flag is set
        if (empty($user->verified_at)) {
            $user->verified_at = now();
            $user->save();
        }

        $token = $user->createToken('firebase_login')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Simple email verification for QR entry flow
     */
    public function mailVerifySimple(Request $request)
    {
        Log::info('mailVerifySimple called:', $request->all());

        try {
            $email = $request->input('email');
            $token = $request->input('token');

            if (!$email || !$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email dan token wajib diisi',
                    'errors' => [
                        'email' => !$email ? ['Email wajib diisi'] : [],
                        'token' => !$token ? ['Token wajib diisi'] : []
                    ]
                ], 422);
            }

            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan',
                    'errors' => [
                        'email' => ['User tidak ditemukan']
                    ]
                ], 404);
            }

            // Mark as verified (untuk testing, skip actual verification)
            $user->verified_at = now();
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
            }
            $user->save();

            $userToken = $user->createToken('email-verified')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'verified_at' => $user->verified_at
                    ],
                    'token' => $userToken,
                    'qr_data' => $request->input('qr_data')
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('mailVerifySimple error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Verification failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function profile()
    {
        $user = User::with([
            'role', 
            'corporate_user', 
            'corporate_user.corporate', 
            'corporate_user.role'
        ])->find(Auth::id());

        if (!$user) {
            return response([
                'message' => 'User not found',
                'data' => null
            ], 404);
        }

        // Debug log untuk melihat apakah corporate_user ter-load
        Log::info('Profile loaded for user:', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'has_corporate_user' => $user->corporate_user ? true : false,
            'corporate_user_data' => $user->corporate_user ? $user->corporate_user->toArray() : null,
            'corporate_id' => $user->corporate_user ? $user->corporate_user->corporate_id : null
        ]);
        
        return response([
            'message' => 'success',
            'data' => $user
        ]);
    }

    /**
     * Corporate-specific account endpoint
     */
    public function corporateAccount()
    {
        try {
            $user = User::with([
                'role', 
                'corporate_user', 
                'corporate_user.corporate', 
                'corporate_user.role'
            ])->find(Auth::id());

            if (!$user) {
                return response()->json([
                    'message' => 'Authentication required',
                    'error' => 'No authenticated user found'
                ], 401);
            }

            Log::info('Corporate account endpoint called:', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'has_corporate_user' => $user->corporate_user !== null,
                'corporate_id' => $user->corporate_user ? $user->corporate_user->corporate_id : null
            ]);

            $isVerified = !empty($user->verified_at);

            return response()->json([
                'message' => 'Success',
                'data' => [
                    'profile' => $user,
                    'verification_status' => [
                        'is_verified' => $isVerified,
                        'verified_at' => $user->verified_at,
                        'requires_verification' => !$isVerified
                    ]
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Corporate account endpoint error:', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Success',
                'data' => [
                    'profile' => [
                        'id' => Auth::id() ?? 0,
                        'name' => 'User',
                        'email' => '',
                        'role' => 'user',
                        'cubes' => [],
                        'corporate_user' => null
                    ],
                    'verification_status' => [
                        'is_verified' => false,
                        'verified_at' => null,
                        'requires_verification' => true
                    ]
                ]
            ], 200);
        }
    }
}
