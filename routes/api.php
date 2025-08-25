<?php

    use App\Http\Controllers\AppConfigController;
    use Illuminate\Support\Facades\Route;

    // =========================>
    // ## Auth controller
    // =========================>
    use App\Http\Controllers\AuthController;
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

    /**
     * * Route Unauthorized Exception
     */
    Route::get('/unauthorized', function () {
        return response()->json([
            'error' => 'Unauthorize',
            'message' => 'Please login'
        ], 401);
    })->name('unauthorized');

    /**
     * * Route for run a cron job script
     */
    Route::prefix('/script')->group(function () {
        Route::post('/check-expired-activate-cubes', [ScriptController::class, 'checkExpiredActivateCubes']);
        Route::post('/flush-datasource-logs', [ScriptController::class, 'flushDatasourceLog']);
        Route::post('/check-cube-expired', [ScriptController::class, 'checkCubeExpired']);
    });


    // =========================>
    // * Auth
    // =========================>
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/resend-mail', [AuthController::class, 'resendMail'])->middleware('auth:sanctum');
    Route::post('/auth/verify-mail', [AuthController::class, 'mailVerify'])->middleware('auth:sanctum');
    Route::post('/auth/edit-profile', [AuthController::class, 'editProfile'])->middleware('auth:sanctum');
    Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
    Route::post('/auth/login-firebase', [AuthController::class, 'login_firebase']);

    Route::post('/account/forgot-password/send-email', [AuthController::class, 'forgotPasswordSendEmail']);
    Route::post('/account/forgot-password/token-verify', [AuthController::class, 'forgotPasswordTokenVerify']);
    Route::post('/account/forgot-password/new-password', [AuthController::class, 'forgotPasswordNewPassword']);

    Route::middleware('auth:sanctum')->group(function() {
        Route::get('/account', [AuthController::class, 'account']);
        Route::get('/account-unverified', [AuthController::class, 'account_unverified']);
        
        Route::get('/app-config/{id}', [AppConfigController::class, 'show']);

        /**
         * * Client
         */
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
        Route::get('/ads/promo-recommendation', [AdController::class, 'getPromoRecommendation']);
        Route::get('/ads/promo-nearest/{lat}/{long}', [AdController::class, 'getPromoNearest']);
        Route::get('/menu-cube/{id}', [AdController::class, 'getMenuCubes']);
        Route::get('/get-cube-by-code/{code}', [AdController::class, 'getCubeByCode']);

        Route::get('/ads-category', [HomeController::class, 'category']);

        Route::apiResource('/report-content-ticket', ReportContentTicketController::class)->only(['index', 'store']);

        Route::get('/chat-rooms', [ChatController::class, 'index']);
        Route::post('/chat-rooms', [ChatController::class, 'store']);
        Route::get('/chat-rooms/{id}', [ChatController::class, 'show']);
        Route::post('/chats', [ChatController::class, 'createMessage']);

        Route::get('/huehuy-ads', [HuehuyAdController::class, 'index']);
        Route::get('/cube-huehuy-ads', [HuehuyAdController::class, 'cube_ad']);
        Route::get('/huehuy-ads/{id}', [HuehuyAdController::class, 'show']);


        // =========================>
        // * Admin
        // =========================>
        require('api/admin.php');

        // =========================>
        // * Corporate
        // =========================>
        require('api/corporate.php');

        // CRUD kategori komunitas (nested resource)
        Route::prefix('communities/{communityId}/categories')->group(function () {
            Route::get('/', [CommunityWidgetController::class, 'index']);
            Route::post('/', [CommunityWidgetController::class, 'store']);

            // tambahkan route untuk attach existing promo/voucher ke kategori
            Route::post('{id}/attach', [CommunityWidgetController::class, 'attachExisting']);

            Route::get('{id}', [CommunityWidgetController::class, 'showCategory']);
            Route::put('{id}', [CommunityWidgetController::class, 'update']);
            Route::delete('{id}', [CommunityWidgetController::class, 'destroy']);
        });

        Route::prefix('communities/{communityId}/promos')->group(function () {
            Route::get('/', [PromoController::class, 'indexByCommunity']);
            Route::post('/', [PromoController::class, 'storeForCommunity']);
            // gunakan showForCommunity agar query mencocokkan both community_id dan promo id
            Route::get('{id}', [PromoController::class, 'showForCommunity']);
            Route::put('{id}', [PromoController::class, 'update']);
            Route::delete('{id}', [PromoController::class, 'destroy']);
        });

        // HAPUS duplikat berikut (jika masih ada), karena sudah ditangani di atas:
        // Route::get('/communities/{communityId}/promos/{promoId}', [PromoController::class, 'showForCommunity']);
        // Route::get('/communities/{communityId}/promos', [PromoController::class, 'indexByCommunity']);
    });

    // * Client

    // * Picklist Options
    Route::prefix('/options')->group(function () {
        Route::get('/world', [PicklistController::class, 'world']);
        Route::get('/cube-type', [PicklistController::class, 'cubeType']);
        Route::get('/ad-category', [PicklistController::class, 'adCategory']);
    });

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



    // * Datasource Integration
    require('api/integration.php');

    // =========================>
        // * WhatsAppOTP
        // =========================>

