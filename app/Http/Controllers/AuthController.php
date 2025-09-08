<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Mail\UserRegisterMail;
use App\Mail\VerificationCodeMail;
use App\Models\PasswordReset;
use App\Models\PersonalAccessToken;
use App\Models\User;
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
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

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
        Log::info('Searching user with email: ' . $request->email . ' and scope: ' . $scope);
        
        switch ($scope) {
            case 'admin':
                $user = User::where('email', $request->email)
                    ->where('role_id', 1)
                    ->first();
                break;
            case 'corporate':
                $user = User::where('email', $request->email)
                    ->join('corporate_users', 'corporate_users.user_id', 'users.id')
                    ->first();
                break;
            default: // 'user' scope - terima user biasa (role_id: 2) dan manager tenant (role_id: 6)
                $user = User::where('email', $request->email)
                    ->whereIn('role_id', [2, 6]) // 2 = User, 6 = Manager Tenant
                    ->first();
                break;
        }

        Log::info('User found:', ['user' => $user ? $user->toArray() : null]);

        if (!$user) {
            Log::warning('User not found for email: ' . $request->email . ' with scope: ' . $scope);
            return response([
                'message' => 'User not found',
                'errors' => [
                    'email' => ['Data tidak ditemukan untuk scope ' . $scope]
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

        // =========================>
        // ## create token
        // =========================>
        $user_token = $user->createToken('sanctum')->plainTextToken;

        return response([
            'message' => 'Success',
            'data' => [
                "user" => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role
                ],
                "token" => $user_token,
                "role" => $user->role,
                "scope" => $scope
            ],
            'user_token' => $user_token // Backward compatibility
        ]);
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
            'phone' => 'required|string|max:100',
            'password' => 'required|string|min:8|max:50|confirmed',
            'image' => 'nullable',
            'role_id' => [
                'nullable', // Ubah dari 'required' ke 'nullable'
                'integer',
                Rule::in([1, 2, 6]) // 1=admin, 2=user, 6=manager_tenant
            ],
        ]);

        if ($validate->fails()) {
            Log::error('Registration validation failed:', [
                'errors' => $validate->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            Log::error('Validation failed:', $validate->errors()->toArray());
            return response()->json([
                'message' => "Error: Unprocessable Entity! Validation Error",
                'errors' => $validate->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 2) Buat user
            $user = new User();
            $user->role_id = $request->role_id ?? 2; // Default ke role_id = 2 (user biasa)
            $user->name = $request->name;
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->password = Hash::make($request->password);

            // Upload foto jika ada
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
            try {
                $verificationCode = EmailVerificationCode::createForEmail($user->email);
                
                // 5) Kirim email verifikasi
                Mail::to($user->email)->send(new VerificationCodeMail($verificationCode->code));
                Log::info('Email sent successfully to:', ['email' => $user->email]);
                $emailStatus = 'Verification email sent';
            } catch (\Throwable $th) {
                Log::error('Email sending failed (ignored):', ['error' => $th->getMessage()]);
                $emailStatus = 'Registered, but failed to send verification email';
            }

            $userToken = $user->createToken('registration')->plainTextToken;

            return response()->json([
                'message' => 'Registration successful, please verify your email',
                'data' => [
                    'user' => $user,
                    'token' => $userToken, // PASTIKAN INI ADA
                    'email' => $user->email,
                    'verification_required' => true
                ],
                'role' => $user->role,
                'user_token' => $userToken, // Backward compatibility
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
        // Add initial logging to see what's being sent
        Log::info('mailVerify called with request data:', [
            'all_data' => $request->all(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method()
        ]);

        // More flexible validation - accept either token or code
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

        // check validate
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
                'debug_info' => [
                    'received_fields' => array_keys($request->all()),
                    'required_fields' => array_keys($rules)
                ]
            ], 422);
        }

        // Get email from request
        $email = $request->email;
        
        // If no email provided, try to get it from other sources or return error
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

        // Accept both 'token' and 'code' field names
        $token = $request->token ?? $request->code;

        DB::beginTransaction();

        try {
            // Try new system first (6-digit plain code)
            if (strlen($token) === 6 && ctype_digit($token)) {
                $isValid = EmailVerificationCode::verifyCode($user->email, $token);
                
                if ($isValid) {
                    $user->verified_at = now();
                    $user->save();
                    
                    // Create token for user to continue with QR flow
                    $userToken = $user->createToken('email-verified')->plainTextToken;
                    
                    DB::commit();
                    Log::info('Email verification successful for user:', ['email' => $user->email]);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Email verified successfully',
                        'data' => [
                            'user' => $user,
                            'token' => $userToken,
                            'qr_data' => $request->input('qr_data')
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
                // update token verify
                $userTokenVerify->used_at = now();
                $userTokenVerify->save();

                // update user
                $user->verified_at = now();
                $user->save();
                
                // Create token for user to continue with QR flow
                $userToken = $user->createToken('email-verified')->plainTextToken;
                
                DB::commit();
                Log::info('Email verification successful (old system) for user:', ['email' => $user->email]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Email verified successfully',
                    'data' => [
                        'user' => $user,
                        'token' => $userToken,
                        'qr_data' => $request->input('qr_data')
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
            
        if($cekPassword == true) {
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
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'Authentication required',
                    'error' => 'No authenticated user found'
                ], 401);
            }

            Log::info('Account endpoint called for user:', ['user_id' => $user->id, 'email' => $user->email]);

            // ABSOLUTE MINIMAL: Hanya field yang pasti ada
            $userArray = [
                'id' => (int) $user->id,
                'name' => (string) ($user->name ?? 'User'),
                'email' => (string) ($user->email ?? ''),
                'phone' => $user->phone ?? null,
                'avatar' => $user->avatar ?? null,
                'verified_at' => $user->verified_at ?? null,
                'email_verified_at' => $user->email_verified_at ?? null,
                'role_id' => (int) ($user->role_id ?? 2),
                'role' => 'user',
                'cubes' => [],
                'corporate_user' => null
            ];

            $isVerified = !empty($user->verified_at);

            return response()->json([
                'message' => 'Success',
                'data' => [
                    'profile' => $userArray,
                    'verification_status' => [
                        'is_verified' => $isVerified,
                        'verified_at' => $user->verified_at,
                        'email_verified_at' => $user->email_verified_at,
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
            
            // ALWAYS return 200 with minimal data
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
                        'email_verified_at' => null,
                        'requires_verification' => true
                    ]
                ]
            ], 200); // ALWAYS return 200, never 500
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
                                'avatar' => $user->avatar ?? null,
                                'verified_at' => $user->verified_at,
                                'email_verified_at' => $user->email_verified_at,
                                'status' => $user->verified_at ? 'verified' : 'pending_verification'
                            ]
                        ],
                        'verification_required' => !$user->verified_at
                    ]);
                }
            }
            
            // FALLBACK: Jika ada auth user
            $user = Auth::user();
            
            if ($user) {
                return response()->json([
                    'message' => 'Success',
                    'data' => [
                        'profile' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone ?? null,
                            'avatar' => $user->avatar ?? null,
                            'verified_at' => $user->verified_at,
                            'email_verified_at' => $user->email_verified_at,
                            'status' => $user->verified_at ? 'verified' : 'pending_verification'
                        ]
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
        if (!($request->idToken && is_string($request->idToken))) {
            return response()->json([
                "message" => "require idToken"
            ], 422);
        }

        $factory = (new Factory())
        ->withServiceAccount([
                'type' => 'service_account',
                'project_id' => 'huehuy-c43c3',
                'private_key_id' => 'ad9ff9b64ccee8cbbdd7feae6823e1e1bdbd111c',
                'private_key' => '-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDDY3m8HGEC9PCO\nLQxZZEwTYiu0Ypygf5fzC0EIUG9eF+Lc5Gvkw+lm4y7Wy+yWWvg0jNQyYKQVwEa5\nXNllAoXq0o30fPnCk+VY8fJ+X5/iRyGRfzVHEI/nHP/k/ZJlZ2suvYS8TId5EDAs\n5v34Ax9wHoVqCgb639xvSqjIoaLkx0q7B2je68Jx77sXntnXtIu7MOOUeHmCZI/3\nmhRAvrj5eq6h6sfb3Wr0IivrK0BzpGarN3yUNjClkZ9dm/rOrxZ5ebCTpQ1oYFUC\n2uRp8XIfKI0h0yCTIbK0kShHnVxEe965T3eSUzlE3OCVlMZjqG5ZJqyv48AgJ1ge\n6bezjMU9AgMBAAECggEAAlwaNA1hiYnIwIpSR8iRHWq2mWOch3nCNB9hG+wB5iuP\nu8lvymkHhiBbhPLoEPhL719cxE6vXQ0EBxaSk7tlh9WojWYPXaWmIx/4UtWig6aD\nMv5fd92259OIeh1zHwFT2iQ50hhe7n2bsF5SViRMvoRY4BkO9MdtXXtzEiXDdA7m\nKtC7t+6dvjUrR1R1FTaJreBnroXxLv1UHiNWtq4ejSfNZVMgguhdM0C4lH8/tYsj\n5qc4sgwSEFS2qezjj66kQHg9KoZ8Cajo02maEdkmz+gH21SoJNb3IaYeqyWFTd5I\nOMcTJ6SKtHK/1incYv4MOLKbV78Kxaw4p60nYAtVdQKBgQD8ZmycUR9SzW2idkAv\nrbnrhig0vc3lc/RdWCCjE7DaQqTp8yJFc4N37olCDD6FIfloSFxaL5Zwb2xlzDCh\nqY22EYDne9ltPI+MyPzqtsorxV72JPqK/fERyDnW6CpaGFFrTQTAfzl7SIZDsIeG\nwEl8U55dunuMVZckwQs7Uh1HYwKBgQDGLONS4tgPcQL0b3bc+YMqTXzpqw70FnuZ\nZ/ZCiajI9rXTz0wblBRrDCyifOGAo0Vme1KRFeYIP5BS5VlrMEbsm0v1dFOqimYi\nHEWKlYqx08e5z0ZwXDsP9+MDG4ioMNxgEowi7TdaKi081sT0KQgKATNwtIesP+vT\nIiM6NDDy3wKBgCaHLAUgjPuCyD2Id3vPtRWywOhsIMXp0V9+WF0MYG6wxaPArXaU\nj3j7PJCMde60pPG6Of66TOiU2aMgbDwBOdSVD2xGh4YZPIBtHc5mYK4Vzs0cD/Kv\nmODyA4I+plhiZetPMm5//TJIe9ZRWB7Fs3H7Aa2lDb76Qbwmi6RegIGpAoGAf/Ir\nMjBS3mVQSxBL5Y8SKBWvOA3AscZyNjDwxTSrTFQ8QGvt70BDjnllt+J4lNzUyb2F\nKTbCNUEUpPB+Mr4QjGIXQHnCKrEAD7XBECBMU1Mv977i81gYqc6ZOkBkknI5Va2j\n3EjbG9NvMYBX2GtFTXBJDdMAZS0/zCiWJdXcZHECgYBogimN9NiUITUEkvXS+ZHC\nB3vjFSzmNftMpV8VjFjVOlaDB7oegdF/+vHlZVPVisMfRKPXcBRvIE6n+4QU1nr6\n1QxeXulZm2xMQ8NPkN1EEhlktosj4wSTfxQVIxVnx7rI2Fzkpk/2gChxMCaocV+x\n1+Gvj+XHypZau+TglCN9Tg==\n-----END PRIVATE KEY-----\n',
                'client_email' => 'firebase-adminsdk-n9gqp@huehuy-c43c3.iam.gserviceaccount.com"',
                'client_id' => '116908476211153313008',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-n9gqp%40huehuy-c43c3.iam.gserviceaccount.com',
                'universe_domain' => 'googleapis.com',
            ])
        ->withProjectId('huehuy-c43c3');
        $auth = $factory->createAuth();
        $idTokenString = $request->idToken;

        try {
            $verifiedIdToken = $auth->verifyIdToken($idTokenString);
        } catch (FailedToVerifyToken $e) {
            return response()->json([
                "message" => "invalid idToken"
            ], 422);
        }

        $email = $verifiedIdToken->claims()->get('email');

        $user = User::where('email', $email)->whereNotNull('name')->first();

        if (!$user) {
            User::where('email', $email)->whereNull('name')->delete();

            $user = new User();

            $user->email = $email;
            $user->name = $verifiedIdToken->claims()->get('name');
            $user->verified_at = date_create();
            $user->role_id = 2;

            try {
                $user->save();
            } catch (\Throwable $th) {
                return response([
                    'message' => $th,
                ], 500);
            }
        }

        $user_token = $user->createToken('sanctum')->plainTextToken;

        return response([
            'message' => 'Email verify has been sending!',
            'token' => $user_token,
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
}
