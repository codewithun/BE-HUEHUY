<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

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
Route::post('/auth/resend-mail', [AuthController::class, 'resendMail'])->middleware('auth:sanctum')->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);
Route::post('/auth/verify-mail', [AuthController::class, 'mailVerify'])->middleware('auth:sanctum')->withoutMiddleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]);
Route::post('/auth/edit-profile', [AuthController::class, 'editProfile'])->middleware('auth:sanctum');
Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');

/** (Legacy) Firebase login — kalau sudah migrasi ke Google Console, ini boleh dilooping ke deprecated atau dihapus nanti. */
Route::post('/auth/login-firebase', [AuthController::class, 'login_firebase']);

Route::post('/account/forgot-password/send-email', [AuthController::class, 'forgotPasswordSendEmail']);
Route::post('/account/forgot-password/token-verify', [AuthController::class, 'forgotPasswordTokenVerify']);
Route::post('/account/forgot-password/new-password', [AuthController::class, 'forgotPasswordNewPassword']);

/**
 * Account endpoints that don't require authentication
 */
Route::get('/account-unverified', [AuthController::class, 'account_unverified']);

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
 * Protected endpoints (Sanctum)
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

    // Account
    Route::get('/account', [AuthController::class, 'account']);

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
