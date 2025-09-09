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
 * Health check
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
 * =======================================================
 * AUTHENTICATION ROUTES (Public - No Auth Required)
 * =======================================================
 */
Route::prefix('auth')->group(function () {
    // Basic Auth
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    
    // Email Verification
    Route::post('/resend-mail', [AuthController::class, 'resendMail']);
    Route::post('/verify-mail', [AuthController::class, 'mailVerify']);
    
    // Firebase Auth (Legacy)
    Route::post('/login-firebase', [AuthController::class, 'login_firebase']);
    
    // Protected Auth Routes (Require Authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/edit-profile', [AuthController::class, 'editProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

/**
 * =======================================================
 * ACCOUNT & PASSWORD RECOVERY (Public)
 * =======================================================
 */
Route::prefix('account')->group(function () {
    // Password Recovery
    Route::post('/forgot-password/send-email', [AuthController::class, 'forgotPasswordSendEmail']);
    Route::post('/forgot-password/token-verify', [AuthController::class, 'forgotPasswordTokenVerify']);
    Route::post('/forgot-password/new-password', [AuthController::class, 'forgotPasswordNewPassword']);
});

// Account endpoints for unverified users (no auth required)
Route::get('/account-unverified', [AuthController::class, 'account_unverified']);

/**
 * =======================================================
 * EMAIL VERIFICATION SYSTEM (Public)
 * =======================================================
 */
Route::prefix('email-verification')->group(function () {
    Route::post('/send-code', [EmailVerificationController::class, 'sendCode']);
    Route::post('/verify-code', [EmailVerificationController::class, 'verifyCode']);
    Route::post('/resend-code', [EmailVerificationController::class, 'resendCode']);
    Route::get('/status', [EmailVerificationController::class, 'checkStatus']);
});

/**
 * =======================================================
 * QR ENTRY SYSTEM (Public - For QR-based registration)
 * =======================================================
 */
Route::prefix('qr-entry')->group(function () {
    Route::post('/register', [QrEntryController::class, 'qrRegisterAndVerify']);
    Route::post('/verify-email', [QrEntryController::class, 'qrVerifyEmail']);
    Route::get('/status', [QrEntryController::class, 'qrEntryStatus']);
});

/**
 * =======================================================
 * PUBLIC CONTENT ROUTES (No Authentication Required)
 * =======================================================
 */
Route::prefix('ads')->group(function () {
    Route::get('/promo-recommendation', [AdController::class, 'getPromoRecommendation']);
    Route::get('/promo-nearest/{lat}/{long}', [AdController::class, 'getPromoNearest']);
});

// App Configuration
Route::get('/app-config/{id}', [AppConfigController::class, 'show']);
Route::get('/admin/app-config', [AppConfigController::class, 'index']);
Route::get('/admin/app-config/{id}', [AppConfigController::class, 'show']);

// Content & Categories
Route::get('/ads-category', [HomeController::class, 'category']);
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

// Future Public Routes (Commented - Waiting for Controllers)
// Route::get('/events', [EventController::class, 'index']);
// Route::get('/events/{id}', [EventController::class, 'show']);
// Route::get('/communities/{communityId}/events', [EventController::class, 'indexByCommunity']);
// Route::get('/communities', [CommunityController::class, 'index']);
// Route::get('/communities/{id}', [CommunityController::class, 'show']);

/**
 * =======================================================
 * PROTECTED ROUTES (Authentication Required)
 * =======================================================
 */
Route::middleware('auth:sanctum')->group(function () {
    
    /**
     * User Profile & Account Management
     */
    Route::get('/me', function (Request $r) {
        $u = $r->user();
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar' => $u->avatar ?? null,
        ];
    });
    
    Route::get('/account', [AuthController::class, 'account']);
    Route::get('/account-authenticated', [AuthController::class, 'account']); // Backup untuk compatibility

    /**
     * Client Features
     */
    // Notifications
    Route::get('/notification', [NotificationController::class, 'index']);
    
    // Cubes Management
    Route::apiResource('/cubes', CubeController::class);
    Route::get('/get-cube-by-code/{code}', [AdController::class, 'getCubeByCode']);
    Route::get('/menu-cube/{id}', [AdController::class, 'getMenuCubes']);
    
    // Opening Hours
    Route::apiResource('/opening-hours', OpeningHourController::class)->only(['store', 'update', 'destroy']);
    
    // Grabs (Rewards/Points System)
    Route::prefix('grabs')->group(function () {
        Route::get('/', [GrabController::class, 'index']);
        Route::post('/', [GrabController::class, 'store']);
        Route::post('/validate', [GrabController::class, 'validateGrab']);
        Route::get('/validated-history', [GrabController::class, 'validatedHistory']);
    });
    
    // Location-based Features
    Route::get('/worlds', [HomeController::class, 'worlds']);
    Route::get('/ads/{lat}/{long}', [AdController::class, 'getAds']);
    Route::get('/shuffle-ads', [AdController::class, 'getShuffleAds']);
    
    // Reports
    Route::apiResource('/report-content-ticket', ReportContentTicketController::class)->only(['index', 'store']);
    
    /**
     * Chat System
     */
    Route::prefix('chat')->group(function () {
        Route::get('/rooms', [ChatController::class, 'index']);
        Route::post('/rooms', [ChatController::class, 'store']);
        Route::get('/rooms/{id}', [ChatController::class, 'show']);
        Route::post('/messages', [ChatController::class, 'createMessage']);
    });
    
    /**
     * Huehuy Ads System
     */
    Route::prefix('huehuy-ads')->group(function () {
        Route::get('/', [HuehuyAdController::class, 'index']);
        Route::get('/cube-ads', [HuehuyAdController::class, 'cube_ad']);
        Route::get('/{id}', [HuehuyAdController::class, 'show']);
    });
    
    /**
     * Community Management
     */
    Route::prefix('communities/{communityId}')->group(function () {
        // Community Categories
        Route::prefix('categories')->group(function () {
            Route::get('/', [CommunityWidgetController::class, 'index']);
            Route::post('/', [CommunityWidgetController::class, 'store']);
            Route::post('/{id}/attach', [CommunityWidgetController::class, 'attachExisting']);
            Route::get('/{id}', [CommunityWidgetController::class, 'showCategory']);
            Route::put('/{id}', [CommunityWidgetController::class, 'update']);
            Route::delete('/{id}', [CommunityWidgetController::class, 'destroy']);
        });
        
        // Community Promos
        Route::prefix('promos')->group(function () {
            Route::get('/', [PromoController::class, 'indexByCommunity']);
            Route::post('/', [PromoController::class, 'storeForCommunity']);
            Route::get('/{id}', [PromoController::class, 'showForCommunity']);
            Route::put('/{id}', [PromoController::class, 'update']);
            Route::delete('/{id}', [PromoController::class, 'destroy']);
        });
    });
    
    // Future Community Routes (Commented - Waiting for Controllers)
    // Route::get('/communities/with-membership', [CommunityController::class, 'withMembership']);
    // Route::get('/communities/user-communities', [CommunityController::class, 'userCommunities']);
    // Route::post('/communities/{id}/join', [CommunityController::class, 'join']);
    // Route::post('/communities/{id}/leave', [CommunityController::class, 'leave']);
    
    /**
     * Promos & Vouchers System
     */
    Route::prefix('promos')->group(function () {
        Route::post('/validate', [PromoController::class, 'validateCode']);
        Route::get('/{promo}/history', [PromoController::class, 'history']);
    });
    
    Route::prefix('vouchers')->group(function () {
        Route::post('/validate', [VoucherController::class, 'validateCode']);
        Route::get('/{voucher}/history', [VoucherController::class, 'history']);
        Route::get('/voucher-items', [VoucherController::class, 'voucherItems']);
    });
    
    /**
     * User Activity History
     */
    Route::prefix('user')->group(function () {
        Route::get('/promo-validations', [PromoController::class, 'userValidationHistory']);
        Route::get('/voucher-validations', [VoucherController::class, 'userValidationHistory']);
    });
    
    // Future Event Routes (Commented - Waiting for Controllers)
    // Route::post('/events/{id}/register', [EventController::class, 'register']);
    // Route::get('/events/{id}/registrations', [EventController::class, 'registrations']);
    
    /**
     * Admin Routes
     */
    require __DIR__.'/api/admin.php';
    
    /**
     * Corporate Routes
     */
    require __DIR__.'/api/corporate.php';
    
    /**
     * Integration Routes
     */
    require __DIR__.'/api/integration.php';
});

/**
 * =======================================================
 * FALLBACK ROUTE
 * =======================================================
 */
Route::fallback(function () {
    return response()->json(['message' => 'Not Found'], 404);
});
