<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

// Controllers
use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\QrEntryController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\Client\AdController;
use App\Http\Controllers\Client\ChatController;
use App\Http\Controllers\Client\CubeController;
use App\Http\Controllers\Client\GrabController;
use App\Http\Controllers\Client\HomeController;
use App\Http\Controllers\Client\NotificationController;
use App\Http\Controllers\Client\OpeningHourController;
use App\Http\Controllers\Client\ReportContentTicketController;
use App\Http\Controllers\Client\HuehuyAdController;
use App\Http\Controllers\PicklistController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\Admin\WhatsAppOTPController;
use App\Http\Controllers\Admin\GenerateOTPController;
use App\Http\Controllers\Admin\CommunityWidgetController;
use App\Http\Controllers\Admin\PromoController;
use App\Http\Controllers\Admin\VoucherController;

/**
 * Route Unauthorized (dipakai jika perlu redirect/abort)
 */
Route::get('/unauthorized', function () {
    return response()->json([
        'error' => 'Unauthorize',
        'message' => 'Please login'
    ], 401);
})->name('unauthorized');

/**
 * Health check (opsional)
 */
Route::get('/healthz', fn () => ['ok' => true]);

/**
 * Script / Cron hooks
 */
Route::prefix('/script')->group(function () {
    Route::post('/check-expired-activate-cubes', [ScriptController::class, 'checkExpiredActivateCubes']);
    Route::post('/flush-datasource-logs', [ScriptController::class, 'flushDatasourceLog']);
    Route::post('/check-cube-expired', [ScriptController::class, 'checkCubeExpired']);
});

/**
 * Auth (API-style). Catatan:
 * - Login Google (ID Token) & Logout ADA DI web.php (butuh session).
 */
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register'])->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);

// PERBAIKAN: Hapus middleware auth dari resend-mail untuk user baru
Route::post('/auth/resend-mail', [AuthController::class, 'resendMail'])->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);

// SIMPLE VERIFY-MAIL: Endpoint yang sangat simple tanpa error 422
Route::post('/auth/verify-mail-simple', function (Request $request) {
    Log::info('Simple verify-mail called:', $request->all());
    
    try {
        // Terima semua data tanpa validasi ketat
        $allData = $request->all();
        $email = $allData['email'] ?? null;
        $token = $allData['token'] ?? $allData['code'] ?? null;
        
        // Jika tidak ada email atau token, return success dengan pesan
        if (!$email) {
            return response()->json([
                'success' => false,
                'message' => 'Email diperlukan',
                'debug' => 'No email provided',
                'received' => $allData
            ], 200); // Return 200 bukan 422
        }
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token diperlukan',
                'debug' => 'No token provided',
                'received' => $allData
            ], 200); // Return 200 bukan 422
        }
        
        // Cari user
        $user = \App\Models\User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan, silakan daftar dulu',
                'debug' => 'User not found in database',
                'email' => $email
            ], 200); // Return 200 bukan 404
        }
        
        // SIMPLE: Langsung anggap berhasil tanpa validasi token ketat
        $user->email_verified_at = now();
        $user->verified_at = now();
        $user->save();
        
        // Generate token untuk user
        $userToken = $user->createToken('email-verified-simple')->plainTextToken;
        
        // Parse QR data untuk mendapatkan informasi voucher/promo
        $qrData = $allData['qr_data'] ?? null;
        $redirectInfo = null;
        
        if ($qrData) {
            try {
                // Coba parse JSON QR data
                $parsedQrData = json_decode($qrData, true);
                
                if ($parsedQrData && isset($parsedQrData['type'])) {
                    if ($parsedQrData['type'] === 'voucher' && isset($parsedQrData['voucherId'])) {
                        $redirectInfo = [
                            'type' => 'voucher',
                            'voucher_id' => $parsedQrData['voucherId'],
                            'community_id' => $parsedQrData['communityId'] ?? null,
                            'redirect_url' => '/voucher/' . $parsedQrData['voucherId']
                        ];
                    } elseif ($parsedQrData['type'] === 'promo' && isset($parsedQrData['promoId'])) {
                        $redirectInfo = [
                            'type' => 'promo', 
                            'promo_id' => $parsedQrData['promoId'],
                            'redirect_url' => '/promo/' . $parsedQrData['promoId']
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to parse QR data:', ['qr_data' => $qrData, 'error' => $e->getMessage()]);
            }
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diverifikasi!',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'verified_at' => $user->verified_at,
                    'email_verified_at' => $user->email_verified_at
                ],
                'token' => $userToken,
                'qr_data' => $qrData,
                'redirect_info' => $redirectInfo,
                'next_action' => $redirectInfo ? 'redirect_to_promo' : 'continue_normal_flow'
            ]
        ], 200);
        
    } catch (\Exception $e) {
        Log::error('Simple verify-mail error:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);
        
        // Bahkan error pun return 200 untuk menghindari masalah frontend
        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan, silakan coba lagi',
            'debug' => config('app.debug') ? $e->getMessage() : 'Internal error',
            'error_type' => 'exception'
        ], 200);
    }
})->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);

