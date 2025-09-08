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
        // =========================>
        // ## validate request
        // =========================>
        $validate = Validator::make($request->all(), [
            "email" => 'required|string|max:255|exists:users,email',
            "password" => 'required',
            "scope" => ['required', 'string', Rule::in(['admin', 'corporate', 'user'])]
        ]);

        if ($validate->fails()) {
            return response()->json([
                'message' => "Error: Validation Error!",
                'errors' => $validate->errors(),
            ], 422);
        }

        // =========================>
        // ## get user request
        // =========================>
        $user = User::select('users.*')
            ->where('email', $request->email);

        switch ($request->scope) {
            case 'admin': {
                $user = $user->where('role_id', 1)
                    ->first();
            } break;
            case 'corporate': {
                $user = $user->join('corporate_users', 'corporate_users.user_id', 'users.id')
                    ->first();
            } break;
            default: {
                $user = $user
                    ->where('role_id', 2)
                    ->first();
            } break;
        }

        if (!$user) {
            return response([
                'message' => 'User not found',
                'errors' => [
                    'email' => ['Data tidak ditemukan']
                ]
            ], 422);
        }

        // =========================>
        // ## check active user
        // =========================>
        // if (empty($user->verified_at)) {
        //     return response()->json([
        //         'message' => 'User inactive. please contact administrator',
        //         'errors' => ['email' => ['Akun di non-aktifkan, silahkan menghubungi administrator!']],
        //     ], 422);
        // }

        // =========================>
        // ## check password
        // =========================>
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Wrong username or password in our records',
                'errors' => ['password' => ['Password salah!']],
            ], 422);
        }

        // =========================>
        // ## create password
        // =========================>
        $user_token = $user->createToken('sanctum')->plainTextToken;

        return response([
            'message' => 'Success',
            'data' => [
                "token" => $user_token,
                "role" => $user->role,
                "scope" => $request->scope
            ]
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
        // Log request masuk (untuk debugging)
        Log::info('Registration attempt:', $request->all());
    
        // 1) Validasi input
        $validate = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:100',
            'password' => 'required|string|min:8|max:50|confirmed',
            'image' => 'nullable'
        ]);
    
        if ($validate->fails()) {
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
            $user->role_id = 2; // default role 'user'
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
            Log::info('User created successfully:', ['user_id' => $user->id]);
    
            // 3) Commit dulu transaksi (supaya data user fix)
            DB::commit();
    
            // 4) Buat kode verifikasi dengan sistem baru (di luar transaksi)
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
    
            // 6) Buat token Sanctum untuk auto-login setelah register
            $userToken = $user->createToken('sanctum')->plainTextToken;
    
            return response()->json([
                'message' => 'Success',
                'email_status' => $emailStatus,
                'data' => $user,
                'role' => $user->role,
                'user_token' => $userToken,
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
        $user = Auth::user();

        try {
            // Create new verification code using the new system
            $verificationCode = EmailVerificationCode::createForEmail($user->email);

            // Send email verification
            Mail::to($user->email)->send(new VerificationCodeMail($verificationCode->code));

            Log::info('Verification email resent successfully to:', ['email' => $user->email]);

            return response([
                'message' => "Email verify has been sent!",
                'data' => [
                    'email' => $user->email,
                    'expires_at' => $verificationCode->expires_at->toISOString()
                ]
            ]);

        } catch (\Throwable $th) {
            Log::error('Failed to resend verification email:', ['error' => $th->getMessage()]);
            
            return response([
                'message' => "Error: Failed to send verification email",
                'error' => config('app.debug') ? $th->getMessage() : null
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
        $validate = Validator::make($request->all(), [
            'token' => 'required'
        ]);

        // check validate
        if ($validate->fails()) {
            return response()->json([
                'message' => "Error: Unprocessable Entity!",
                'errors' => $validate->errors(),
            ], 422);
        }

        $user = User::where('id', Auth::user()->id)->firstOrFail();
        $token = $request->token;

        DB::beginTransaction();

        try {
            // Try new system first (6-digit plain code)
            if (strlen($token) === 6 && ctype_digit($token)) {
                $isValid = EmailVerificationCode::verifyCode($user->email, $token);
                
                if ($isValid) {
                    $user->verified_at = now();
                    $user->save();
                    
                    DB::commit();
                    return response([
                        'message' => 'Success',
                        'data' => $user
                    ]);
                }
            }

            // Fallback to old system (5-digit hashed)
            $userTokenVerify = UserTokenVerify::where('user_id', Auth::user()->id)
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
                
                DB::commit();
                return response([
                    'message' => 'Success',
                    'data' => $user
                ]);
            }

            // Token tidak valid
            DB::rollBack();
            return response()->json([
                'message' => "Token Invalid!",
                'errors' => [
                    'token' => ['Token tidak valid atau sudah kadaluarsa!']
                ]
            ], 422);

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Mail verification failed:', ['error' => $th->getMessage()]);
            return response()->json([
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
        // * Find Data
        $user = Auth::user();

        if(!$user->verified_at){
            return response(['unauthorized'], 401);
        }
        
        $user->role = $user->role;
        $user->cubes = $user->cubes;
        if ($user->corporate_user) {
            $user->corporate_user->role = $user->corporate_user->role;
            $user->corporate_user = $user->corporate_user->corporate;
        }

        // * Response
        return response([
            'message' => 'Success',
            'data' => [
                'profile' => $user
            ]
        ]);
    }

    public function account_unverified()
    {
        // * Find Data
        $user = Auth::user();
        
        $user->role = $user->role;
        $user->cubes = $user->cubes;
        if ($user->corporate_user) {
            $user->corporate_user->role = $user->corporate_user->role;
            $user->corporate_user = $user->corporate_user->corporate;
        }

        // * Response
        return response([
            'message' => 'Success',
            'data' => [
                'profile' => $user
            ]
        ]);
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
}
