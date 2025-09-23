<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// Controllers (ADMIN namespace dipakai untuk CommunityController kamu)
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
use App\Http\Controllers\Admin\CommunityWidgetController;
use App\Http\Controllers\Admin\PromoController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\CommunityController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\VoucherItemController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\PromoItemController;

/**
 * Unauthorized helper
 */
Route::get('/unauthorized', fn () => response()->json([
    'error' => 'Unauthorize',
    'message' => 'Please login'
], 401))->name('unauthorized');

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
 * =======================
 * AUTH (Public)
 * =======================
 */
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    Route::post('/resend-mail', [AuthController::class, 'resendMail']);
    Route::post('/verify-mail', [AuthController::class, 'mailVerify']);

    // Legacy Firebase
    Route::post('/login-firebase', [AuthController::class, 'login_firebase']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/edit-profile', [AuthController::class, 'editProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

/**
 * =======================
 * ACCOUNT & RECOVERY
 * =======================
 */
Route::prefix('account')->group(function () {
    Route::post('/forgot-password/send-email', [AuthController::class, 'forgotPasswordSendEmail']);
    Route::post('/forgot-password/token-verify', [AuthController::class, 'forgotPasswordTokenVerify']);
    Route::post('/forgot-password/new-password', [AuthController::class, 'forgotPasswordNewPassword']);
});

Route::get('/account-unverified', [AuthController::class, 'account_unverified']);

/**
 * =======================
 * EMAIL VERIFICATION
 * =======================
 */
Route::prefix('email-verification')->group(function () {
    Route::post('/send-code', [EmailVerificationController::class, 'sendCode']);
    Route::post('/verify-code', [EmailVerificationController::class, 'verifyCode']);
    Route::post('/resend-code', [EmailVerificationController::class, 'resendCode']);
    Route::get('/status', [EmailVerificationController::class, 'checkStatus']);
});

/**
 * =======================
 * QR ENTRY (Public)
 * =======================
 */
Route::prefix('qr-entry')->group(function () {
    Route::post('/register', [QrEntryController::class, 'qrRegisterAndVerify']);
    Route::post('/verify-email', [QrEntryController::class, 'qrVerifyEmail']);
    Route::get('/status', [QrEntryController::class, 'qrEntryStatus']);
});

/**
 * =======================
 * PUBLIC CONTENT
 * =======================
 */
Route::prefix('ads')->group(function () {
    Route::get('/promo-recommendation', [AdController::class, 'getPromoRecommendation']);
    Route::get('/promo-nearest/{lat}/{long}', [AdController::class, 'getPromoNearest']);
});

Route::get('/promos/{id}/public', [PromoController::class, 'showPublic'])->whereNumber('id');
Route::get('/vouchers/{id}/public', [VoucherController::class, 'showPublic'])->whereNumber('id');

/**
 * =======================
 * ADMIN & CONFIG (Public GETs)
 * =======================
 */
Route::get('/app-config/{id}', [AppConfigController::class, 'show'])->whereNumber('id');
Route::get('/admin/app-config', [AppConfigController::class, 'index']);
Route::get('/admin/app-config/{id}', [AppConfigController::class, 'show'])->whereNumber('id');

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

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{id}', [EventController::class, 'show'])->whereNumber('id');

/**
 * =======================
 * PUBLIC Communities (list & detail)
 * (PERHATIKAN: statis/dengan nama didefinisikan SEBELUM dinamis)
 * =======================
 */
Route::get('/communities', [CommunityController::class, 'index']);
Route::get('/communities/{id}', [CommunityController::class, 'show'])->whereNumber('id');

/**
 * +++ NEW: PUBLIC members fallback +++
 */
Route::get('/communities/{id}/members', [CommunityController::class, 'publicMembers'])->whereNumber('id');

/**
 * =======================
 * PROTECTED (auth:sanctum)
 * =======================
 */
Route::middleware('auth:sanctum')->group(function () {

    // === Account ===
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
    Route::get('/account-authenticated', [AuthController::class, 'account']);

    // === Notifications ===
    Route::get('/notification', [NotificationController::class, 'index']);
    Route::post('/notification/{id}/read', [NotificationController::class, 'markAsRead'])->whereNumber('id');
    Route::post('/notification/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notification/{id}', [NotificationController::class, 'destroy'])->whereNumber('id');
    Route::delete('/notification', [NotificationController::class, 'destroyAll'] ?? fn() => abort(405));

    // === Cubes ===
    Route::apiResource('/cubes', CubeController::class);
    Route::get('/get-cube-by-code/{code}', [AdController::class, 'getCubeByCode']);
    Route::get('/menu-cube/{id}', [AdController::class, 'getMenuCubes'])->whereNumber('id');

    // === Opening Hours ===
    Route::apiResource('/opening-hours', OpeningHourController::class)->only(['store', 'update', 'destroy']);

    // === Grabs ===
    Route::prefix('grabs')->group(function () {
        Route::get('/', [GrabController::class, 'index']);
        Route::post('/', [GrabController::class, 'store']);
        Route::post('/validate', [GrabController::class, 'validateGrab']);
        Route::get('/validated-history', [GrabController::class, 'validatedHistory']);
    });

    // === Location ===
    Route::get('/worlds', [HomeController::class, 'worlds']);
    Route::get('/ads/{lat}/{long}', [AdController::class, 'getAds']);
    Route::get('/shuffle-ads', [AdController::class, 'getShuffleAds']);

    // === Reports ===
    Route::apiResource('/report-content-ticket', ReportContentTicketController::class)->only(['index', 'store']);

    // === Chat ===
    Route::prefix('chat')->group(function () {
        Route::get('/rooms', [ChatController::class, 'index']);
        Route::post('/rooms', [ChatController::class, 'store']);
        Route::get('/rooms/{id}', [ChatController::class, 'show'])->whereNumber('id');
        Route::post('/messages', [ChatController::class, 'createMessage']);
    });

    // === Huehuy Ads ===
    Route::prefix('huehuy-ads')->group(function () {
        Route::get('/', [HuehuyAdController::class, 'index']);
        Route::get('/cube-ads', [HuehuyAdController::class, 'cube_ad']);
        Route::get('/{id}', [HuehuyAdController::class, 'show'])->whereNumber('id');
    });

    /**
     * COMMUNITY MEMBERSHIP ENDPOINTS (statis dulu!)
     */
    Route::get('/communities/with-membership', [CommunityController::class, 'withMembership']);
    Route::get('/communities/user-communities', [CommunityController::class, 'userCommunities']);
    Route::post('/communities/{id}/join', [CommunityController::class, 'join'])->whereNumber('id');
    Route::post('/communities/{id}/leave', [CommunityController::class, 'leave'])->whereNumber('id');

    /**
     * Community nested resources (gunakan constraint numeric)
     */
    Route::prefix('communities/{communityId}')
        ->whereNumber('communityId')
        ->group(function () {

        // === Events per community (FE: /communities/{id}/events)
        Route::get('/events', [EventController::class, 'indexByCommunity']);

        // === Alias promo-categories -> pakai controller categories index
        Route::get('/promo-categories', [CommunityWidgetController::class, 'index']);

        // Categories
        Route::prefix('categories')->group(function () {
            Route::get('/', [CommunityWidgetController::class, 'index']);
            Route::post('/', [CommunityWidgetController::class, 'store']);
            Route::post('/{id}/attach', [CommunityWidgetController::class, 'attachExisting'])->whereNumber('id');
            Route::get('/{id}', [CommunityWidgetController::class, 'showCategory'])->whereNumber('id');
            Route::put('/{id}', [CommunityWidgetController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [CommunityWidgetController::class, 'destroy'])->whereNumber('id');
        });

        // Promos
        Route::prefix('promos')->group(function () {
            Route::get('/', [PromoController::class, 'indexByCommunity']);
            Route::post('/', [PromoController::class, 'storeForCommunity']);
            Route::get('/{id}', [PromoController::class, 'showForCommunity'])->whereNumber('id');
            Route::put('/{id}', [PromoController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [PromoController::class, 'destroy'])->whereNumber('id');
        });

        // Vouchers
        Route::prefix('vouchers')->group(function () {
            Route::get('/', [VoucherController::class, 'indexByCommunity']);
            Route::post('/', [VoucherController::class, 'storeForCommunity']);
            Route::get('/{id}', [VoucherController::class, 'showForCommunity'])->whereNumber('id');
            Route::put('/{id}', [VoucherController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [VoucherController::class, 'destroy'])->whereNumber('id');
        });
    });

    // Promos & Vouchers history
    Route::prefix('promos')->group(function () {
        Route::post('/validate', [PromoController::class, 'validateCode']);
        Route::post('/validate-code', [PromoController::class, 'validateCode']);
        Route::get('/{promo}/history', [PromoController::class, 'history'])->whereNumber('promo');

        Route::post('/{promo}/items', [PromoItemController::class, 'storeForPromo'])
            ->whereNumber('promo');
    });

    Route::prefix('vouchers')->group(function () {
        Route::post('/validate', [VoucherController::class, 'validateCode']);
        Route::get('/{voucher}/history', [VoucherController::class, 'history'])->whereNumber('voucher');
        Route::get('/voucher-items', [VoucherController::class, 'voucherItems']);
        
        Route::get('/lookup-by-code/{code}', [VoucherController::class, 'lookupByCode']);
        Route::get('/user-voucher-items/{userId}', [VoucherController::class, 'getUserVoucherItems'])->whereNumber('userId');
    });

    // === Voucher Items (claim) ===
    Route::post('/vouchers/{voucher}/claim', [VoucherItemController::class, 'claim'])->whereNumber('voucher');
    Route::post('/admin/voucher-items/{id}/redeem', [VoucherItemController::class, 'redeem'])->whereNumber('id');

    // User Activity
    Route::prefix('user')->group(function () {
        Route::get('/promo-validations', [PromoController::class, 'userValidationHistory']);
        Route::get('/voucher-validations', [VoucherController::class, 'userValidationHistory']);
    });

    /**
     * =======================
     * ADMIN BUNDLE (auth)
     * =======================
     */
    Route::prefix('admin')->group(function () {
        // === USERS (untuk MultiSelectDropdown admin contacts) ===
        // Contoh: /api/admin/users?only_admin_contacts=true&paginate=all
        // atau    /api/admin/users?roles[]=admin&roles[]=manager_tenant&paginate=all
        Route::get('/users', [AdminUserController::class, 'index']);

        // === COMMUNITIES CRUD (Admin) ===
        Route::get('/communities', [CommunityController::class, 'index']);
        Route::post('/communities', [CommunityController::class, 'store']);
        Route::put('/communities/{id}', [CommunityController::class, 'update'])->whereNumber('id');
        Route::delete('/communities/{id}', [CommunityController::class, 'destroy'])->whereNumber('id');

         // +++ NEW: ADMIN members +++
        Route::get('/communities/{id}/members', [CommunityController::class, 'adminMembers'])->whereNumber('id');
    });

    // Admin/Corporate/Integration bundle
    require __DIR__.'/api/admin.php';
    require __DIR__.'/api/corporate.php';
    require __DIR__.'/api/integration.php';
});

/**
 * Fallback 404
 */
Route::fallback(fn () => response()->json(['message' => 'Not Found'], 404));