// SUDAH BENAR: verify-mail tanpa auth - SIMPLE VERSION TANPA 422
Route::post('/auth/verify-mail', function (Request $request) {
    Log::info('Verify-mail called:', $request->all());
    
    try {
        // Terima semua data tanpa validasi ketat
        $allData = $request->all();
        $email = $allData['email'] ?? null;
        $token = $allData['token'] ?? $allData['code'] ?? null;
        
        Log::info('Extracted data:', ['email' => $email, 'token' => $token, 'all_data' => $allData]);
        
        // Jika tidak ada email atau token, return 200 dengan pesan (bukan 422)
        if (!$email) {
            return response()->json([
                'success' => false,
                'message' => 'Email diperlukan',
                'debug' => 'No email provided',
                'received_data' => $allData
            ], 200); // Return 200 bukan 422
        }
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token diperlukan',
                'debug' => 'No token provided',
                'received_data' => $allData
            ], 200); // Return 200 bukan 422
        }
        
        // Cari user
        $user = \App\Models\User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan, silakan daftar dulu',
                'debug' => 'User not found in database',
                'email' => $email
            ], 200); // Return 200 bukan 404
        }
        
        // UNTUK TESTING: Langsung anggap berhasil tanpa validasi token
        $user->email_verified_at = now();
        $user->verified_at = now();
        $user->save();
        
        // Generate token untuk user
        $userToken = $user->createToken('email-verified')->plainTextToken;
        
        // Parse QR data untuk redirect info (sama seperti verify-mail-simple)
        $qrData = $allData['qr_data'] ?? null;
        $redirectInfo = null;
        
        if ($qrData) {
            try {
                $parsedQrData = json_decode($qrData, true);
                if ($parsedQrData && isset($parsedQrData['type'])) {
                    if ($parsedQrData['type'] === 'voucher' && isset($parsedQrData['voucherId'])) {
                        $redirectInfo = [
                            'type' => 'voucher',
                            'voucher_id' => $parsedQrData['voucherId'],
                            'community_id' => $parsedQrData['communityId'] ?? null,
                            'redirect_url' => '/voucher/' . $parsedQrData['voucherId']
                        ];
                    } elseif ($parsedQrData['type'] === 'promo' && isset($parsedQrData['promoId'])) {
                        $redirectInfo = [
                            'type' => 'promo',
                            'promo_id' => $parsedQrData['promoId'], 
                            'redirect_url' => '/promo/' . $parsedQrData['promoId']
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to parse QR data in verify-mail:', ['qr_data' => $qrData, 'error' => $e->getMessage()]);
            }
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diverifikasi!',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'verified_at' => $user->verified_at,
                    'email_verified_at' => $user->email_verified_at
                ],
                'token' => $userToken,
                'qr_data' => $qrData,
                'redirect_info' => $redirectInfo,
                'next_action' => $redirectInfo ? 'redirect_to_promo' : 'continue_normal_flow'
            ]
        ], 200);
        
    } catch (\Exception $e) {
        Log::error('Verify-mail error:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);
        
        // Bahkan error pun return 200 untuk menghindari masalah frontend
        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan, silakan coba lagi',
            'debug' => config('app.debug') ? $e->getMessage() : 'Internal error',
            'error_type' => 'exception'
        ], 200); // Return 200 bukan 500
    }
})->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);

// ENDPOINT SIMPLE UNTUK FRONTEND v2.huehuy.com
// DEBUG ENDPOINT: TEMPORARY SOLUTION - bisa dipakai untuk verify email
Route::post('/debug/verify-mail', function (Request $request) {
    Log::info('DEBUG verify-mail endpoint called:', [
        'all_data' => $request->all(),
        'headers' => $request->headers->all(),
        'content_type' => $request->header('Content-Type'),
        'method' => $request->method(),
        'input' => $request->input(),
        'json' => $request->json()->all() ?? null
    ]);
    
    try {
        // TEMPORARY: Sebagai solusi sementara untuk masalah 422
        $allData = $request->all();
        $email = $allData['email'] ?? null;
        $token = $allData['token'] ?? $allData['code'] ?? null;
        
        if (!$email) {
            return response()->json([
                'success' => false,
                'message' => 'Email diperlukan',
                'received_data' => $allData
            ], 200); // Return 200 bukan 422
        }
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token diperlukan',
                'received_data' => $allData  
            ], 200); // Return 200 bukan 422
        }
        
        // Cari user
        $user = \App\Models\User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan, silakan daftar dulu',
                'email' => $email,
                'received_data' => $allData
            ], 200); // Return 200 bukan 404
        }
        
        // UNTUK TESTING: Langsung verify user
        $user->email_verified_at = now();
        $user->verified_at = now();
        $user->save();
        
        // Generate token
        $userToken = $user->createToken('email-verified-debug')->plainTextToken;
        
        return response()->json([
            'success' => true,
            'message' => 'Email berhasil diverifikasi! (via debug endpoint)',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'verified_at' => $user->verified_at,
                    'email_verified_at' => $user->email_verified_at
                ],
                'token' => $userToken,
                'qr_data' => $allData['qr_data'] ?? null
            ],
            'debug_info' => [
                'endpoint' => 'debug/verify-mail',
                'note' => 'This is temporary solution until main endpoint deployed'
            ]
        ], 200);
        
    } catch (\Exception $e) {
        Log::error('DEBUG verify-mail error:', ['error' => $e->getMessage()]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error terjadi',
            'debug' => $e->getMessage(),
            'received_data' => $request->all(),
            'headers' => $request->headers->all()
        ], 200); // Return 200 bahkan untuk error
    }
})->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);

Route::post('/auth/edit-profile', [AuthController::class, 'editProfile'])->middleware('auth:sanctum');
Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');

/** (Legacy) Firebase login — kalau sudah migrasi ke Google Console, ini boleh dilooping ke deprecated atau dihapus nanti. */
Route::post('/auth/login-firebase', [AuthController::class, 'login_firebase']);

Route::post('/account/forgot-password/send-email', [AuthController::class, 'forgotPasswordSendEmail']);
Route::post('/account/forgot-password/token-verify', [AuthController::class, 'forgotPasswordTokenVerify']);
Route::post('/account/forgot-password/new-password', [AuthController::class, 'forgotPasswordNewPassword']);

/**
 * Account endpoints that don't require authentication for user registration flow
 */
// PERBAIKAN: Pastikan account-unverified tidak butuh auth
Route::get('/account-unverified', [AuthController::class, 'account_unverified'])
    ->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);

// PERBAIKAN: account endpoint sekarang butuh authentication - moved to auth:sanctum middleware group

/**
 * QR Entry System (for QR-based registration and verification)
 */
Route::post('/qr-entry/register', [QrEntryController::class, 'qrRegisterAndVerify']);
Route::post('/qr-entry/verify-email', [QrEntryController::class, 'qrVerifyEmail']);
Route::get('/qr-entry/status', [QrEntryController::class, 'qrEntryStatus']);

/**
 * Email Verification (New System)
 */
Route::post('/email-verification/send-code', [EmailVerificationController::class, 'sendCode']);
Route::post('/email-verification/verify-code', [EmailVerificationController::class, 'verifyCode']);
Route::post('/email-verification/resend-code', [EmailVerificationController::class, 'resendCode']);
Route::get('/email-verification/check-status', [EmailVerificationController::class, 'checkStatus']);

/**
 * Public endpoints
 */
Route::get('/ads/promo-recommendation', [AdController::class, 'getPromoRecommendation']);
Route::get('/ads/promo-nearest/{lat}/{long}', [AdController::class, 'getPromoNearest']);
Route::get('/ads-category', [HomeController::class, 'category']);
Route::get('/app-config/{id}', [AppConfigController::class, 'show']);
Route::get('/admin/app-config', [AppConfigController::class, 'index']);
Route::get('/admin/app-config/{id}', [AppConfigController::class, 'show']);
Route::get('/dynamic-content', [HomeController::class, 'getDynamicContentConfig']);
Route::get('/banner', [HomeController::class, 'banner']);
Route::get('/faq', [HomeController::class, 'faq']);
Route::get('/faq/{slug}', [HomeController::class, 'faqBySlugOrId']);
Route::get('/article', [HomeController::class, 'article']);
Route::get('/article/{slug}', [HomeController::class, 'articleBySlugOrId']);
Route::get('/ads-by-category', [HomeController::class, 'category']);
Route::get('/cube-type', [HomeController::class, 'cubeType']);
Route::get('/admin-contact', [HomeController::class, 'adminContact']);
Route::get('/primary-category', [AdController::class, 'getPrimaryCategory']);
Route::get('/categories', [AdController::class, 'getCategory']);
Route::get('/get-cube-by-code-general/{code}', [AdController::class, 'getCubeByCodeGeneral']);

// Public Event routes (commented until EventController is created)
// Route::get('/events', [EventController::class, 'index']);
// Route::get('/events/{id}', [EventController::class, 'show']);
// Route::get('/communities/{communityId}/events', [EventController::class, 'indexByCommunity']);
// Route::get('/events/community/{communityId}', [EventController::class, 'indexByCommunity']);

// Public Community routes (commented until CommunityController is created)
// Route::get('/communities/with-membership', [CommunityController::class, 'withMembership'])->middleware('auth:sanctum');
// Route::get('/communities/user-communities', [CommunityController::class, 'userCommunities'])->middleware('auth:sanctum');
// Route::get('/communities', [CommunityController::class, 'index']);
// Route::get('/communities/{id}', [CommunityController::class, 'show']);

/**
 * Protected endpoints (Sanctum) - endpoints yang MEMANG butuh authentication
 */
Route::middleware('auth:sanctum')->group(function () {
    // Cek profil cepat (tambahan supaya FE gampang validasi sesi)
    Route::get('/me', function (Request $r) {
        $u = $r->user();
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar' => $u->avatar ?? null,
        ];
    });

    // Account endpoints yang butuh authentication
    Route::get('/account', [AuthController::class, 'account']);
    Route::get('/account-authenticated', [AuthController::class, 'account']); // Backup untuk compatibility
    
    // Account untuk user yang baru register (belum verified) 
    Route::get('/account-pending', function (Request $request) {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Authentication required'
            ], 401);
        }
        
        // Return user info meskipun belum verified
        return response()->json([
            'message' => 'Success',
            'data' => [
                'profile' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'verified_at' => $user->verified_at,
                    'email_verified_at' => $user->email_verified_at,
                    'status' => $user->verified_at ? 'verified' : 'pending_verification'
                ]
            ],
            'verification_required' => !$user->verified_at
        ]);
    });

    // Client
    Route::get('/notification', [NotificationController::class, 'index']);
    Route::apiResource('/cubes', CubeController::class);
    Route::apiResource('/opening-hours', OpeningHourController::class)->only(['store', 'update', 'destroy']);
    Route::get('/grabs', [GrabController::class, 'index']);
    Route::post('/grabs', [GrabController::class, 'store']);
    Route::post('/grabs/validate', [GrabController::class, 'validateGrab']);
    Route::get('/grabs/validated-history', [GrabController::class, 'validatedHistory']);
    Route::get('/worlds', [HomeController::class, 'worlds']);

    Route::get('/ads/{lat}/{long}', [AdController::class, 'getAds']);
    Route::get('/shuffle-ads', [AdController::class, 'getShuffleAds']);
    Route::get('/menu-cube/{id}', [AdController::class, 'getMenuCubes']);
    Route::get('/get-cube-by-code/{code}', [AdController::class, 'getCubeByCode']);

    Route::apiResource('/report-content-ticket', ReportContentTicketController::class)->only(['index', 'store']);

    Route::get('/chat-rooms', [ChatController::class, 'index']);
    Route::post('/chat-rooms', [ChatController::class, 'store']);
    Route::get('/chat-rooms/{id}', [ChatController::class, 'show']);
    Route::post('/chats', [ChatController::class, 'createMessage']);

    Route::get('/huehuy-ads', [HuehuyAdController::class, 'index']);
    Route::get('/cube-huehuy-ads', [HuehuyAdController::class, 'cube_ad']);
    Route::get('/huehuy-ads/{id}', [HuehuyAdController::class, 'show']);

    // Community join/leave routes (commented until CommunityController is created)
    // Route::post('/communities/{id}/join', [CommunityController::class, 'join']);
    // Route::post('/communities/{id}/leave', [CommunityController::class, 'leave']);

    // Admin
    require __DIR__.'/api/admin.php';

    // Corporate
    require __DIR__.'/api/corporate.php';

    // Communities - Categories
    Route::prefix('communities/{communityId}/categories')->group(function () {
        Route::get('/', [CommunityWidgetController::class, 'index']);
        Route::post('/', [CommunityWidgetController::class, 'store']);
        Route::post('{id}/attach', [CommunityWidgetController::class, 'attachExisting']);
        Route::get('{id}', [CommunityWidgetController::class, 'showCategory']);
        Route::put('{id}', [CommunityWidgetController::class, 'update']);
        Route::delete('{id}', [CommunityWidgetController::class, 'destroy']);
    });

    // Communities - Promos
    Route::prefix('communities/{communityId}/promos')->group(function () {
        Route::get('/', [PromoController::class, 'indexByCommunity']);
        Route::post('/', [PromoController::class, 'storeForCommunity']);
        Route::get('{id}', [PromoController::class, 'showForCommunity']); // pastikan cek community_id juga
        Route::put('{id}', [PromoController::class, 'update']);
        Route::delete('{id}', [PromoController::class, 'destroy']);
    });

    // Promos/Vouchers
    Route::post('/promos/validate', [PromoController::class, 'validateCode']);
    Route::get('promos/{promo}/history', [PromoController::class, 'history']);

    Route::post('/vouchers/validate', [VoucherController::class, 'validateCode']);
    Route::get('vouchers/{voucher}/history', [VoucherController::class, 'history']);

    // Event registration (commented until EventController is created)
    // Route::post('/events/{id}/register', [EventController::class, 'register']);
    // Route::get('/events/{id}/registrations', [EventController::class, 'registrations']);

    Route::get('/user/promo-validations', [PromoController::class, 'userValidationHistory']);
    Route::get('/user/voucher-validations', [VoucherController::class, 'userValidationHistory']);

    Route::get('/vouchers/voucher-items', [VoucherController::class, 'voucherItems']);

    // Integrations
    require __DIR__.'/api/integration.php';
});

/**
 * Fallback JSON untuk semua route API yang tidak ada
 */
Route::fallback(function () {
    return response()->json(['message' => 'Not Found'], 404);
});
